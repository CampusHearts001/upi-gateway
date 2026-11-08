<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Models\WebhookOutbox;
use App\Services\OrderService;
use App\Services\Verifiers\PaytmBusinessVerifier;
use App\Services\Verifiers\VerificationResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

class LatePaymentEvidenceBindingTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    private PaymentAccount $account;

    private OrderService $orderService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orderService = app(OrderService::class);
        $this->merchant = User::create([
            'name' => 'Evidence Binding Merchant',
            'email' => 'evidence@example.com',
            'password' => 'password12345',
        ]);
        $this->merchant->regenerateApiCredentials();
        $this->account = $this->merchant->paymentAccounts()->create([
            'provider' => 'paytm_business',
            'label' => 'Binding Test Account',
            'payee_name' => 'Binding Payee',
            'upi_id' => 'binding@upi',
            'credentials' => ['mid' => 'TESTMID'],
        ]);
    }

    public function test_valid_payment_just_before_expiry_is_accepted(): void
    {
        $order = $this->createOrder();
        $this->travel(14)->minutes();
        $lease = $this->verificationLease($order);

        // Payment completed 1 minute before original expiry
        $occurredAt = CarbonImmutable::now();

        $result = VerificationResult::fromPaytmBusiness(
            order: $order,
            amount: 100,
            utr: '123456789012',
            txnId: 'TXN-JUST-BEFORE-EXPIRY',
            raw: ['TXNDATE' => $occurredAt->setTimezone('Asia/Kolkata')->format('Y-m-d H:i:s.0')],
            occurredAt: $occurredAt,
        );

        $this->orderService->markPaid($order, $result, $lease);

        $fresh = $order->fresh();
        $this->assertSame(Order::PAID, $fresh->status);
        $this->assertSame('TXN-JUST-BEFORE-EXPIRY', $fresh->provider_txn_id);
        $this->assertSame('123456789012', $fresh->utr);
        $this->assertSame($occurredAt->timestamp, $fresh->paid_at->timestamp);
        $this->assertSame(1, WebhookOutbox::where('order_id', $order->id)->where('event', 'order.paid')->count());
    }

    public function test_valid_payment_inside_the_configured_late_payment_grace_period_is_accepted(): void
    {
        $order = $this->createOrder();
        // Simulate order expired 2 hours ago
        $order->forceFill([
            'status' => Order::EXPIRED,
            'created_at' => now()->subHours(3),
            'expires_at' => now()->subHours(2),
            'original_expires_at' => now()->subHours(2),
        ])->save();

        $lease = $this->verificationLease($order);

        // Payment completed 30 minutes after original expiry (well within 24h grace)
        $occurredAt = CarbonImmutable::instance($order->original_expires_at)->addMinutes(30);

        $result = VerificationResult::fromPaytmBusiness(
            order: $order,
            amount: 100,
            utr: '123456789012',
            txnId: 'TXN-LATE-GRACE-ACCEPTED',
            raw: ['TXNDATE' => $occurredAt->setTimezone('Asia/Kolkata')->format('Y-m-d H:i:s.0')],
            occurredAt: $occurredAt,
        );

        $this->orderService->markPaid($order, $result, $lease);

        $fresh = $order->fresh();
        $this->assertSame(Order::PAID, $fresh->status);
        $this->assertSame('TXN-LATE-GRACE-ACCEPTED', $fresh->provider_txn_id);
        $this->assertSame(1, WebhookOutbox::where('order_id', $order->id)->where('event', 'order.paid')->count());
    }

    public function test_evidence_before_the_original_valid_window_is_rejected(): void
    {
        $order = $this->createOrder();
        $lease = $this->verificationLease($order);

        // Payment claims to have occurred 1 hour before order was created
        $occurredAt = CarbonImmutable::instance($order->created_at)->subHour();

        $result = VerificationResult::fromPaytmBusiness(
            order: $order,
            amount: 100,
            utr: '123456789012',
            txnId: 'TXN-BEFORE-CREATION',
            raw: ['TXNDATE' => $occurredAt->setTimezone('Asia/Kolkata')->format('Y-m-d H:i:s.0')],
            occurredAt: $occurredAt,
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Provider payment was completed before the order was created.');

        try {
            $this->orderService->markPaid($order, $result, $lease);
        } finally {
            $this->assertSame(Order::PENDING, $order->fresh()->status);
            $this->assertSame(0, WebhookOutbox::where('order_id', $order->id)->count());
        }
    }

    public function test_evidence_older_than_maximum_allowed_age_is_rejected(): void
    {
        config(['gateway.evidence_max_age_seconds' => 3600]); // 1 hour max age
        $order = $this->createOrder();
        $lease = $this->verificationLease($order);

        // Evidence occurred 2 hours ago
        $occurredAt = CarbonImmutable::now()->subHours(2);

        $result = VerificationResult::fromPaytmBusiness(
            order: $order,
            amount: 100,
            utr: '123456789012',
            txnId: 'TXN-EXCEEDS-MAX-AGE',
            raw: ['TXNDATE' => $occurredAt->setTimezone('Asia/Kolkata')->format('Y-m-d H:i:s.0')],
            occurredAt: $occurredAt,
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Provider payment evidence exceeds maximum allowed age.');

        try {
            $this->orderService->markPaid($order, $result, $lease);
        } finally {
            $this->assertSame(Order::PENDING, $order->fresh()->status);
            $this->assertSame(0, WebhookOutbox::where('order_id', $order->id)->count());
        }
    }

    public function test_evidence_after_order_expiry_extension_cannot_validate_stale_payment(): void
    {
        $order = $this->createOrder();
        // Simulate order was created 35 hours ago, with original expiry at 34 hours ago
        // And an admin/operator extended expires_at to 10 hours in the future
        $order->forceFill([
            'created_at' => now()->subHours(35),
            'original_expires_at' => now()->subHours(34),
            'expires_at' => now()->addHours(10),
        ])->save();

        $lease = $this->verificationLease($order);

        // Payment occurred 5 hours ago (which is 29 hours after original expiry, outside the 24h grace window)
        $occurredAt = CarbonImmutable::now()->subHours(5);

        $result = VerificationResult::fromPaytmBusiness(
            order: $order,
            amount: 100,
            utr: '123456789012',
            txnId: 'TXN-AFTER-GRACE-EXTENSION',
            raw: ['TXNDATE' => $occurredAt->setTimezone('Asia/Kolkata')->format('Y-m-d H:i:s.0')],
            occurredAt: $occurredAt,
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Provider payment evidence was completed after the late-payment grace period.');

        try {
            $this->orderService->markPaid($order, $result, $lease);
        } finally {
            $this->assertSame(Order::PENDING, $order->fresh()->status);
            $this->assertSame(0, WebhookOutbox::where('order_id', $order->id)->count());
        }
    }

    public function test_missing_provider_timestamp_is_rejected(): void
    {
        $order = $this->createOrder();
        $lease = $this->verificationLease($order);

        // Raw provider response has no timestamp field
        $result = VerificationResult::fromPaytmBusiness(
            order: $order,
            amount: 100,
            utr: '123456789012',
            txnId: 'TXN-NO-TIMESTAMP',
            raw: ['STATUS' => 'TXN_SUCCESS', 'ORDERID' => $order->order_no, 'TXNAMOUNT' => '100.00'],
            occurredAt: null,
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Provider evidence is missing a valid transaction timestamp.');

        try {
            $this->orderService->markPaid($order, $result, $lease);
        } finally {
            $this->assertSame(Order::PENDING, $order->fresh()->status);
            $this->assertSame(0, WebhookOutbox::where('order_id', $order->id)->count());
        }
    }

    public function test_invalid_provider_timestamp_is_rejected(): void
    {
        $order = $this->createOrder();
        $lease = $this->verificationLease($order);

        // Raw provider response has an invalid timestamp string
        $result = VerificationResult::fromPaytmBusiness(
            order: $order,
            amount: 100,
            utr: '123456789012',
            txnId: 'TXN-INVALID-TIMESTAMP',
            raw: ['STATUS' => 'TXN_SUCCESS', 'ORDERID' => $order->order_no, 'TXNAMOUNT' => '100.00', 'TXNDATE' => 'not-a-valid-date'],
            occurredAt: null,
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Provider evidence is missing a valid transaction timestamp.');

        try {
            $this->orderService->markPaid($order, $result, $lease);
        } finally {
            $this->assertSame(Order::PENDING, $order->fresh()->status);
            $this->assertSame(0, WebhookOutbox::where('order_id', $order->id)->count());
        }
    }

    public static function mismatchedEvidenceScenarios(): array
    {
        return [
            'wrong order ID' => ['orderId', 999999],
            'wrong order number' => ['orderNumber', 'ORDWRONG12345'],
            'wrong amount' => ['amount', '105.00'],
            'wrong currency' => ['currency', 'USD'],
            'wrong provider' => ['provider', 'phonepe_pg'],
            'wrong payment account' => ['paymentAccountId', 99999],
            'wrong environment' => ['environment', 'sandbox'],
            'missing references' => ['references', null],
            'wrong original expiry deadline' => ['originalExpiresAt', null],
        ];
    }

    #[DataProvider('mismatchedEvidenceScenarios')]
    public function test_mismatched_evidence_is_rejected(string $field, mixed $override): void
    {
        $order = $this->createOrder();
        $lease = $this->verificationLease($order);
        $occurredAt = CarbonImmutable::now();

        $ref = new ReflectionClass(VerificationResult::class);
        $instance = $ref->newInstanceWithoutConstructor();

        $defaultParams = [
            'paid' => true,
            'utr' => '123456789012',
            'txnId' => 'TXN-TEST',
            'raw' => ['TXNDATE' => $occurredAt->format('Y-m-d H:i:s')],
            'provider' => 'paytm_business',
            'orderId' => $order->id,
            'orderNumber' => $order->order_no,
            'amount' => '100.00',
            'currency' => 'INR',
            'verifiedAt' => CarbonImmutable::now(),
            'paymentAccountId' => $this->account->id,
            'environment' => Order::LIVE,
            'originalExpiresAt' => $order->originalExpiryDeadline(),
            'occurredAt' => $occurredAt,
        ];

        if ($field === 'references') {
            $defaultParams['utr'] = null;
            $defaultParams['txnId'] = null;
        } elseif ($field === 'originalExpiresAt') {
            $defaultParams['originalExpiresAt'] = CarbonImmutable::now()->addHours(5);
        } else {
            $defaultParams[$field] = $override;
        }

        foreach ($defaultParams as $propName => $propValue) {
            $prop = $ref->getProperty($propName);
            $prop->setValue($instance, $propValue);
        }

        $this->expectException(ValidationException::class);
        $this->orderService->markPaid($order, $instance, $lease);
    }

    public function test_duplicate_evidence_and_repeated_callbacks_are_idempotent(): void
    {
        $order = $this->createOrder();
        $lease = $this->verificationLease($order);
        $occurredAt = CarbonImmutable::now();

        $result = VerificationResult::fromPaytmBusiness(
            order: $order,
            amount: 100,
            utr: '123456789012',
            txnId: 'TXN-IDEMPOTENT',
            raw: ['TXNDATE' => $occurredAt->format('Y-m-d H:i:s')],
            occurredAt: $occurredAt,
        );

        // First transition marks PAID
        $this->orderService->markPaid($order, $result, $lease);
        $this->assertSame(Order::PAID, $order->fresh()->status);
        $this->assertSame(1, WebhookOutbox::where('order_id', $order->id)->where('event', 'order.paid')->count());

        // Repeated callback with the same evidence returns cleanly without duplicate outbox events
        $secondResult = $this->orderService->markPaid($order->fresh(), $result, $lease);
        $this->assertSame(Order::PAID, $secondResult->status);
        $this->assertSame(1, WebhookOutbox::where('order_id', $order->id)->where('event', 'order.paid')->count());
    }

    public function test_transactional_webhook_outbox_remains_exactly_once_per_payment_transition(): void
    {
        $order = $this->createOrder();
        $lease = $this->verificationLease($order);
        $occurredAt = CarbonImmutable::now();

        $result = VerificationResult::fromPaytmBusiness(
            order: $order,
            amount: 100,
            utr: '123456789012',
            txnId: 'TXN-OUTBOX-EXACTLY-ONCE',
            raw: ['TXNDATE' => $occurredAt->format('Y-m-d H:i:s')],
            occurredAt: $occurredAt,
        );

        $this->orderService->markPaid($order, $result, $lease);

        $this->assertSame(Order::PAID, $order->fresh()->status);
        $this->assertSame(1, WebhookOutbox::where('order_id', $order->id)->count());
        $this->assertSame('order.paid', WebhookOutbox::where('order_id', $order->id)->value('event'));
    }

    public function test_paytm_business_verifier_parses_ist_timezone_accurately(): void
    {
        $order = $this->createOrder();
        // Paytm sends timestamp in IST: 2026-11-07 20:30:00 (which is 15:00:00 UTC)
        Http::fake([
            config('gateway.paytm_status_url').'*' => Http::response([
                'STATUS' => 'TXN_SUCCESS',
                'ORDERID' => $order->order_no,
                'TXNAMOUNT' => '100.00',
                'CURRENCY' => 'INR',
                'BANKTXNID' => '123456789012',
                'TXNID' => 'TXN-IST-TEST',
                'TXNDATE' => '2026-11-07 20:30:00.0',
            ]),
        ]);

        $result = app(PaytmBusinessVerifier::class)->check($order->load('paymentAccount'));
        $this->assertTrue($result->paid);
        $this->assertNotNull($result->occurredAt);
        // 20:30 IST is 15:00 UTC
        $this->assertSame('2026-11-07T15:00:00+00:00', $result->occurredAt->toIso8601String());
    }

    private function createOrder(): Order
    {
        return $this->orderService->create($this->merchant, [
            'amount' => '100.00',
            'payment_account_id' => $this->account->id,
        ]);
    }
}
