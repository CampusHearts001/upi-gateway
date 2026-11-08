<?php

namespace Tests\Feature;

use App\Jobs\SendWebhook;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Verifiers\VerificationResult;
use App\Services\WebhookOutboxDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LatePaymentRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
        Http::preventStrayRequests();
        $this->merchant = User::create(['name' => 'Late Payment Test', 'email' => 'late@example.com', 'password' => 'password12345']);
        $this->merchant->regenerateApiCredentials();
    }

    private function order(string $provider = 'paytm_pg'): Order
    {
        $account = $this->merchant->paymentAccounts()->create([
            'provider' => $provider, 'label' => 'Late Payment Test', 'payee_name' => 'Test Merchant', 'upi_id' => 'late@upi',
            'credentials' => ['environment' => 'production', 'mid' => 'TESTMID', 'merchant_key' => 'abcd1234EFGH5678',
                'client_id' => 'TESTCLIENT', 'client_secret' => 'TESTSECRET', 'client_version' => '1'],
        ]);
        $order = app(OrderService::class)->create($this->merchant, ['amount' => '100.00', 'payment_account_id' => $account->id]);
        $order->forceFill([
            'status' => Order::EXPIRED,
            'created_at' => now()->subHours(2),
            'expires_at' => now()->subHour(),
            'original_expires_at' => now()->subHour(),
            'last_checked_at' => null,
        ])->save();

        return $order->load('paymentAccount');
    }

    private function success(Order $order): array
    {
        return match ($order->paymentAccount->provider) {
            'paytm_business' => ['STATUS' => 'TXN_SUCCESS', 'ORDERID' => $order->order_no, 'TXNAMOUNT' => '100.00',
                'CURRENCY' => 'INR', 'BANKTXNID' => '123456789012', 'TXNID' => 'LATE-TXN',
                'TXNDATE' => now()->subMinutes(45)->format('Y-m-d H:i:s')],
            'paytm_pg' => ['resultInfo' => ['resultStatus' => 'TXN_SUCCESS'], 'orderId' => $order->order_no,
                'txnAmount' => '100.00', 'currency' => 'INR', 'bankTxnId' => '123456789012', 'txnId' => 'LATE-TXN',
                'txnDate' => now()->subMinutes(45)->format('Y-m-d H:i:s')],
            'phonepe_pg' => ['state' => 'COMPLETED', 'merchantOrderId' => $order->order_no, 'orderId' => 'PHONEPE-ID',
                'amount' => 10000, 'currency' => 'INR', 'paymentDetails' => [
                    ['state' => 'COMPLETED', 'transactionId' => 'LATE-TXN', 'rail' => ['utr' => '123456789012'],
                        'completedAt' => now()->subMinutes(45)->getTimestampMs()],
                ]],
        };
    }

    private function fakeStatus(Order $order, array $data): void
    {
        match ($order->paymentAccount->provider) {
            'paytm_business' => Http::fake([config('gateway.paytm_status_url').'*' => Http::response($data)]),
            'paytm_pg' => Http::fake(['secure.paytmpayments.com/v3/order/status' => Http::response(['body' => $data])]),
            'phonepe_pg' => Http::fake([
                'api.phonepe.com/apis/identity-manager/v1/oauth/token' => Http::response(['access_token' => 'TESTTOKEN', 'expires_at' => time() + 3600]),
                'api.phonepe.com/apis/pg/checkout/v2/order/*' => Http::response($data),
            ]),
        };
    }

    private function assertExpired(Order $order): void
    {
        $this->assertSame(Order::EXPIRED, $order->fresh()->status);
        $this->assertNull($order->fresh()->paid_at);
        Queue::assertNotPushed(SendWebhook::class, fn ($job) => $job->event === 'order.paid');
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected validation to reject payment evidence.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    public static function providers(): array
    {
        return [['paytm_business'], ['paytm_pg'], ['phonepe_pg']];
    }

    #[DataProvider('providers')]
    public function test_trusted_late_success_is_recovered_once(string $provider): void
    {
        $order = $this->order($provider);
        $this->fakeStatus($order, $this->success($order));
        $service = app(OrderService::class);
        $service->check($order);
        $service->check($order, force: true);

        $this->assertSame(Order::PAID, $order->fresh()->status);
        $this->assertSame('provider', $order->fresh()->verified_by);
        $this->assertSame('123456789012', $order->fresh()->utr);
        app(WebhookOutboxDispatcher::class)->dispatchDue();
        Queue::assertPushed(SendWebhook::class, 1);
        Http::assertSentCount($provider === 'phonepe_pg' ? 2 : 1);
    }

    public static function invalidResponses(): array
    {
        $cases = [];
        foreach (['paytm_business', 'paytm_pg', 'phonepe_pg'] as $provider) {
            foreach (['pending', 'failed', 'order', 'amount', 'fractional_amount', 'currency', 'reference'] as $error) {
                $cases[$provider.'-'.$error] = [$provider, $error];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidResponses')]
    public function test_unconfirmed_or_mismatched_provider_data_leaves_order_expired(string $provider, string $error): void
    {
        $order = $this->order($provider);
        $data = $this->success($order);
        $statusKey = match ($provider) {
            'paytm_business' => 'STATUS', 'paytm_pg' => 'resultInfo', default => 'state',
        };
        if (in_array($error, ['pending', 'failed'], true)) {
            $status = $error === 'pending' ? 'PENDING' : 'FAILED';
            $data[$statusKey] = $provider === 'paytm_pg' ? ['resultStatus' => $status] : $status;
        } elseif ($error === 'order') {
            $data[match ($provider) {
                'paytm_business' => 'ORDERID', 'paytm_pg' => 'orderId', default => 'merchantOrderId'
            }] = 'WRONG-ORDER';
        } elseif ($error === 'amount') {
            $data[match ($provider) {
                'paytm_business' => 'TXNAMOUNT', 'paytm_pg' => 'txnAmount', default => 'amount'
            }] = 1;
        } elseif ($error === 'fractional_amount') {
            $data[match ($provider) {
                'paytm_business' => 'TXNAMOUNT', 'paytm_pg' => 'txnAmount', default => 'amount'
            }] = $provider === 'phonepe_pg' ? 10000.5 : '100.001';
        } elseif ($error === 'currency') {
            $data[$provider === 'paytm_business' ? 'CURRENCY' : 'currency'] = 'USD';
        } else {
            foreach (['BANKTXNID', 'TXNID', 'bankTxnId', 'txnId', 'orderId', 'paymentDetails'] as $field) {
                unset($data[$field]);
            }
            // Paytm PG still needs its local order ID for the order match.
            if ($provider === 'paytm_pg') {
                $data['orderId'] = $order->order_no;
            }
        }
        $this->fakeStatus($order, $data);
        app(OrderService::class)->check($order);
        $this->assertExpired($order);
    }

    public function test_deadline_configuration_and_null_expiry_prevent_provider_requests(): void
    {
        $order = $this->order();
        $order->forceFill(['expires_at' => now()->subHours(24)->subSecond()])->save();
        app(OrderService::class)->check($order, force: true);
        $this->assertExpired($order);

        config(['gateway.late_payment_grace_hours' => 0]);
        $order->forceFill(['expires_at' => now()->subHour()])->save();
        app(OrderService::class)->check($order, force: true);
        $this->assertExpired($order);

        config(['gateway.late_payment_grace_hours' => 24]);
        $order->forceFill(['expires_at' => null])->save();
        app(OrderService::class)->check($order);
        $this->assertExpired($order);
        $this->assertNull($order->fresh()->expires_at);
        Http::assertNothingSent();
    }

    public function test_stale_pending_order_cannot_bypass_recovery_deadline(): void
    {
        $order = $this->order();
        $order->forceFill(['status' => Order::PENDING, 'expires_at' => now()->subHours(25)])->save();
        app(OrderService::class)->check($order, force: true);
        $this->assertExpired($order);
        Http::assertNothingSent();
    }

    public function test_custom_grace_period_and_boundary_are_enforced(): void
    {
        config(['gateway.late_payment_grace_hours' => 2]);
        $order = $this->order();
        $order->forceFill(['expires_at' => now()->subHours(2)])->save();
        $this->fakeStatus($order, $this->success($order));
        app(OrderService::class)->check($order);
        $this->assertSame(Order::PAID, $order->fresh()->status);
        app(WebhookOutboxDispatcher::class)->dispatchDue();
        Queue::assertPushed(SendWebhook::class, 1);
    }

    public function test_expired_check_throttle_cannot_be_bypassed_by_forced_returns(): void
    {
        $order = $this->order();
        $this->fakeStatus($order, ['resultInfo' => ['resultStatus' => 'PENDING']]);
        $service = app(OrderService::class);
        $service->check($order);
        $service->check($order, force: true);
        $this->travel(299)->seconds();
        $service->check($order, force: true);
        Http::assertSentCount(1);
        $this->travel(1)->seconds();
        $service->check($order, force: true);
        Http::assertSentCount(2);
        $this->assertExpired($order);
    }

    public function test_overlapping_checks_and_existing_lock_do_not_duplicate_http_or_paid_events(): void
    {
        $order = $this->order();
        $lock = Cache::lock('order-verification:'.$order->id, 120);
        $this->assertTrue($lock->get());
        app(OrderService::class)->check($order, force: true);
        Http::assertNothingSent();
        $lock->release();

        Http::fake(['secure.paytmpayments.com/v3/order/status' => function () use ($order) {
            app(OrderService::class)->check($order->fresh(), force: true);

            return Http::response(['body' => $this->success($order)]);
        }]);
        app(OrderService::class)->check($order);
        $this->assertSame(Order::PAID, $order->fresh()->status);
        Http::assertSentCount(1);
        app(WebhookOutboxDispatcher::class)->dispatchDue();
        Queue::assertPushed(SendWebhook::class, 1);
    }

    public function test_abandoned_check_lock_expires_and_recovery_resumes(): void
    {
        $order = $this->order();
        $lock = Cache::lock('order-verification:'.$order->id, 120);
        $this->assertTrue($lock->get());
        $this->fakeStatus($order, $this->success($order));
        app(OrderService::class)->check($order);
        Http::assertNothingSent();
        $this->travel(121)->seconds();
        app(OrderService::class)->check($order);
        $this->assertSame(Order::PAID, $order->fresh()->status);
        app(WebhookOutboxDispatcher::class)->dispatchDue();
        Queue::assertPushed(SendWebhook::class, 1);
    }

    public static function racedBindings(): array
    {
        return [['account'], ['environment'], ['order_number'], ['amount'], ['deadline'], ['review']];
    }

    #[DataProvider('racedBindings')]
    public function test_state_changed_during_http_is_revalidated_before_late_payment(string $change): void
    {
        $order = $this->order();
        $replacement = $change === 'account' ? $this->order()->paymentAccount : null;
        Http::fake(['secure.paytmpayments.com/v3/order/status' => function () use ($order, $change, $replacement) {
            $data = $this->success($order);
            match ($change) {
                'account' => Order::whereKey($order->id)->update(['payment_account_id' => $replacement->id]),
                'environment' => $order->paymentAccount->update(['credentials' => array_merge($order->paymentAccount->credentials, ['environment' => 'sandbox'])]),
                'order_number' => Order::whereKey($order->id)->update(['order_no' => 'ORDCHANGED']),
                'amount' => Order::whereKey($order->id)->update(['amount' => '101.00']),
                'deadline' => $this->travel(24)->hours(),
                'review' => Order::whereKey($order->id)->update(['status' => Order::REVIEW]),
            };

            return Http::response(['body' => $data]);
        }]);
        if ($change === 'review') {
            app(OrderService::class)->check($order);
            $this->assertSame(Order::REVIEW, $order->fresh()->status);
            Queue::assertNothingPushed();
        } else {
            $this->assertRejected(fn () => app(OrderService::class)->check($order));
            $this->assertExpired($order);
        }
    }

    public function test_manual_and_sandbox_expired_orders_never_use_provider_recovery(): void
    {
        $manual = $this->order('manual');
        app(OrderService::class)->check($manual);
        $this->assertExpired($manual);
        $sandbox = app(OrderService::class)->create($this->merchant, ['amount' => 100], Order::SANDBOX);
        $sandbox->forceFill(['status' => Order::EXPIRED, 'expires_at' => now()->subHour()])->save();
        app(OrderService::class)->check($sandbox, force: true);
        $this->assertExpired($sandbox);
        Http::assertNothingSent();
    }

    public function test_direct_late_transition_cannot_reuse_evidence_after_deadline(): void
    {
        $order = $this->order();
        $evidence = VerificationResult::fromPaytmPg($order, 100, '123456789012', 'LATE-TXN');
        $this->travel(24)->hours();
        $lease = $this->verificationLease($order);
        $this->assertRejected(fn () => app(OrderService::class)->markPaid($order, $evidence, $lease));
        $this->assertExpired($order);
        Http::assertNothingSent();
    }

    public function test_api_status_and_checkout_polling_can_recover_expired_orders(): void
    {
        $order = $this->order();
        $this->fakeStatus($order, $this->success($order));
        $this->getJson('/api/v1/orders/'.$order->order_no, [
            'X-Api-Key' => $this->merchant->api_key, 'X-Api-Secret' => $this->merchant->api_secret,
        ])->assertOk()->assertJsonPath('data.status', Order::PAID);
        $this->getJson(route('pay.status', $order))->assertOk()->assertJsonPath('status', Order::PAID);
        app(WebhookOutboxDispatcher::class)->dispatchDue();
        Queue::assertPushed(SendWebhook::class, 1);
    }

    public function test_expired_checkout_polls_only_during_recovery_window_without_reopening_payment(): void
    {
        $order = $this->order();
        $this->fakeStatus($order, ['resultInfo' => ['resultStatus' => 'PENDING']]);
        $this->get(route('pay.show', $order))->assertOk()->assertSee('We can still check the provider')
            ->assertSee('const pollDelay', false)->assertDontSee('Pay securely with Paytm');
        $this->assertExpired($order);
        $this->travel(24)->hours();
        $this->get(route('pay.show', $order))->assertOk()->assertDontSee('const pollDelay', false);
        Http::assertSentCount(1);
    }

    public function test_scheduler_batch_limit_cooldown_legacy_logs_and_recovery(): void
    {
        config(['gateway.late_payment_batch_size' => 2]);
        Log::spy();
        $invalid = $this->order();
        $invalid->paymentAccount->update(['credentials' => ['environment' => 'sandbox', 'mid' => 'DO-NOT-LOG']]);
        $valid = [$this->order(), $this->order(), $this->order()];
        Http::fake(['secure.paytmpayments.com/v3/order/status' => function (Request $request) {
            $order = Order::where('order_no', $request['body']['orderId'])->firstOrFail()->load('paymentAccount');

            $data = $this->success($order);
            $data['bankTxnId'] = sprintf('%012d', 123450000000 + $order->id);

            return Http::response(['body' => $data]);
        }]);
        $this->artisan('orders:check-pending')->assertSuccessful();
        $this->assertSame(Order::EXPIRED, $invalid->fresh()->status);
        $this->assertNotNull($invalid->fresh()->last_checked_at);
        $this->assertSame(1, Order::where('status', Order::PAID)->count());
        Http::assertSentCount(1);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => $message === 'Late payment verification rejected'
            && $context['order_id'] === $invalid->id && $context['payment_account_id'] === $invalid->payment_account_id
            && $context['order_environment'] === Order::LIVE && $context['account_environment'] === Order::SANDBOX
            && ! str_contains(json_encode($context), 'DO-NOT-LOG'));

        $this->artisan('orders:check-pending')->assertSuccessful();
        $this->assertSame(3, Order::where('status', Order::PAID)->count());
        app(WebhookOutboxDispatcher::class)->dispatchDue();
        Queue::assertPushed(SendWebhook::class, 3);
        Http::assertSentCount(3);
        $this->assertSame(Order::EXPIRED, $invalid->fresh()->status);
    }
}
