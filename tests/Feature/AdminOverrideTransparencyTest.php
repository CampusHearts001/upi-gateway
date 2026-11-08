<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Models\WebhookOutbox;
use App\Services\OrderService;
use App\Services\Verifiers\VerificationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdminOverrideTransparencyTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    private User $admin;

    private PaymentAccount $liveAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::create([
            'name' => 'Merchant Alpha',
            'business_name' => 'Alpha Shop',
            'email' => 'alpha@example.com',
            'password' => 'password123',
            'webhook_url' => 'https://alpha.example.com/webhook',
        ]);
        $this->merchant->regenerateApiCredentials();
        $this->merchant->regenerateWebhookSecret();

        $this->admin = User::create([
            'name' => 'Platform Admin',
            'email' => 'admin@platform.example.com',
            'password' => 'password123',
            'role' => 'admin',
        ]);

        $this->liveAccount = $this->merchant->paymentAccounts()->create([
            'provider' => 'paytm_pg',
            'label' => 'Primary PG',
            'payee_name' => 'Alpha Shop',
            'credentials' => [
                'environment' => 'production',
                'mid' => 'MID123456',
                'merchant_key' => '1234567890abcdef',
            ],
            'is_default' => true,
        ]);
    }

    private function apiHeaders(): array
    {
        return [
            'X-Api-Key' => $this->merchant->api_key,
            'X-Api-Secret' => $this->merchant->api_secret,
        ];
    }

    private function createLiveOrder(string $clientId = 'CLIENT-101', float $amount = 250.00): Order
    {
        return app(OrderService::class)->create($this->merchant, [
            'amount' => $amount,
            'client_order_id' => $clientId,
            'payment_account_id' => $this->liveAccount->id,
            'customer_name' => 'Alice Customer',
        ], Order::LIVE);
    }

    public function test_admin_override_visible_in_merchant_api_response(): void
    {
        $order = $this->createLiveOrder('API-OVR-1');

        app(OrderService::class)->adminOverridePaid(
            $order,
            $this->admin,
            'BANKREF-OVR-999',
            'Bank credit verified on offline statement.',
            '127.0.0.1',
            '250.00'
        );

        $response = $this->getJson("/api/v1/orders/{$order->order_no}", $this->apiHeaders());

        $response->assertOk();
        $response->assertJsonPath('data.status', 'paid');
        $response->assertJsonPath('data.verified_by', 'admin_override');
        $response->assertJsonPath('data.utr', 'BANKREF-OVR-999');
    }

    public function test_admin_override_visible_in_webhook_payload(): void
    {
        $order = $this->createLiveOrder('WH-OVR-1');

        app(OrderService::class)->adminOverridePaid(
            $order,
            $this->admin,
            'BANKREF-WH-777',
            'Bank credit verified on offline statement.',
            '127.0.0.1',
            '250.00'
        );

        $outbox = WebhookOutbox::where('order_id', $order->id)->where('event', 'order.paid')->firstOrFail();

        $this->assertSame('order.paid', $outbox->payload['event']);
        $this->assertSame('paid', $outbox->payload['data']['status']);
        $this->assertSame('admin_override', $outbox->payload['data']['verified_by']);
        $this->assertSame('BANKREF-WH-777', $outbox->payload['data']['utr']);
    }

    public function test_admin_override_dashboard_displays_timeline_label_verification_and_banner(): void
    {
        $order = $this->createLiveOrder('DASH-OVR-1');

        app(OrderService::class)->adminOverridePaid(
            $order,
            $this->admin,
            'BANKREF-DASH-123',
            'Bank credit verified on offline statement.',
            '127.0.0.1',
            '250.00'
        );

        $response = $this->actingAs($this->merchant)->get(route('orders.show', $order));

        $response->assertOk();

        // 1. Explicit timeline label
        $response->assertSee('Payment received (admin override)');
        $response->assertDontSee('Payment received (auto verified)');

        // 2. Verification shows Administrator override instead of provider label
        $response->assertSee('Administrator override');

        // 3. Clear merchant-facing informational banner with factual wording
        $response->assertSee('Administrator Override Notice');
        $response->assertSee(
            'This payment was marked as paid through a platform administrator override. Please review your records before fulfilling the order.'
        );
    }

    public function test_admin_reference_is_not_presented_as_provider_transaction_id(): void
    {
        $order = $this->createLiveOrder('REF-OVR-1');
        $reference = 'ADMIN-SPECIFIC-REF-456';

        app(OrderService::class)->adminOverridePaid(
            $order,
            $this->admin,
            $reference,
            'Manual verification reference confirmed by operator.',
            '127.0.0.1',
            '250.00'
        );

        $response = $this->actingAs($this->merchant)->get(route('orders.show', $order));

        $response->assertOk();
        // Admin reference is clearly labeled
        $response->assertSee('Admin reference');
        $response->assertSee($reference);

        // Provider txn ID is NOT rendered with the admin reference
        $content = $response->getContent();
        $this->assertStringNotContainsString(
            '<dt class="text-xs font-medium text-slate-500">Provider txn ID</dt>'."\n".'<dd class="mt-1 text-sm text-slate-900 break-all font-mono">'.$reference.'</dd>',
            preg_replace('/\s+/', ' ', $content)
        );
    }

    public function test_normal_provider_payment_remains_unchanged(): void
    {
        $order = $this->createLiveOrder('PROV-1');

        $evidence = VerificationResult::fromPaytmPg(
            $order,
            250.00,
            '123456789012',
            'PAYTM_TXN_9999'
        );

        $this->markProviderPaid($order, $evidence);

        $this->assertSame('provider', $order->fresh()->verified_by);

        // API check
        $apiResponse = $this->getJson("/api/v1/orders/{$order->order_no}", $this->apiHeaders());
        $apiResponse->assertOk();
        $apiResponse->assertJsonPath('data.status', 'paid');
        $apiResponse->assertJsonPath('data.verified_by', 'provider');

        // Dashboard check
        $dashResponse = $this->actingAs($this->merchant)->get(route('orders.show', $order));
        $dashResponse->assertOk();
        $dashResponse->assertSee('Payment received (auto verified)');
        $dashResponse->assertDontSee('Administrator Override Notice');
        $dashResponse->assertDontSee('Payment received (admin override)');
        $dashResponse->assertSee('PAYTM_TXN_9999');
    }

    public function test_utr_review_payment_remains_unchanged(): void
    {
        $manualAccount = $this->merchant->paymentAccounts()->create([
            'provider' => 'manual',
            'label' => 'Direct UPI',
            'upi_id' => 'alpha@upi',
            'payee_name' => 'Alpha Shop',
            'is_default' => false,
        ]);

        $order = app(OrderService::class)->create($this->merchant, [
            'amount' => 150.00,
            'client_order_id' => 'MANUAL-1',
            'payment_account_id' => $manualAccount->id,
        ], Order::LIVE);

        // Customer submits UTR
        app(OrderService::class)->submitCustomerUtr($order, '123456789012');
        $this->assertSame(Order::REVIEW, $order->fresh()->status);

        // Merchant approves UTR
        app(OrderService::class)->approveCustomerUtr($order->fresh(), '123456789012', 150.00);

        $this->assertSame('utr_review', $order->fresh()->verified_by);

        // API check
        $apiResponse = $this->getJson("/api/v1/orders/{$order->order_no}", $this->apiHeaders());
        $apiResponse->assertOk();
        $apiResponse->assertJsonPath('data.status', 'paid');
        $apiResponse->assertJsonPath('data.verified_by', 'utr_review');

        // Dashboard check
        $dashResponse = $this->actingAs($this->merchant)->get(route('orders.show', $order));
        $dashResponse->assertOk();
        $dashResponse->assertSee('Payment received (UTR matched)');
        $dashResponse->assertDontSee('Administrator Override Notice');
    }

    public function test_sandbox_simulation_remains_unchanged(): void
    {
        $sandboxOrder = app(OrderService::class)->create($this->merchant, [
            'amount' => 500.00,
            'client_order_id' => 'SANDBOX-1',
        ], Order::SANDBOX);

        app(OrderService::class)->simulateSandbox($sandboxOrder, 'success');

        $this->assertSame('sandbox', $sandboxOrder->fresh()->verified_by);

        // API check
        $this->merchant->regenerateSandboxApiCredentials();
        $headers = [
            'X-Api-Key' => $this->merchant->sandbox_api_key,
            'X-Api-Secret' => $this->merchant->sandbox_api_secret,
        ];
        $apiResponse = $this->getJson("/api/v1/orders/{$sandboxOrder->order_no}", $headers);
        $apiResponse->assertOk();
        $apiResponse->assertJsonPath('data.verified_by', 'sandbox');

        // Dashboard check
        $dashResponse = $this->actingAs($this->merchant)->get(route('orders.show', $sandboxOrder));
        $dashResponse->assertOk();
        $dashResponse->assertSee('Payment received (TEST simulation)');
        $dashResponse->assertDontSee('Administrator Override Notice');
    }

    public function test_sandbox_orders_cannot_be_admin_overridden(): void
    {
        $sandboxOrder = app(OrderService::class)->create($this->merchant, [
            'amount' => 500.00,
            'client_order_id' => 'SANDBOX-REJECT-1',
        ], Order::SANDBOX);

        $this->expectException(ValidationException::class);

        app(OrderService::class)->adminOverridePaid(
            $sandboxOrder,
            $this->admin,
            'BANKREF-SANDBOX',
            'Should not be permitted on sandbox orders.',
            '127.0.0.1',
            '500.00'
        );
    }

    public function test_legacy_paid_orders_with_null_verified_by_preserve_null_in_api(): void
    {
        $order = $this->createLiveOrder('LEGACY-NULL-1');

        // Force a legacy state where verified_by is null
        $order->forceFill([
            'status' => Order::PAID,
            'verified_by' => null,
            'paid_at' => now(),
            'utr' => 'LEGACY123456',
        ])->save();

        $apiData = $order->fresh()->toApiArray();

        $this->assertSame(Order::PAID, $apiData['status']);
        $this->assertNull($apiData['verified_by']);
    }
}
