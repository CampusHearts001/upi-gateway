<?php

namespace Tests\Feature;

use App\Jobs\SendWebhook;
use App\Models\AdminAuditLog;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Verifiers\PaytmBusinessVerifier;
use App\Services\Verifiers\PaytmPgVerifier;
use App\Services\Verifiers\PhonePePgVerifier;
use App\Services\Verifiers\VerificationResult;
use App\Services\WebhookOutboxDispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Transaction integrity and payment security tests.
 *
 * Covers:
 *  - Terminal state protection (PAID order cannot be re-paid)
 *  - Provider callback blocked on REVIEW orders
 *  - Race condition protection on approve/reject UTR
 *  - Race condition protection on concurrent UTR submissions
 *  - Order ID mismatch rejection for all three providers
 *  - Webhook idempotency (no double-delivery on retry)
 */
class TransactionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function merchant(): User
    {
        $user = User::create([
            'name' => 'Ravi',
            'business_name' => 'Ravi Store',
            'email' => 'ravi@example.com',
            'password' => 'password123',
        ]);
        $user->regenerateApiCredentials();
        $user->paymentAccounts()->create([
            'provider' => 'paytm_business',
            'label' => 'Main',
            'upi_id' => 'paytmqr123@paytm',
            'payee_name' => 'Ravi Store',
            'credentials' => ['mid' => 'TESTMID123'],
            'is_default' => true,
        ]);

        return $user;
    }

    private function manualMerchant(): User
    {
        $user = User::create([
            'name' => 'Manual',
            'email' => 'manual@example.com',
            'password' => 'password123',
        ]);
        $user->paymentAccounts()->create([
            'provider' => 'manual',
            'label' => 'GPay',
            'upi_id' => 'manual@okaxis',
            'payee_name' => 'Manual',
            'is_default' => true,
        ]);

        return $user;
    }

    private function order(User $merchant, array $extra = []): Order
    {
        return app(OrderService::class)->create($merchant, array_merge(
            ['amount' => 100, 'client_order_id' => 'T-'.uniqid()],
            $extra
        ));
    }

    private function verified(Order $order, string $utr, string $txn): VerificationResult
    {
        return VerificationResult::fromPaytmBusiness($order, $order->amount, $utr, $txn);
    }

    private function assertValidationRejected(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected validation to reject the operation.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    // ── 1. Terminal state: PAID order cannot be re-paid ───────────────────────

    public function test_paid_order_cannot_be_marked_paid_again(): void
    {
        Queue::fake();
        $merchant = $this->merchant();
        $order = $this->order($merchant);

        $service = app(OrderService::class);
        $lease = $this->verificationLease($order);
        $service->markPaid($order, $this->verified($order, '111111111111', 'TXN1'), $lease);
        $this->assertSame(Order::PAID, $order->fresh()->status);

        // Second call must be a no-op (returns false internally, no second webhook).
        $service->markPaid($order, $this->verified($order, '222222222222', 'TXN2'), $lease);
        $this->assertSame('111111111111', $order->fresh()->utr);
        app(WebhookOutboxDispatcher::class)->dispatchDue();
        Queue::assertPushed(SendWebhook::class, 1); // only one webhook
    }

    public function test_provider_evidence_is_bound_to_order_and_amount(): void
    {
        $merchant = $this->merchant();
        $order = $this->order($merchant);
        $other = $this->order($merchant);
        $service = app(OrderService::class);

        Queue::fake();
        $lease = $this->verificationLease($order);
        $this->assertValidationRejected(fn () => $service->markPaid($order, VerificationResult::fromPaytmBusiness(
            $other, $other->amount, '412345678901', 'TXN-WRONG'
        ), $lease));
        $this->assertSame(Order::PENDING, $order->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_unsupported_provider_cannot_create_successful_evidence(): void
    {
        $merchant = $this->merchant();
        $order = $this->order($merchant);

        $this->expectException(\Error::class);
        VerificationResult::fromTrustedProvider($order, 'manual', $order->amount, '412345678901', 'TXN-MANUAL');
    }

    public function test_provider_amount_mismatch_is_rejected_inside_lock(): void
    {
        $merchant = $this->merchant();
        $order = $this->order($merchant);

        Queue::fake();
        $lease = $this->verificationLease($order);
        $this->assertValidationRejected(fn () => app(OrderService::class)->markPaid($order, VerificationResult::fromPaytmBusiness(
            $order, '99.00', '412345678901', 'TXN-AMOUNT'
        ), $lease));
        $this->assertSame(Order::PENDING, $order->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_database_rejects_duplicate_client_order_id_for_one_merchant(): void
    {
        $merchant = $this->merchant();
        $this->order($merchant, ['client_order_id' => 'same-id']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->order($merchant, ['client_order_id' => 'same-id']);
    }

    public function test_direct_utr_approval_leaves_review_state_and_sends_no_paid_webhook(): void
    {
        Queue::fake();
        $merchant = $this->manualMerchant();
        $order = $this->order($merchant);
        app(OrderService::class)->submitCustomerUtr($order, '412345678901');

        $this->assertValidationRejected(fn () => app(OrderService::class)->approveCustomerUtr($order, '412345678901'));

        $this->assertSame(Order::REVIEW, $order->fresh()->status);
        Queue::assertNotPushed(SendWebhook::class, fn ($job) => $job->event === 'order.paid');
    }

    // ── 2. Provider callback must not re-open a REVIEW order ─────────────────

    public function test_provider_callback_does_not_override_review_status(): void
    {
        Queue::fake();
        $merchant = $this->manualMerchant();
        $order = $this->order($merchant);
        $lease = $this->verificationLease($order);

        // Customer submits UTR → order goes to REVIEW.
        $this->post(route('pay.utr', $order), ['utr' => '412345678901']);
        $this->assertSame(Order::REVIEW, $order->fresh()->status);

        // A provider-sourced markPaid (e.g. from a late Paytm callback) must be blocked.
        $service = app(OrderService::class);
        $this->assertValidationRejected(fn () => $service->markPaid(
            $order->fresh(), $this->verified($order->fresh(), '412345678901', 'TXN1'), $lease
        ));

        // Order must remain in REVIEW, not jump to PAID via the provider path.
        $this->assertSame(Order::REVIEW, $order->fresh()->status);
        Queue::assertNotPushed(SendWebhook::class, fn ($j) => $j->event === 'order.paid');
    }

    // ── 3. Approve UTR: concurrent approve + reject is safe ──────────────────

    public function test_approve_utr_checks_status_inside_lock(): void
    {
        Queue::fake();
        $merchant = $this->manualMerchant();
        $order = $this->order($merchant);

        $this->post(route('pay.utr', $order), ['utr' => '412345678901']);

        // Simulate: reject happens first (changes status to PENDING).
        $service = app(OrderService::class);
        $service->rejectCustomerUtr($order->fresh(), 'Not found');
        $this->assertSame(Order::PENDING, $order->fresh()->status);

        // Now approve arrives late — must fail because status is no longer REVIEW.
        $this->actingAs($merchant)
            ->post(route('orders.approve-utr', $order), ['bank_utr' => '412345678901', 'received_amount' => 100.00])
            ->assertSessionHasErrors('bank_utr');

        $this->assertSame(Order::PENDING, $order->fresh()->status);
    }

    public function test_reject_utr_checks_status_inside_lock(): void
    {
        Queue::fake();
        $merchant = $this->manualMerchant();
        $order = $this->order($merchant);

        $this->post(route('pay.utr', $order), ['utr' => '412345678901']);

        // Matching UTR is rejected and cannot transition the order.
        $service = app(OrderService::class);
        $this->assertValidationRejected(fn () => $service->approveCustomerUtr($order->fresh(), '412345678901'));
        $this->assertSame(Order::REVIEW, $order->fresh()->status);
        $this->actingAs($merchant)->post(route('orders.reject-utr', $order), ['reason' => 'Not received'])
            ->assertSessionHasNoErrors();
        $this->assertSame(Order::PENDING, $order->fresh()->status);
        Queue::assertNotPushed(SendWebhook::class, fn ($job) => $job->event === 'order.paid');
    }

    // ── 4. Duplicate UTR submission is blocked atomically ────────────────────

    public function test_same_utr_on_two_concurrent_submissions_is_blocked(): void
    {
        Queue::fake();
        $merchant = $this->manualMerchant();
        $order1 = $this->order($merchant);
        $order2 = $this->order($merchant);

        // First submission succeeds.
        $this->post(route('pay.utr', $order1), ['utr' => '412345678901']);
        $this->assertSame(Order::REVIEW, $order1->fresh()->status);

        // Second submission with the same UTR must be rejected.
        $this->post(route('pay.utr', $order2), ['utr' => '412345678901'])
            ->assertSessionHasErrors('utr');
        $this->assertSame(Order::PENDING, $order2->fresh()->status);
    }

    // ── 5. Paytm Business: order ID mismatch is rejected ─────────────────────

    public function test_paytm_business_rejects_response_with_wrong_order_id(): void
    {
        Queue::fake();
        $merchant = $this->merchant();
        $order = $this->order($merchant);

        Http::fake(['securegw.paytm.in/*' => Http::response([
            'STATUS' => 'TXN_SUCCESS',
            'TXNAMOUNT' => '100.00',
            'BANKTXNID' => '111111111111',
            'TXNID' => 'T1',
            'ORDERID' => 'DIFFERENT_ORDER_NO', // wrong order
        ])]);

        $result = app(PaytmBusinessVerifier::class)->check($order->load('paymentAccount'));

        $this->assertFalse($result->paid);
        $this->assertSame(Order::PENDING, $order->fresh()->status);
    }

    public function test_paytm_business_accepts_response_with_correct_order_id(): void
    {
        Queue::fake();
        $merchant = $this->merchant();
        $order = $this->order($merchant);

        Http::fake(['securegw.paytm.in/*' => Http::response([
            'STATUS' => 'TXN_SUCCESS',
            'TXNAMOUNT' => '100.00',
            'BANKTXNID' => '111111111111',
            'TXNID' => 'T1',
            'ORDERID' => $order->order_no,
            'TXNDATE' => now()->format('Y-m-d H:i:s'),
        ])]);

        $result = app(PaytmBusinessVerifier::class)->check($order->load('paymentAccount'));

        $this->assertTrue($result->paid);
        $this->assertSame('111111111111', $result->utr);
    }

    // ── 6. Paytm PG: order ID mismatch is rejected ───────────────────────────

    public function test_paytm_pg_rejects_response_with_wrong_order_id(): void
    {
        Queue::fake();
        $merchant = User::create(['name' => 'PG', 'email' => 'pg@example.com', 'password' => 'password123']);
        $merchant->regenerateApiCredentials();
        $merchant->paymentAccounts()->create([
            'provider' => 'paytm_pg',
            'label' => 'Paytm PG',
            'payee_name' => 'PG Store',
            'credentials' => [
                'mid' => 'PGMID',
                'merchant_key' => 'abcd1234EFGH5678',
                'website_name' => 'DEFAULT',
                'environment' => 'production',
            ],
            'is_default' => true,
        ]);
        $order = $this->order($merchant);

        Http::fake(['secure.paytmpayments.com/v3/order/status' => Http::response([
            'body' => [
                'resultInfo' => ['resultStatus' => 'TXN_SUCCESS'],
                'txnId' => 'PTM1',
                'bankTxnId' => '222222222222',
                'txnAmount' => '100.00',
                'orderId' => 'WRONG_ORDER_NO',
            ],
        ])]);

        $result = app(PaytmPgVerifier::class)->check($order->load('paymentAccount'));

        $this->assertFalse($result->paid);
    }

    // ── 7. PhonePe PG: order ID mismatch is rejected ─────────────────────────

    public function test_phonepe_rejects_response_with_wrong_order_id(): void
    {
        Queue::fake();
        $merchant = User::create(['name' => 'PP', 'email' => 'pp@example.com', 'password' => 'password123']);
        $merchant->regenerateApiCredentials();
        $merchant->paymentAccounts()->create([
            'provider' => 'phonepe_pg',
            'label' => 'PhonePe',
            'payee_name' => 'PP Store',
            'credentials' => [
                'client_id' => 'CLIENT1',
                'client_secret' => 'SECRET1',
                'client_version' => '1',
                'environment' => 'production',
            ],
            'is_default' => true,
        ]);
        $order = $this->order($merchant);

        Http::fake([
            '*/v1/oauth/token' => Http::response(['access_token' => 'TOKEN', 'expires_at' => time() + 3600]),
            "*/checkout/v2/order/{$order->order_no}/status*" => Http::response([
                'orderId' => 'PHONEPE_INTERNAL_ID',
                'merchantOrderId' => 'WRONG_ORDER_NO',
                'state' => 'COMPLETED',
                'amount' => 10000,
            ]),
        ]);

        $result = app(PhonePePgVerifier::class)->check($order->load('paymentAccount'));

        $this->assertFalse($result->paid);
    }

    // ── 8. Webhook idempotency: no double-delivery on queue retry ─────────────

    public function test_webhook_is_not_delivered_twice_on_queue_retry(): void
    {
        Http::fake(['merchant.test/*' => Http::response('ok', 200)]);

        $merchant = $this->merchant();
        $merchant->forceFill(['webhook_url' => 'https://merchant.test/hook'])->save();
        $order = $this->order($merchant);

        // First delivery succeeds.
        (new SendWebhook($order, 'order.paid'))->handle();
        $this->assertSame(1, $order->webhookLogs()->where('success', true)->count());

        // Simulate queue retry (worker crashed after delivery, before job deletion).
        (new SendWebhook($order, 'order.paid'))->handle();

        // Only one HTTP request should have been made total.
        Http::assertSentCount(1);
        $this->assertSame(1, $order->webhookLogs()->where('success', true)->count());
    }

    // ── 9. Expired order cannot be re-expired ────────────────────────────────

    public function test_expired_order_is_not_re_expired(): void
    {
        Queue::fake();
        $merchant = $this->merchant();
        $order = $this->order($merchant);

        $service = app(OrderService::class);
        $lease = $this->verificationLease($order);
        $service->expire($order, $lease);
        $this->assertSame(Order::EXPIRED, $order->fresh()->status);

        // Second expire call must be a no-op.
        $service->expire($order->fresh(), $lease);
        app(WebhookOutboxDispatcher::class)->dispatchDue();
        Queue::assertPushed(SendWebhook::class, 1); // only one expired webhook
    }

    // ── 10. Cross-merchant: merchant B cannot approve merchant A's UTR ────────

    public function test_merchant_b_cannot_approve_merchant_a_utr_review(): void
    {
        Queue::fake();
        $merchantA = $this->manualMerchant();
        $order = $this->order($merchantA);

        $this->post(route('pay.utr', $order), ['utr' => '412345678901']);
        $this->assertSame(Order::REVIEW, $order->fresh()->status);

        $merchantB = User::create(['name' => 'B', 'email' => 'b@example.com', 'password' => 'password123']);

        $this->actingAs($merchantB)
            ->post(route('orders.approve-utr', $order), ['bank_utr' => '412345678901'])
            ->assertNotFound();

        $this->assertSame(Order::REVIEW, $order->fresh()->status);
    }

    public function test_admin_override_is_atomic_audited_and_cannot_repeat(): void
    {
        $merchant = $this->manualMerchant();
        $admin = User::create(['name' => 'Admin', 'email' => 'override2@example.com', 'password' => 'password123', 'role' => 'admin']);
        $order = $this->order($merchant);
        $service = app(OrderService::class);

        $service->adminOverridePaid($order, $admin, 'BANKREF123456', 'Independent bank statement reviewed by administrator.', '127.0.0.1', '100.00');

        $this->assertSame(Order::PAID, $order->fresh()->status);
        $this->assertSame('admin_override', $order->fresh()->verified_by);
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_id' => $admin->id,
            'action' => 'order_manual_payment_override',
            'subject_id' => $order->id,
        ]);

        $this->assertValidationRejected(fn () => $service->adminOverridePaid($order->fresh(), $admin, 'BANKREF999999', 'Second independent review attempt must be rejected.', '127.0.0.1', '100.00'));
        $this->assertSame('BANKREF123456', $order->fresh()->utr);
        $this->assertSame(1, AdminAuditLog::where('subject_id', $order->id)->count());
    }

    public function test_admin_override_rejects_locked_order_amount_mismatch(): void
    {
        $merchant = $this->manualMerchant();
        $admin = User::create(['name' => 'Admin', 'email' => 'override3@example.com', 'password' => 'password123', 'role' => 'admin']);
        $order = $this->order($merchant);

        Queue::fake();
        $this->assertValidationRejected(fn () => app(OrderService::class)->adminOverridePaid($order, $admin, 'BANKREF333333', 'Independent bank statement reviewed by administrator.', '127.0.0.1', '99.00'));
        $this->assertSame(Order::PENDING, $order->fresh()->status);
        $this->assertDatabaseMissing('admin_audit_logs', ['subject_id' => $order->id]);
        Queue::assertNothingPushed();
    }

    public function test_admin_audit_record_survives_admin_deletion(): void
    {
        $merchant = $this->manualMerchant();
        $admin = User::create(['name' => 'Admin', 'email' => 'override4@example.com', 'password' => 'password123', 'role' => 'admin']);
        $order = $this->order($merchant);

        app(OrderService::class)->adminOverridePaid($order, $admin, 'BANKREF444444', 'Independent bank statement reviewed by administrator.', '127.0.0.1', '100.00');
        $auditId = AdminAuditLog::where('subject_id', $order->id)->value('id');
        $admin->delete();

        $this->assertDatabaseHas('admin_audit_logs', ['id' => $auditId, 'subject_id' => $order->id]);
        $this->assertNull(AdminAuditLog::findOrFail($auditId)->admin_id);
        $this->assertSame($admin->id, AdminAuditLog::findOrFail($auditId)->actor_admin_id);
    }
}
