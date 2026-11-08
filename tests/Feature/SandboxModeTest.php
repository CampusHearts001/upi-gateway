<?php

namespace Tests\Feature;

use App\Jobs\SendWebhook;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Services\WebhookOutboxDispatcher;
use App\Support\WebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SandboxModeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: string, 2: string} */
    private function merchant(string $email = 'sandbox@example.com'): array
    {
        $merchant = User::create([
            'name' => 'Sandbox Merchant',
            'business_name' => 'Test Shop',
            'email' => $email,
            'password' => 'password12345',
        ]);
        $merchant->regenerateApiCredentials();
        $credentials = $merchant->regenerateSandboxApiCredentials();
        $merchant->paymentAccounts()->create([
            'provider' => 'manual',
            'label' => 'Live account',
            'upi_id' => 'live@upi',
            'payee_name' => 'Live Shop',
            'is_default' => true,
        ]);

        return [$merchant->fresh(), $credentials['key'], $credentials['secret']];
    }

    private function headers(string $key, string $secret): array
    {
        return [
            'X-Api-Key' => $key,
            'X-Api-Secret' => $secret,
            'Accept' => 'application/json',
        ];
    }

    private function createSandboxOrder(User $merchant, string $key, string $secret, string $clientId = 'TEST-1', int $amount = 100): Order
    {
        $orderNo = $this->postJson('/api/v1/orders', [
            'amount' => $amount,
            'client_order_id' => $clientId,
        ], $this->headers($key, $secret))
            ->assertCreated()
            ->assertJsonPath('data.environment', Order::SANDBOX)
            ->assertJsonPath('data.test', true)
            ->assertJsonPath('data.upi_intent', null)
            ->json('data.order_id');

        return Order::where('order_no', $orderNo)->firstOrFail();
    }

    public function test_sandbox_credentials_authenticate_and_never_use_a_live_account(): void
    {
        Http::fake();
        [$merchant, $key, $secret] = $this->merchant();

        $order = $this->createSandboxOrder($merchant, $key, $secret);

        $this->assertStringStartsWith('TST', $order->order_no);
        $this->assertSame(Order::SANDBOX, $order->environment);
        $this->assertNull($order->payment_account_id);
        $this->assertStringContainsString('/sandbox/pay/', $order->paymentUrl());
        Http::assertNothingSent();
    }

    public function test_request_environment_cannot_override_the_authenticated_credential(): void
    {
        [$merchant, $key, $secret] = $this->merchant();

        $response = $this->postJson('/api/v1/orders', [
            'amount' => 100,
            'client_order_id' => 'NO-ENV-SWITCH',
            'environment' => Order::LIVE,
        ], $this->headers($key, $secret))->assertCreated();

        $this->assertSame(Order::SANDBOX, $response->json('data.environment'));
        $this->assertSame(
            Order::SANDBOX,
            Order::where('client_order_id', 'NO-ENV-SWITCH')->value('environment')
        );
    }

    public function test_invalid_or_cross_environment_secrets_are_rejected(): void
    {
        [$merchant, $key, $secret] = $this->merchant();

        $this->getJson('/api/v1/accounts', $this->headers($key, 'wrong'))->assertUnauthorized();
        $this->getJson('/api/v1/accounts', $this->headers($key, $merchant->api_secret))->assertUnauthorized();
        $this->getJson('/api/v1/accounts', $this->headers($merchant->api_key, $secret))->assertUnauthorized();
    }

    public function test_sandbox_success_is_internal_and_cannot_use_live_checkout(): void
    {
        Http::fake();
        [$merchant, $key, $secret] = $this->merchant();
        $order = $this->createSandboxOrder($merchant, $key, $secret);

        $this->postJson(route('sandbox.pay.simulate', $order), ['outcome' => 'success'])
            ->assertOk()
            ->assertJsonPath('data.status', Order::PAID)
            ->assertJsonPath('data.test', true);

        $order->refresh();
        $this->assertSame(Order::PAID, $order->status);
        $this->assertSame('sandbox', $order->verified_by);
        $this->assertStringStartsWith('TEST', $order->utr);
        $this->get(route('pay.show', $order))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_failure_pending_and_expired_outcomes(): void
    {
        [$merchant, $key, $secret] = $this->merchant();

        foreach ([
            'failed' => Order::FAILED,
            'pending' => Order::PENDING,
            'expired' => Order::EXPIRED,
        ] as $outcome => $status) {
            $order = $this->createSandboxOrder($merchant, $key, $secret, 'OUTCOME-'.strtoupper($outcome));
            $this->postJson(route('sandbox.pay.simulate', $order), ['outcome' => $outcome])
                ->assertOk()
                ->assertJsonPath('data.status', $status);
            $this->assertNotNull($order->fresh()->sandbox_simulated_at);
        }
    }

    public function test_timeout_and_invalid_reference_are_controlled_simulations(): void
    {
        Http::fake();
        [$merchant, $key, $secret] = $this->merchant();
        $timeout = $this->createSandboxOrder($merchant, $key, $secret, 'TIMEOUT');

        $this->postJson(route('sandbox.pay.simulate', $timeout), ['outcome' => 'timeout'])
            ->assertStatus(504)
            ->assertJsonPath('message', 'Simulated provider timeout.');
        $this->assertSame(Order::PENDING, $timeout->fresh()->status);

        $invalid = $this->createSandboxOrder($merchant, $key, $secret, 'INVALID-REF');
        $this->postJson(route('sandbox.pay.simulate', $invalid), ['outcome' => 'invalid_reference'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('transaction_reference');
        $this->assertNull($invalid->fresh()->sandbox_simulated_at);
        Http::assertNothingSent();
    }

    public function test_live_and_sandbox_orders_are_isolated_even_with_the_same_client_id(): void
    {
        [$merchant, $key, $secret] = $this->merchant();
        $sandbox = $this->createSandboxOrder($merchant, $key, $secret, 'SHARED-ID');

        $liveOrderNo = $this->postJson('/api/v1/orders', [
            'amount' => 100,
            'client_order_id' => 'SHARED-ID',
        ], $this->headers($merchant->api_key, $merchant->api_secret))
            ->assertCreated()
            ->assertJsonPath('data.environment', Order::LIVE)
            ->json('data.order_id');

        $this->getJson('/api/v1/orders/'.$liveOrderNo, $this->headers($key, $secret))->assertNotFound();
        $this->getJson('/api/v1/orders/'.$sandbox->order_no, $this->headers($merchant->api_key, $merchant->api_secret))->assertNotFound();
    }

    public function test_sandbox_api_is_scoped_to_the_authenticated_merchant(): void
    {
        [$merchantA, $keyA, $secretA] = $this->merchant('a@example.com');
        [, $keyB, $secretB] = $this->merchant('b@example.com');
        $order = $this->createSandboxOrder($merchantA, $keyA, $secretA);

        $this->getJson('/api/v1/orders/'.$order->order_no, $this->headers($keyB, $secretB))->assertNotFound();
    }

    public function test_dashboard_totals_are_filtered_by_environment(): void
    {
        [$merchant, $key, $secret] = $this->merchant();
        $sandbox = $this->createSandboxOrder($merchant, $key, $secret, 'SANDBOX-TOTAL', 500);
        app(OrderService::class)->simulateSandbox($sandbox, 'success');

        $live = app(OrderService::class)->create($merchant, ['amount' => 100, 'client_order_id' => 'LIVE-TOTAL']);
        // A stored paid fixture tests reporting without authorizing a payment.
        $live->forceFill(['status' => Order::PAID, 'paid_at' => now()])->save();

        $liveStats = $this->actingAs($merchant)->get(route('dashboard'))->viewData('stats');
        $sandboxResponse = $this->actingAs($merchant)->get(route('dashboard', ['environment' => 'sandbox']));
        $sandboxStats = $sandboxResponse->viewData('stats');

        $this->assertSame(100.0, $liveStats['total_amount']);
        $this->assertSame(500.0, $sandboxStats['total_amount']);
        $sandboxResponse->assertSee('SIMULATED DATA');
    }

    public function test_legacy_manual_service_arguments_cannot_mark_a_live_order_paid(): void
    {
        Queue::fake();
        [$merchant] = $this->merchant();
        $live = app(OrderService::class)->create($merchant, ['amount' => 100, 'client_order_id' => 'LEGACY']);

        try {
            app(OrderService::class)->markPaid($live, '123456789012', null, [], 'manual');
            $this->fail('Legacy manual payment arguments must be rejected.');
        } catch (\TypeError) {
            $this->assertSame(Order::PENDING, $live->fresh()->status);
        }
        Queue::assertNotPushed(SendWebhook::class, fn ($job) => $job->event === 'order.paid');
    }

    public function test_sandbox_webhook_uses_only_sandbox_secret_and_is_marked_test(): void
    {
        Http::fake(['sandbox.example/*' => Http::response('ok', 200)]);
        [$merchant, $key, $secret] = $this->merchant();
        $merchant->update([
            'webhook_url' => 'https://live.example/webhook',
            'sandbox_webhook_url' => 'https://sandbox.example/webhook',
        ]);
        $order = $this->createSandboxOrder($merchant, $key, $secret);
        app(OrderService::class)->simulateSandbox($order, 'success');

        (new SendWebhook($order->fresh(), 'order.paid'))->handle();

        Http::assertSent(function (HttpRequest $request) use ($secret) {
            $payload = json_decode($request->body(), true);

            return $request->url() === 'https://sandbox.example/webhook'
                && ($payload['data']['environment'] ?? null) === Order::SANDBOX
                && ($payload['data']['test'] ?? false) === true
                && WebhookSignature::verify($request->body(), $request->header('X-Signature')[0], $secret);
        });
        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), 'live.example'));
        $this->assertNotSame(
            hash_hmac('sha256', Http::recorded()[0][0]->body(), $merchant->api_secret),
            Http::recorded()[0][0]->header('X-Signature')[0]
        );
    }

    public function test_duplicate_simulation_is_rejected_without_a_second_event(): void
    {
        Queue::fake();
        [$merchant, $key, $secret] = $this->merchant();
        $order = $this->createSandboxOrder($merchant, $key, $secret);

        $this->postJson(route('sandbox.pay.simulate', $order), ['outcome' => 'success'])->assertOk();
        $this->postJson(route('sandbox.pay.simulate', $order), ['outcome' => 'failed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('outcome');

        app(WebhookOutboxDispatcher::class)->dispatchDue();
        Queue::assertPushed(SendWebhook::class, 1);
        $this->assertSame(Order::PAID, $order->fresh()->status);
    }

    public function test_sandbox_credentials_can_be_revoked_and_secret_is_not_redisplayed(): void
    {
        [$merchant, $key, $secret] = $this->merchant();

        $this->actingAs($merchant)->get(route('settings'))
            ->assertOk()
            ->assertDontSee($secret);

        $this->actingAs($merchant)->post(route('settings.sandbox.revoke'))->assertRedirect();
        $this->getJson('/api/v1/accounts', $this->headers($key, $secret))->assertUnauthorized();
    }
}
