<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Services\Verifiers\VerificationResult;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private readonly VerificationProcessInspector $processInspector,
        private readonly VerificationNodeRegistry $nodeRegistry,
    ) {}

    /**
     * @param  array{amount: numeric, client_order_id?: string, payment_account_id?: int, customer_name?: string, customer_email?: string, customer_mobile?: string, note?: string, redirect_url?: string}  $data
     */
    public function create(User $merchant, array $data, string $environment = Order::LIVE): Order
    {
        if ($environment === Order::SANDBOX) {
            return $this->createSandbox($merchant, $data);
        }

        if ($environment !== Order::LIVE) {
            throw ValidationException::withMessages(['environment' => 'Invalid payment environment.']);
        }

        // Serialize this merchant's order creation, including the case where
        // no account exists yet, so daily-limit totals remain consistent.
        return DB::transaction(function () use ($merchant, $data) {
            User::whereKey($merchant->id)->lockForUpdate()->firstOrFail();

            $account = $this->resolveAccount($merchant, (float) $data['amount'], $data['payment_account_id'] ?? null);
            $account = PaymentAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();

            return $this->createFor($merchant, $account, $data, Order::LIVE);
        });
    }

    private function createSandbox(User $merchant, array $data): Order
    {
        if (isset($data['payment_account_id'])) {
            throw ValidationException::withMessages([
                'payment_account_id' => 'Sandbox simulations do not use provider payment accounts.',
            ]);
        }

        return DB::transaction(function () use ($merchant, $data) {
            User::whereKey($merchant->id)->lockForUpdate()->firstOrFail();

            return $this->createFor($merchant, null, $data, Order::SANDBOX);
        });
    }

    private function createFor(User $merchant, ?PaymentAccount $account, array $data, string $environment): Order
    {
        $orderNo = ($environment === Order::SANDBOX ? 'TST' : 'ORD').strtoupper(Str::random(16));

        $expiresAt = now()->addMinutes((int) config('gateway.order_expiry_minutes', 15));

        $order = new Order([
            'order_no' => $orderNo,
            'payment_account_id' => $account?->id,
            'client_order_id' => $data['client_order_id'] ?? $orderNo,
            'amount' => $data['amount'],
            'status' => Order::PENDING,
            'customer_name' => $data['customer_name'] ?? null,
            'customer_email' => $data['customer_email'] ?? null,
            'customer_mobile' => $data['customer_mobile'] ?? null,
            'note' => $data['note'] ?? null,
            'redirect_url' => $data['redirect_url'] ?? null,
            'expires_at' => $expiresAt,
            'original_expires_at' => $expiresAt,
        ]);
        $order->forceFill(['environment' => $environment]);
        $order->user()->associate($merchant);
        $account?->assertCompatibleWithOrder($order);
        $order->save();

        return $order;
    }

    /**
     * Ask the provider for the latest status, at most once per
     * `check_interval_seconds` per order, however many tabs are polling.
     */
    public function check(Order $order, bool $force = false): Order
    {
        // A short cache lease spans HTTP without holding SQL row locks.
        $lock = Cache::lock('order-verification:'.$order->id, (int) config('gateway.verification_lease_seconds', 120));
        if (! $lock->get()) {
            return $order->refresh();
        }

        try {
            // The database lease remains authoritative after the cache lock
            // expires. Takeover requires confirmed termination of the exact
            // same-node process identity; unknown liveness fails closed.
            $lease = $this->acquireVerificationLease($order);
            if (! $lease) {
                return $order->refresh();
            }

            try {
                return $this->checkWithoutOverlap($order, $force, $lease);
            } finally {
                $this->releaseVerificationLease($order, $lease);
            }
        } finally {
            $lock->release();
        }
    }

    private function checkWithoutOverlap(Order $order, bool $force, VerificationLease $lease): Order
    {
        $order->refresh();
        if ($order->status === Order::EXPIRED) {
            return $this->checkLatePayment($order, $lease);
        }
        if (! $order->isPending()) {
            return $order;
        }

        // Orders created before the expiry compatibility fix may legitimately
        // have a NULL expiry. Give them a fresh local expiry before any
        // provider verifier or scheduler code dereferences the timestamp.
        if ($order->expires_at === null) {
            return $this->repairNullExpiry($order, $lease);
        }

        if ($order->environment === Order::SANDBOX) {
            if ($order->payment_account_id !== null) {
                $this->validateAccountForCheck($order);
            }

            return $order->isPastExpiry() ? $this->expire($order, $lease) : $order->refresh();
        }

        $this->validateAccountForCheck($order);

        // A scheduler outage must not allow an old PENDING row to bypass the
        // recovery deadline. Expire it without making a provider request.
        if ($order->isPastExpiry() && ! $order->isWithinLatePaymentGrace()) {
            return $this->expire($order, $lease);
        }

        // Lock ownership & generation check before updating verification timestamps / throttles
        $claimed = Order::whereKey($order->id)
            ->where('verification_token', $lease->token)
            ->where('verification_generation', $lease->generation)
            ->where('verification_node_id', $lease->nodeId)
            ->where('verification_node_instance_id', $lease->nodeInstanceId)
            ->where('verification_pid', $lease->pid)
            ->where('verification_process_identity', $lease->processIdentity)
            ->where('status', Order::PENDING)
            ->when(! $force, fn ($q) => $q->where(fn ($q) => $q->whereNull('last_checked_at')
                ->orWhere('last_checked_at', '<=', now()->subSeconds(config('gateway.check_interval_seconds')))))
            ->update(['last_checked_at' => now()]);

        // MySQL reports zero affected rows when a forced recheck occurs within
        // the same second and writes the same timestamp value. A forced check
        // intentionally bypasses the interval, so verify the state separately.
        if ($force && ! $claimed) {
            $claimed = Order::whereKey($order->id)
                ->where('verification_token', $lease->token)
                ->where('verification_generation', $lease->generation)
                ->where('verification_node_id', $lease->nodeId)
                ->where('verification_node_instance_id', $lease->nodeInstanceId)
                ->where('verification_pid', $lease->pid)
                ->where('verification_process_identity', $lease->processIdentity)
                ->where('status', Order::PENDING)
                ->exists();
        }

        if ($claimed) {
            // Keep provider HTTP calls outside SQL row locks
            $result = $order->paymentAccount->verifier()->check($order);

            if ($result->paid) {
                return $this->markPaid($order, $result, $lease);
            }
        }

        if ($order->isPastExpiry()) {
            return $this->expire($order, $lease);
        }

        return $order->refresh();
    }

    protected function repairNullExpiry(Order $order, VerificationLease $lease): Order
    {
        $duration = (int) config('gateway.order_expiry_minutes', 15);
        $repairedExpiry = now()->addMinutes($duration);

        Order::whereKey($order->id)
            ->where('status', Order::PENDING)
            ->whereNull('expires_at')
            ->where('verification_token', $lease->token)
            ->where('verification_generation', $lease->generation)
            ->where('verification_node_id', $lease->nodeId)
            ->where('verification_node_instance_id', $lease->nodeInstanceId)
            ->where('verification_pid', $lease->pid)
            ->where('verification_process_identity', $lease->processIdentity)
            ->update([
                'expires_at' => $repairedExpiry,
                'original_expires_at' => DB::raw('COALESCE(original_expires_at, '.DB::getPdo()->quote($repairedExpiry->toDateTimeString()).')'),
            ]);

        return $order->refresh();
    }

    private function checkLatePayment(Order $order, VerificationLease $lease): Order
    {
        if ($order->environment !== Order::LIVE || ! $order->isWithinLatePaymentGrace()) {
            return $order;
        }
        $order->load('paymentAccount');
        if ($order->paymentAccount && ! $order->paymentAccount->supportsStatusVerification()) {
            return $order;
        }

        // Atomic throttle applies to polling, scheduler and forced callbacks.
        // Lock ownership & generation check before updating verification timestamps / throttles
        $claimed = Order::whereKey($order->id)->where('status', Order::EXPIRED)
            ->where('verification_token', $lease->token)
            ->where('verification_generation', $lease->generation)
            ->where('verification_node_id', $lease->nodeId)
            ->where('verification_node_instance_id', $lease->nodeInstanceId)
            ->where('verification_pid', $lease->pid)
            ->where('verification_process_identity', $lease->processIdentity)
            ->where('environment', Order::LIVE)
            ->where('expires_at', '<=', now())
            ->where('expires_at', '>=', now()->subHours(max(0, (int) config('gateway.late_payment_grace_hours', 24))))
            ->where(fn ($q) => $q->whereNull('last_checked_at')
                ->orWhere('last_checked_at', '<=', now()->subSeconds(max(120, (int) config('gateway.late_payment_check_interval_seconds', 300)))))
            ->update(['last_checked_at' => now()]);
        if (! $claimed) {
            return $order->refresh();
        }

        try {
            $order->refresh();
            $this->validateAccountForCheck($order);
            if (! $order->canRecoverLatePayment()) {
                return $order;
            }
            // Keep provider HTTP calls outside SQL row locks
            $result = $order->paymentAccount->verifier()->check($order);
            $order = $result->paid ? $this->markPaid($order, $result, $lease) : $order->refresh();
            Log::info('Late payment status checked', $this->verificationLogContext($order) + [
                'outcome' => $order->status === Order::PAID ? 'paid' : 'not_confirmed',
            ]);

            return $order;
        } catch (ValidationException $e) {
            Log::warning('Late payment verification rejected', $this->verificationLogContext($order) + [
                'validation_fields' => array_keys($e->errors()),
            ]);
            throw $e;
        }
    }

    public function verificationLogContext(Order $order): array
    {
        return [
            'order_id' => $order->id,
            'payment_account_id' => $order->payment_account_id,
            'order_environment' => $order->environment,
            'provider' => $order->paymentAccount?->provider,
            'account_environment' => $order->paymentAccount?->paymentEnvironment(),
            'expires_at' => $order->expires_at?->toIso8601String(),
        ];
    }

    public function markPaid(Order $order, VerificationResult $verification, ?VerificationLease $lease = null): Order
    {
        if ($lease === null) {
            throw ValidationException::withMessages(['order' => 'A current provider verification lease is required.']);
        }
        if (! $verification->paid) {
            throw ValidationException::withMessages(['order' => 'Only a successful provider verification can mark an order paid.']);
        }

        $utr = $verification->utr;
        $txnId = $verification->txnId;
        $raw = $verification->raw;
        $provider = $verification->provider;
        $verifiedAmount = $verification->amount;

        try {
            DB::transaction(function () use ($order, $verification, $utr, $txnId, $raw, $provider, $verifiedAmount, $lease) {
                User::whereKey($order->user_id)->lockForUpdate()->firstOrFail();
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $account = $this->lockCompatibleAccount($locked);

                if (! $this->ownsVerificationLease($locked, $lease)) {
                    $this->logStaleVerificationOwner($locked, $lease, 'payment commit');

                    return false;
                }

                if (! in_array($provider, ['paytm_business', 'paytm_pg', 'phonepe_pg'], true)
                    || ($provider === 'paytm_business' && ! (bool) config('gateway.enable_paytm_business', true))
                    || $account->provider !== $provider
                    || $verification->paymentAccountId !== $account->id
                    || $verification->environment !== Order::LIVE
                    || $verification->environment !== $account->paymentEnvironment()
                    || $verification->orderId !== $locked->id
                    || $verification->orderNumber !== $locked->order_no
                    || $verification->currency !== 'INR'
                    || $verification->verifiedAt === null
                    || $verification->verifiedAt->isFuture()
                    || (trim($utr ?? '') === '' && trim($txnId ?? '') === '')
                    || $verifiedAmount !== (string) $locked->amount) {
                    throw ValidationException::withMessages(['order' => 'Provider verification does not match this order.']);
                }

                $lockedOriginalDeadline = $locked->originalExpiryDeadline();
                if ($lockedOriginalDeadline === null
                    || $verification->originalExpiresAt === null
                    || $verification->originalExpiresAt->timestamp !== $lockedOriginalDeadline->timestamp) {
                    throw ValidationException::withMessages(['order' => 'Provider evidence is not bound to this order original expiry deadline.']);
                }

                $requireTimestamp = (bool) config('gateway.evidence_require_timestamp', true);
                if ($requireTimestamp && $verification->occurredAt === null) {
                    throw ValidationException::withMessages(['order' => 'Provider evidence is missing a valid transaction timestamp.']);
                }

                $skewSeconds = max(0, (int) config('gateway.evidence_clock_skew_seconds', 300));
                if ($verification->occurredAt !== null && $verification->occurredAt->isAfter(now()->addSeconds($skewSeconds))) {
                    throw ValidationException::withMessages(['order' => 'Provider transaction timestamp is in the future.']);
                }

                $maxAgeSeconds = max(60, (int) config('gateway.evidence_max_age_seconds', 86400));
                if ($verification->occurredAt !== null && $verification->occurredAt->isBefore(now()->subSeconds($maxAgeSeconds))) {
                    throw ValidationException::withMessages(['order' => 'Provider payment evidence exceeds maximum allowed age.']);
                }

                $windowStart = $locked->created_at ? $locked->created_at->subSeconds($skewSeconds) : now()->subSeconds($skewSeconds);
                if ($verification->occurredAt !== null && $verification->occurredAt->isBefore($windowStart)) {
                    throw ValidationException::withMessages(['order' => 'Provider payment was completed before the order was created.']);
                }

                $graceHours = max(0, (int) config('gateway.late_payment_grace_hours', 24));
                $latePaymentDeadline = $lockedOriginalDeadline->addHours($graceHours)->addSeconds($skewSeconds);
                if ($verification->occurredAt !== null && $verification->occurredAt->isAfter($latePaymentDeadline)) {
                    throw ValidationException::withMessages(['order' => 'Provider payment evidence was completed after the late-payment grace period.']);
                }

                if ($locked->environment !== Order::LIVE) {
                    throw ValidationException::withMessages(['order' => 'Sandbox orders cannot be paid through live payment handlers.']);
                }

                if ($locked->status === Order::PAID) {
                    return false;
                }

                // Recheck the deadline under lock: it may have passed while
                // waiting for HTTP or another payment transition. NULL/future
                // expiry cannot authorize recovery of an EXPIRED order.
                if (($locked->status === Order::EXPIRED || $locked->isPastExpiry())
                    && ! $locked->isWithinLatePaymentGrace()) {
                    throw ValidationException::withMessages(['order' => 'The automatic late-payment recovery period has ended.']);
                }

                if (! in_array($locked->status, [Order::PENDING, Order::EXPIRED], true)) {
                    return false;
                }

                if ($utr !== null) {
                    $this->ensureUtrUnused($locked, $utr);
                }

                $paidAt = $verification->occurredAt ? Carbon::instance($verification->occurredAt) : now();

                $locked->forceFill([
                    'status' => Order::PAID,
                    'utr' => $utr,
                    'provider_txn_id' => $txnId,
                    'provider_response' => $raw ?: null,
                    'verified_by' => 'provider',
                    'paid_at' => $paidAt,
                    'review_note' => null,
                    'verification_token' => null,
                    'verification_expires_at' => null,
                    'verification_pid' => null,
                    'verification_node_id' => null,
                    'verification_node_instance_id' => null,
                    'verification_process_identity' => null,
                ])->save();

                app(WebhookOutboxService::class)->record($locked, 'order.paid');

                return true;
            });
        } catch (UniqueConstraintViolationException $e) {
            if (! $this->isUtrUniqueConstraintViolation($e)) {
                throw $e;
            }

            // Two requests raced to use the same UTR; the unique index caught it.
            throw ValidationException::withMessages(['utr' => 'This UTR is already used on another order.']);
        }

        $order->refresh();

        return $order;
    }

    public function adminOverridePaid(Order $order, User $admin, string $reference, string $reason, string $ip, string $submittedAmount): Order
    {
        try {
            DB::transaction(function () use ($order, $admin, $reference, $reason, $ip, $submittedAmount) {
                User::whereKey($order->user_id)->lockForUpdate()->firstOrFail();
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $this->lockCompatibleAccount($locked);

                if ($locked->environment !== Order::LIVE) {
                    throw ValidationException::withMessages(['order' => 'Only Live orders can receive an administrator override.']);
                }
                if ($locked->status === Order::PAID) {
                    throw ValidationException::withMessages(['order' => 'Order is already paid.']);
                }
                if (number_format((float) $submittedAmount, 2, '.', '') !== number_format((float) $locked->amount, 2, '.', '')) {
                    throw ValidationException::withMessages(['amount' => 'The confirmed amount must match the order amount exactly.']);
                }

                $this->ensureUtrUnused($locked, $reference);
                $locked->forceFill([
                    'status' => Order::PAID,
                    'utr' => $reference,
                    'provider_txn_id' => $reference,
                    'verified_by' => 'admin_override',
                    'paid_at' => now(),
                    'review_note' => null,
                ])->save();

                AdminAuditLog::create([
                    'admin_id' => $admin->id,
                    'action' => 'order_manual_payment_override',
                    'subject_type' => 'order',
                    'subject_id' => $locked->id,
                    'meta' => [
                        'order_no' => $locked->order_no,
                        'merchant_id' => $locked->user_id,
                        'amount' => (string) $locked->amount,
                        'verification_reference' => $reference,
                        'reason' => $reason,
                    ],
                    'ip' => $ip,
                ]);

                app(WebhookOutboxService::class)->record($locked, 'order.paid');
            });
        } catch (UniqueConstraintViolationException $e) {
            if (! $this->isUtrUniqueConstraintViolation($e)) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'verification_reference' => 'This verification reference is already used on another order.',
            ]);
        }

        return $order->refresh();
    }

    public function expire(Order $order, ?VerificationLease $lease = null): Order
    {
        if ($lease === null) {
            throw ValidationException::withMessages(['order' => 'A current provider verification lease is required.']);
        }

        DB::transaction(function () use ($order, $lease) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== Order::PENDING) {
                return;
            }

            if (! $this->ownsVerificationLease($locked, $lease)) {
                $this->logStaleVerificationOwner($locked, $lease, 'expiry commit');

                return;
            }

            $locked->forceFill([
                'status' => Order::EXPIRED,
                'verification_token' => null,
                'verification_expires_at' => null,
                'verification_pid' => null,
                'verification_node_id' => null,
                'verification_node_instance_id' => null,
                'verification_process_identity' => null,
            ])->save();
            app(WebhookOutboxService::class)->record($locked, 'order.expired');
        });

        return $order->refresh();
    }

    public function acquireVerificationLease(Order $order): ?VerificationLease
    {
        $leaseSeconds = (int) config('gateway.verification_lease_seconds', 120);
        $node = $this->nodeRegistry->registerCurrentNode();

        return DB::transaction(function () use ($order, $leaseSeconds, $node) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            if (! $locked) {
                return null;
            }

            if ($locked->status !== Order::PENDING && $locked->status !== Order::EXPIRED) {
                return null;
            }

            if ($locked->verification_token !== null) {
                if ($locked->verification_expires_at && $locked->verification_expires_at->isFuture()) {
                    return null;
                }

                $liveness = $this->processInspector->status(
                    $locked->verification_node_id,
                    $locked->verification_node_instance_id,
                    $locked->verification_pid,
                    $locked->verification_process_identity,
                );
                if ($liveness !== VerificationProcessInspector::DEAD) {
                    Log::warning('Expired provider verification lease was not taken over', [
                        'order_id' => $locked->id,
                        'verification_generation' => $locked->verification_generation,
                        'owner_node_id' => $locked->verification_node_id,
                        'liveness' => $liveness,
                    ]);

                    return null;
                }
            }

            $token = (string) Str::uuid();
            $generation = ((int) $locked->verification_generation) + 1;
            $pid = getmypid() ?: 0;
            $nodeId = $node['node_id'];
            $nodeInstanceId = $node['instance_id'];
            $processIdentity = $this->processInspector->currentProcessIdentity()
                ?? 'unknown:'.hash('sha256', $token);
            if ($pid <= 0 || $nodeId === '') {
                Log::error('Provider verification lease could not establish a process identity', [
                    'order_id' => $locked->id,
                ]);

                return null;
            }

            $locked->forceFill([
                'verification_token' => $token,
                'verification_generation' => $generation,
                'verification_started_at' => now(),
                'verification_expires_at' => now()->addSeconds($leaseSeconds),
                'verification_pid' => $pid,
                'verification_node_id' => $nodeId,
                'verification_node_instance_id' => $nodeInstanceId,
                'verification_process_identity' => $processIdentity,
            ])->save();

            return new VerificationLease($locked->id, $token, $generation, $nodeId, $nodeInstanceId, $pid, $processIdentity);
        });
    }

    public function releaseVerificationLease(Order $order, ?VerificationLease $lease): void
    {
        if ($lease === null) {
            return;
        }

        Order::whereKey($order->id)
            ->where('verification_token', $lease->token)
            ->where('verification_generation', $lease->generation)
            ->where('verification_node_id', $lease->nodeId)
            ->where('verification_node_instance_id', $lease->nodeInstanceId)
            ->where('verification_pid', $lease->pid)
            ->where('verification_process_identity', $lease->processIdentity)
            ->update([
                'verification_token' => null,
                'verification_expires_at' => null,
                'verification_pid' => null,
                'verification_node_id' => null,
                'verification_node_instance_id' => null,
                'verification_process_identity' => null,
            ]);
    }

    private function ownsVerificationLease(Order $order, VerificationLease $lease): bool
    {
        return $order->id === $lease->orderId
            && hash_equals((string) $order->verification_token, $lease->token)
            && (int) $order->verification_generation === $lease->generation
            && hash_equals((string) $order->verification_node_id, $lease->nodeId)
            && hash_equals((string) $order->verification_node_instance_id, $lease->nodeInstanceId)
            && (int) $order->verification_pid === $lease->pid
            && hash_equals((string) $order->verification_process_identity, $lease->processIdentity);
    }

    private function logStaleVerificationOwner(Order $order, VerificationLease $lease, string $operation): void
    {
        Log::warning('Stale verification owner rejected during '.$operation, [
            'order_id' => $order->id,
            'current_generation' => $order->verification_generation,
            'attempted_generation' => $lease->generation,
            'current_node_id' => $order->verification_node_id,
            'attempted_node_id' => $lease->nodeId,
        ]);
    }

    /**
     * Customer says they paid and gives the UTR. The order goes to review;
     * it is never marked paid until the merchant confirms the same UTR.
     */
    public function submitCustomerUtr(Order $order, string $utr): Order
    {
        $utr = $this->normalizeUtr($utr);

        DB::transaction(function () use ($order, $utr) {
            User::whereKey($order->user_id)->lockForUpdate()->firstOrFail();
            // Lock the row so concurrent submissions cannot both pass the
            // acceptsCustomerUtr / ensureUtrUnused checks simultaneously.
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();

            if (! $locked->load('paymentAccount')->acceptsCustomerUtr()) {
                throw ValidationException::withMessages(['utr' => 'UTR can no longer be submitted for this payment. Please contact the merchant.']);
            }

            $this->ensureUtrUnused($locked, $utr);

            $updated = Order::whereKey($locked->id)
                ->whereIn('status', [Order::PENDING, Order::EXPIRED])
                ->where('utr_attempts', '<', Order::MAX_UTR_ATTEMPTS)
                ->update([
                    'status' => Order::REVIEW,
                    'customer_utr' => $utr,
                    'utr_submitted_at' => now(),
                    'utr_attempts' => DB::raw('utr_attempts + 1'),
                    'review_note' => null,
                ]);
            if ($updated) {
                app(WebhookOutboxService::class)->record($locked->refresh(), 'order.under_review');
            }
        });

        return $order->refresh();
    }

    /**
     * Merchant confirms by entering the UTR and exact amount verified in their bank/UPI account.
     * Never treats matching customer-submitted UTR alone as proof of payment.
     */
    public function approveCustomerUtr(Order $order, string $bankUtr, float $receivedAmount = 0.0): Order
    {
        $bankUtr = $this->normalizeUtr($bankUtr);

        try {
            DB::transaction(function () use ($order, $bankUtr, $receivedAmount) {
                User::whereKey($order->user_id)->lockForUpdate()->firstOrFail();
                // Lock the row to prevent a concurrent approve + reject race.
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

                if ($locked->status !== Order::REVIEW) {
                    throw ValidationException::withMessages(['bank_utr' => 'This order is not waiting for review.']);
                }

                if (number_format($receivedAmount, 2, '.', '') !== number_format((float) $locked->amount, 2, '.', '')) {
                    throw ValidationException::withMessages(['received_amount' => 'Received amount must match the order amount exactly.']);
                }

                if (! hash_equals((string) $locked->customer_utr, $bankUtr)) {
                    throw ValidationException::withMessages(['bank_utr' => "UTR doesn't match what the customer submitted. Check your bank/UPI app again, or reject."]);
                }

                $this->ensureUtrUnused($locked, $bankUtr);

                $locked->forceFill([
                    'status' => Order::PAID,
                    'utr' => $bankUtr,
                    'verified_by' => 'utr_review',
                    'paid_at' => now(),
                    'review_note' => null,
                    'verification_token' => null,
                    'verification_expires_at' => null,
                    'verification_pid' => null,
                    'verification_node_id' => null,
                    'verification_node_instance_id' => null,
                    'verification_process_identity' => null,
                ])->save();

                app(WebhookOutboxService::class)->record($locked, 'order.paid');
            });
        } catch (UniqueConstraintViolationException $e) {
            if (! $this->isUtrUniqueConstraintViolation($e)) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'bank_utr' => 'This UTR has already been confirmed on another order.',
            ]);
        }

        return $order->refresh();
    }

    /**
     * Merchant couldn't find the payment. The customer gets another chance
     * (fresh expiry window) unless they have used all attempts.
     */
    public function rejectCustomerUtr(Order $order, ?string $reason): Order
    {
        DB::transaction(function () use ($order, $reason) {
            // Lock to prevent a concurrent approve + reject race.
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();

            if ($locked->status !== Order::REVIEW) {
                throw ValidationException::withMessages(['reason' => 'This order is not waiting for review.']);
            }

            $canRetry = $locked->utr_attempts < Order::MAX_UTR_ATTEMPTS;

            Order::whereKey($locked->id)->where('status', Order::REVIEW)->update([
                'status' => $canRetry ? Order::PENDING : Order::EXPIRED,
                'review_note' => $reason ?: "UTR {$locked->customer_utr} was not found in the merchant's account.",
                'expires_at' => $canRetry ? now()->addMinutes(config('gateway.order_expiry_minutes')) : $locked->expires_at,
            ]);

            if (! $canRetry) {
                app(WebhookOutboxService::class)->record($locked->refresh(), 'order.expired');
            }
        });

        return $order->refresh();
    }

    /**
     * Check if a UniqueConstraintViolationException was specifically caused by the
     * orders.user_id + orders.utr composite unique constraint.
     */
    private function isUtrUniqueConstraintViolation(UniqueConstraintViolationException $e): bool
    {
        $driverMessage = (string) ($e->errorInfo[2] ?? '');
        $message = $driverMessage !== '' ? $driverMessage : $e->getMessage();

        // MySQL: exact key name for unique(user_id, utr) on orders table
        if (str_contains($message, 'orders_user_id_utr_unique')) {
            return true;
        }

        // SQLite: exact failure of orders.user_id, orders.utr composite constraint
        if (preg_match('/UNIQUE constraint failed:\s*orders\.user_id,\s*orders\.utr/i', $message)
            || preg_match('/UNIQUE constraint failed:\s*orders\.utr,\s*orders\.user_id/i', $message)) {
            return true;
        }

        // SQLite fallback if table prefix is omitted
        if (preg_match('/UNIQUE constraint failed:\s*user_id,\s*utr/i', $message)
            || preg_match('/UNIQUE constraint failed:\s*utr,\s*user_id/i', $message)) {
            return true;
        }

        return false;
    }

    /**
     * A confirmed UTR identifies one bank transfer, so it may back only one order per merchant.
     */
    public function ensureUtrUnused(Order $order, string $utr): void
    {
        $taken = Order::where('user_id', $order->user_id)
            ->whereKeyNot($order->id)
            ->where('utr', $utr)
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'utr' => 'This UTR is already used on another order.',
                'bank_utr' => 'This UTR is already used on another order.',
            ]);
        }
    }

    public function normalizeUtr(string $utr): string
    {
        return strtoupper(preg_replace('/\s+/', '', $utr));
    }

    public function simulateSandbox(Order $order, string $outcome): Order
    {
        if (! in_array($outcome, ['success', 'failed', 'pending', 'expired', 'timeout', 'invalid_reference'], true)) {
            throw ValidationException::withMessages(['outcome' => 'Invalid sandbox outcome.']);
        }

        if ($outcome === 'invalid_reference') {
            throw ValidationException::withMessages([
                'transaction_reference' => 'The simulated transaction reference is invalid.',
            ]);
        }

        DB::transaction(function () use ($order, $outcome) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->environment !== Order::SANDBOX) {
                abort(404);
            }

            if ($locked->payment_account_id !== null) {
                $this->lockCompatibleAccount($locked);
            }

            if (! $locked->isPending() || $locked->sandbox_simulated_at !== null) {
                throw ValidationException::withMessages([
                    'outcome' => 'This test order has already been simulated.',
                ]);
            }

            $values = [
                'sandbox_simulated_at' => now(),
                'provider_response' => [
                    'simulated' => true,
                    'environment' => Order::SANDBOX,
                    'outcome' => $outcome,
                ],
                'verified_by' => 'sandbox',
            ];

            $event = match ($outcome) {
                'success' => 'order.paid',
                'failed' => 'order.failed',
                'expired' => 'order.expired',
                'timeout' => 'order.timeout',
                default => 'order.pending',
            };

            if ($outcome === 'success') {
                $values += [
                    'status' => Order::PAID,
                    'utr' => 'TEST'.strtoupper(Str::random(16)),
                    'provider_txn_id' => 'TEST_TXN_'.strtoupper(Str::random(12)),
                    'paid_at' => now(),
                ];
            } elseif ($outcome === 'failed') {
                $values['status'] = Order::FAILED;
            } elseif ($outcome === 'expired') {
                $values['status'] = Order::EXPIRED;
            }

            $locked->forceFill($values)->save();
            app(WebhookOutboxService::class)->record($locked, $event);
        });

        return $order->refresh();
    }

    /**
     * Pick the UPI account for a new order:
     * - if an explicit `payment_account_id` is supplied, that exact account must be
     *   active, Live-compatible, and within its daily limit; it is strictly validated
     *   and never silently falls back to rotation or another account;
     * - when `payment_account_id` is omitted, automatic rotation selects among active
     *   accounts with room left today: `random` picks any one, `default` prefers
     *   the default account.
     */
    private function resolveAccount(User $merchant, float $amount, ?int $accountId): PaymentAccount
    {
        if ($accountId !== null) {
            $explicit = $merchant->paymentAccounts()->whereKey($accountId)->withUsageToday()->first();

            if (! $explicit) {
                throw ValidationException::withMessages(['payment_account_id' => 'This UPI account does not exist.']);
            }

            if ($explicit->paymentEnvironment() !== Order::LIVE) {
                throw ValidationException::withMessages(['payment_account_id' => 'Live orders require a Live payment account.']);
            }

            if ($explicit->provider === 'paytm_business' && ! (bool) config('gateway.enable_paytm_business', true)) {
                throw ValidationException::withMessages(['payment_account_id' => 'Paytm for Business accounts are currently disabled by platform policy.']);
            }

            if (! $explicit->is_active) {
                throw ValidationException::withMessages(['payment_account_id' => 'The selected payment account is currently disabled.']);
            }

            if (! $explicit->canAccept($amount)) {
                throw ValidationException::withMessages(['payment_account_id' => 'The selected payment account has reached its daily limit for this amount.']);
            }

            return $explicit;
        }

        $accounts = $merchant->paymentAccounts()
            ->where('is_active', true)
            ->withUsageToday()
            ->get()
            ->filter(fn (PaymentAccount $account) => $account->paymentEnvironment() === Order::LIVE)
            ->when(! (bool) config('gateway.enable_paytm_business', true), fn ($collection) => $collection->filter(fn (PaymentAccount $a) => $a->provider !== 'paytm_business'));

        if ($accounts->isEmpty()) {
            throw ValidationException::withMessages(['payment_account_id' => 'Add an active Live payment account before creating Live orders.']);
        }

        $available = $accounts->filter(fn (PaymentAccount $a) => $a->canAccept($amount));

        if ($available->isEmpty()) {
            throw ValidationException::withMessages(['amount' => 'All UPI accounts have reached their daily limit for this amount. Try a smaller amount or raise a limit.']);
        }

        return $merchant->rotation_mode === 'random'
            ? $available->random()
            : $available->sortBy([['is_default', 'desc'], ['id', 'asc']])->first();
    }

    private function lockCompatibleAccount(Order $order): PaymentAccount
    {
        $account = $order->paymentAccount()->lockForUpdate()->first();
        if (! $account) {
            throw ValidationException::withMessages(['payment_account_id' => 'A matching payment account is required.']);
        }
        $account->assertCompatibleWithOrder($order);
        $order->setRelation('paymentAccount', $account);

        return $account;
    }

    private function validateAccountForCheck(Order $order): void
    {
        // Refresh cached relationships before choosing an endpoint. The final
        // transition locks and validates again after the network request.
        $order->load('paymentAccount');
        if (! $order->paymentAccount) {
            throw ValidationException::withMessages(['payment_account_id' => 'A matching payment account is required.']);
        }
        $order->paymentAccount->assertCompatibleWithOrder($order);
    }
}
