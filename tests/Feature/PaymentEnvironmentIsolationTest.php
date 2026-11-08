<?php

namespace Tests\Feature;

use App\Jobs\SendWebhook;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Verifiers\VerificationResult;
use App\Services\WebhookOutboxDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentEnvironmentIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function merchant(): User
    {
        $merchant = User::create(['name' => 'Environment Test', 'email' => 'environment@example.com', 'password' => 'password12345']);
        $merchant->regenerateApiCredentials();

        return $merchant;
    }

    private function account(User $merchant, string $provider = 'paytm_pg', string $environment = 'production'): PaymentAccount
    {
        return $merchant->paymentAccounts()->create([
            'provider' => $provider,
            'label' => 'Environment Test',
            'payee_name' => 'Test Merchant',
            'credentials' => [
                'environment' => $environment,
                'mid' => 'TESTMID',
                'merchant_key' => 'abcd1234EFGH5678',
                'client_id' => 'TESTCLIENT',
                'client_secret' => 'TESTSECRET',
                'client_version' => '1',
            ],
            'is_default' => true,
        ]);
    }

    private function order(User $merchant, PaymentAccount $account): Order
    {
        return app(OrderService::class)->create($merchant, ['amount' => '100.00', 'payment_account_id' => $account->id]);
    }

    private function changeEnvironment(PaymentAccount $account, string $environment): void
    {
        $account->update(['credentials' => array_merge($account->credentials, ['environment' => $environment])]);
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('The mismatched payment operation was accepted.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    private function assertUnpaid(Order $order): void
    {
        $this->assertSame(Order::PENDING, $order->fresh()->status);
        $this->assertNull($order->fresh()->paid_at);
        Queue::assertNotPushed(SendWebhook::class, fn ($job) => $job->event === 'order.paid');
    }

    public static function hostedProviders(): array
    {
        return [['paytm_pg'], ['phonepe_pg']];
    }

    #[DataProvider('hostedProviders')]
    public function test_live_api_rejects_sandbox_accounts_even_with_a_client_environment_parameter(string $provider): void
    {
        $merchant = $this->merchant();
        $account = $this->account($merchant, $provider, 'sandbox');
        $this->postJson('/api/v1/orders', [
            'amount' => 100,
            'client_order_id' => 'CROSS-ENVIRONMENT',
            'payment_account_id' => $account->id,
            'environment' => 'sandbox',
        ], ['X-Api-Key' => $merchant->api_key, 'X-Api-Secret' => $merchant->api_secret])
            ->assertUnprocessable()->assertJsonValidationErrors('payment_account_id');

        $this->assertDatabaseCount('orders', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_rotation_filters_environments_and_never_falls_back_from_an_explicit_sandbox_account(): void
    {
        $merchant = $this->merchant();
        $sandbox = $this->account($merchant, environment: 'sandbox');
        $this->assertRejected(fn () => app(OrderService::class)->create($merchant, ['amount' => 100]));

        $live = $this->account($merchant);
        $this->assertRejected(fn () => $this->order($merchant, $sandbox));
        $order = app(OrderService::class)->create($merchant, ['amount' => 100]);
        $this->assertSame($live->id, $order->payment_account_id);

        $live->update(['is_active' => false]);
        $this->assertRejected(fn () => app(OrderService::class)->create($merchant, ['amount' => 100]));
        $this->assertDatabaseCount('orders', 1);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_missing_or_unknown_hosted_account_environment_is_rejected(): void
    {
        $merchant = $this->merchant();
        $account = $this->account($merchant);
        foreach ([[], ['environment' => 'live'], ['environment' => 'invalid']] as $credentials) {
            $account->update(['credentials' => $credentials]);
            $this->assertRejected(fn () => $this->order($merchant, $account));
        }
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_sandbox_simulations_reject_account_input_at_both_service_and_api_boundaries(): void
    {
        $merchant = $this->merchant();
        $live = $this->account($merchant);
        $sandboxKeys = $merchant->regenerateSandboxApiCredentials();
        $this->assertRejected(fn () => app(OrderService::class)->create($merchant,
            ['amount' => 100, 'payment_account_id' => $live->id], Order::SANDBOX));

        $this->postJson('/api/v1/orders', ['amount' => 100, 'client_order_id' => 'TEST-ACCOUNT', 'payment_account_id' => $live->id], [
            'X-Api-Key' => $sandboxKeys['key'], 'X-Api-Secret' => $sandboxKeys['secret'],
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_account_id');

        $order = app(OrderService::class)->create($merchant, ['amount' => 100], Order::SANDBOX);
        $this->assertNull($order->payment_account_id);
        app(OrderService::class)->simulateSandbox($order, 'success');
        $this->assertSame(Order::PAID, $order->fresh()->status);
        $this->assertSame('sandbox', $order->fresh()->verified_by);
        app(WebhookOutboxDispatcher::class)->dispatchDue();
        Queue::assertPushed(SendWebhook::class, fn ($job) => $job->event === 'order.paid' && $job->order->environment === Order::SANDBOX);
        Http::assertNothingSent();
    }

    #[DataProvider('hostedProviders')]
    public function test_account_environment_change_blocks_checkout_and_direct_verification_before_http(string $provider): void
    {
        $merchant = $this->merchant();
        $account = $this->account($merchant, $provider);
        $order = $this->order($merchant, $account)->load('paymentAccount');
        $this->changeEnvironment($account, 'sandbox');

        $this->postJson(route('pay.start', $order))->assertUnprocessable()->assertJsonValidationErrors('payment_account_id');
        $this->getJson(route('pay.status', $order))->assertUnprocessable()->assertJsonValidationErrors('payment_account_id');
        $this->postJson(route('pay.return', $order), ['STATUS' => 'TXN_SUCCESS'])->assertUnprocessable();
        $this->assertRejected(fn () => app(OrderService::class)->check($order));
        $this->assertRejected(fn () => $account->verifier()->startCheckout($order, route('pay.return', $order)));
        $this->assertRejected(fn () => $account->verifier()->check($order));

        $this->assertUnpaid($order);
        Http::assertNothingSent();
    }

    public function test_sandbox_evidence_is_rejected_even_if_account_is_later_changed_to_production(): void
    {
        $merchant = $this->merchant();
        $account = $this->account($merchant);
        $order = $this->order($merchant, $account);
        $this->changeEnvironment($account, 'sandbox');
        $evidence = VerificationResult::fromPaytmPg($order->load('paymentAccount'), 100, '123456789012', 'TEST-TXN');
        $this->assertSame(Order::SANDBOX, $evidence->environment);

        $lease = $this->verificationLease($order);
        $this->assertRejected(fn () => app(OrderService::class)->markPaid($order, $evidence, $lease));
        $this->changeEnvironment($account, 'production');
        $this->assertRejected(fn () => app(OrderService::class)->markPaid($order, $evidence, $lease));
        $this->assertUnpaid($order);
        Http::assertNothingSent();
    }

    public function test_account_environment_change_during_a_successful_provider_request_is_rejected(): void
    {
        $merchant = $this->merchant();
        $account = $this->account($merchant);
        $order = $this->order($merchant, $account);
        Http::fake(['secure.paytmpayments.com/v3/order/status' => function () use ($account, $order) {
            $this->changeEnvironment($account, 'sandbox');

            return Http::response(['body' => ['resultInfo' => ['resultStatus' => 'TXN_SUCCESS'],
                'orderId' => $order->order_no, 'txnAmount' => '100.00', 'txnId' => 'RACE-TXN', 'bankTxnId' => '123456789012', 'txnDate' => now()->format('Y-m-d H:i:s')]]);
        }]);

        $this->assertRejected(fn () => app(OrderService::class)->check($order, force: true));
        Http::assertSentCount(1);
        $this->assertUnpaid($order);
    }

    public function test_unbound_cached_sandbox_checkout_is_replaced_with_a_live_checkout(): void
    {
        $merchant = $this->merchant();
        $account = $this->account($merchant);
        $order = $this->order($merchant, $account);
        $order->forceFill(['provider_checkout' => [
            'type' => 'form',
            'url' => 'https://securestage.paytmpayments.com/theia/api/v1/showPaymentPage',
            'fields' => ['txnToken' => 'OLD-SANDBOX-TOKEN'],
            'expires_at' => now()->addHour()->timestamp,
        ]])->save();
        Http::fake(['secure.paytmpayments.com/theia/api/v1/initiateTransaction*' => Http::response([
            'body' => ['resultInfo' => ['resultStatus' => 'S'], 'txnToken' => 'LIVE-TOKEN'],
        ])]);

        $this->post(route('pay.start', $order))->assertOk()->assertSee('LIVE-TOKEN')
            ->assertDontSee('OLD-SANDBOX-TOKEN')->assertDontSee('securestage.paytmpayments.com');

        $checkout = $order->fresh()->provider_checkout;
        $this->assertSame($account->id, $checkout['payment_account_id']);
        $this->assertSame(Order::LIVE, $checkout['payment_environment']);
        $this->assertStringStartsWith('https://secure.paytmpayments.com/', $checkout['url']);
        Http::assertSentCount(1);
        $this->assertUnpaid($order);
    }

    public function test_interleaved_admin_override_and_provider_success_only_dispatch_one_paid_event(): void
    {
        $merchant = $this->merchant();
        $account = $this->account($merchant);
        $order = $this->order($merchant, $account);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password12345', 'role' => 'admin']);
        Http::fake(['secure.paytmpayments.com/v3/order/status' => function () use ($admin, $order) {
            // A second transition finishes while the first is awaiting HTTP.
            app(OrderService::class)->adminOverridePaid($order, $admin, 'BANKREF123',
                'Bank statement independently reviewed.', '127.0.0.1', '100.00');

            return Http::response(['body' => ['resultInfo' => ['resultStatus' => 'TXN_SUCCESS'],
                'orderId' => $order->order_no, 'txnAmount' => '100.00', 'txnId' => 'LATE-TXN', 'bankTxnId' => '123456789012', 'txnDate' => now()->format('Y-m-d H:i:s')]]);
        }]);

        app(OrderService::class)->check($order, force: true);
        $this->assertSame(Order::PAID, $order->fresh()->status);
        $this->assertSame('admin_override', $order->fresh()->verified_by);
        $this->assertSame('BANKREF123', $order->fresh()->utr);
        $this->assertDatabaseCount('admin_audit_logs', 1);
        app(WebhookOutboxDispatcher::class)->dispatchDue();
        Queue::assertPushed(SendWebhook::class, 1);
        Http::assertSentCount(1);
    }

    public static function changedEvidenceBindings(): array
    {
        return [['account'], ['order_number'], ['amount'], ['currency'], ['provider'], ['order_environment']];
    }

    #[DataProvider('changedEvidenceBindings')]
    public function test_final_transition_revalidates_evidence_against_locked_database_state(string $change): void
    {
        $merchant = $this->merchant();
        $account = $this->account($merchant);
        $order = $this->order($merchant, $account)->load('paymentAccount');
        $evidence = $change === 'provider'
            ? VerificationResult::fromPhonePePg($order, 100, '123456789012', 'WRONG-PROVIDER')
            : VerificationResult::fromPaytmPg($order, 100, '123456789012', 'TXN-BOUND', currency: $change === 'currency' ? 'USD' : 'INR');
        $lease = $this->verificationLease($order);

        match ($change) {
            'account' => Order::whereKey($order->id)->update(['payment_account_id' => $this->account($merchant)->id]),
            'order_number' => Order::whereKey($order->id)->update(['order_no' => 'ORDCHANGED']),
            'amount' => Order::whereKey($order->id)->update(['amount' => '101.00']),
            'order_environment' => Order::whereKey($order->id)->update(['environment' => Order::SANDBOX]),
            default => null,
        };

        $this->assertRejected(fn () => app(OrderService::class)->markPaid($order, $evidence, $lease));
        $this->assertUnpaid($order);
        Http::assertNothingSent();
    }

    public function test_admin_override_cannot_complete_a_mismatched_account_order(): void
    {
        $merchant = $this->merchant();
        $account = $this->account($merchant);
        $order = $this->order($merchant, $account);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password12345', 'role' => 'admin']);
        $this->changeEnvironment($account, 'sandbox');

        $this->assertRejected(fn () => app(OrderService::class)->adminOverridePaid($order, $admin, 'BANKREF123',
            'Bank statement independently reviewed.', '127.0.0.1', '100.00'));
        $this->assertDatabaseMissing('admin_audit_logs', ['action' => 'order_manual_payment_override']);
        $this->assertUnpaid($order);
        Http::assertNothingSent();
    }

    public function test_sandbox_simulation_rejects_a_legacy_order_attached_to_a_live_account(): void
    {
        $merchant = $this->merchant();
        $account = $this->account($merchant);
        $order = app(OrderService::class)->create($merchant, ['amount' => 100], Order::SANDBOX);
        $order->forceFill(['payment_account_id' => $account->id])->save();

        $this->postJson(route('sandbox.pay.simulate', $order), ['outcome' => 'success'])
            ->assertUnprocessable()->assertJsonValidationErrors('payment_account_id');
        $this->assertUnpaid($order);
        Http::assertNothingSent();
    }

    public function test_scheduler_continues_after_rejecting_a_legacy_environment_mismatch(): void
    {
        $merchant = $this->merchant();
        $account = $this->account($merchant);
        $mismatched = $this->order($merchant, $account);
        $this->changeEnvironment($account, 'sandbox');
        $manual = $merchant->paymentAccounts()->create([
            'provider' => 'manual', 'label' => 'Manual', 'upi_id' => 'test@upi', 'payee_name' => 'Test Merchant',
        ]);
        $expiring = $this->order($merchant, $manual);
        $expiring->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->artisan('orders:check-pending')->expectsOutput('Checked 1 pending orders.')
            ->expectsOutput('Rejected 1 pending orders; review their payment account configuration.')->assertSuccessful();

        $this->assertUnpaid($mismatched);
        $this->assertSame(Order::EXPIRED, $expiring->fresh()->status);
        Http::assertNothingSent();
    }
}
