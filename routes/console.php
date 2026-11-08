<?php

use App\Models\Order;
use App\Models\User;
use App\Models\VerificationLeaseRecoveryAudit;
use App\Models\WebhookOutbox;
use App\Services\OrderService;
use App\Services\VerificationProcessInspector;
use App\Services\WebhookOutboxDispatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\ValidationException;

/**
 * Catches payments whose customer closed the checkout page before it
 * confirmed, and expires unpaid orders (after one last status check).
 *
 * Priority 27: Independent, bounded dual-phase execution with distributed
 * locking, anti-starvation fair ordering, and isolated error boundaries.
 */
Artisan::command('orders:check-pending', function (OrderService $orders) {
    $startTime = microtime(true);
    $lockSeconds = max(60, min(3600, (int) config('gateway.scheduler_lock_seconds', 300)));
    $lockKey = 'scheduler:orders:check-pending';
    $lock = Cache::lock($lockKey, $lockSeconds);

    if (! $lock->get()) {
        Log::warning('Order verification scheduler skipped: another cycle is already in progress', [
            'lock_key' => $lockKey,
            'lock_seconds' => $lockSeconds,
        ]);
        $this->warn('Another order verification process is currently running; skipping this cycle.');

        return 0;
    }

    $pendingMetrics = [
        'eligible' => 0,
        'selected' => 0,
        'checked' => 0,
        'paid' => 0,
        'expired' => 0,
        'skipped' => 0,
        'rejected' => 0,
        'failed' => 0,
        'duration_ms' => 0,
    ];

    $lateMetrics = [
        'eligible' => 0,
        'selected' => 0,
        'checked' => 0,
        'recovered_paid' => 0,
        'skipped' => 0,
        'rejected' => 0,
        'failed' => 0,
        'duration_ms' => 0,
    ];

    try {
        // ── Phase 1: Pending Orders Verification ───────────────────────────
        $phase1Start = microtime(true);
        try {
            $pendingBatchSize = max(1, min(1000, (int) config('gateway.pending_verification_batch_size', 100)));
            $pendingInterval = max(1, (int) config('gateway.check_interval_seconds', 3));

            $pendingQuery = Order::with('paymentAccount')
                ->where('status', Order::PENDING)
                ->where(function ($q) use ($pendingInterval) {
                    $q->whereNull('last_checked_at')
                        ->orWhere('last_checked_at', '<=', now()->subSeconds($pendingInterval))
                        ->orWhere('expires_at', '<=', now());
                });

            $pendingMetrics['eligible'] = (clone $pendingQuery)->count();

            $pendingOrders = (clone $pendingQuery)
                ->orderByRaw('CASE WHEN last_checked_at IS NULL THEN 0 ELSE 1 END ASC')
                ->orderBy('last_checked_at', 'asc')
                ->orderBy('id', 'asc')
                ->limit($pendingBatchSize)
                ->get();

            $pendingMetrics['selected'] = $pendingOrders->count();

            foreach ($pendingOrders as $order) {
                $initialStatus = $order->status;
                $initialCheckedAt = $order->last_checked_at;
                try {
                    $updated = $orders->check($order);
                    $pendingMetrics['checked']++;

                    if ($updated->status === Order::PAID) {
                        $pendingMetrics['paid']++;
                    } elseif ($updated->status === Order::EXPIRED && $initialStatus !== Order::EXPIRED) {
                        $pendingMetrics['expired']++;
                    } elseif ($updated->status === $initialStatus && (string) $updated->last_checked_at === (string) $initialCheckedAt) {
                        $pendingMetrics['skipped']++;
                    }
                } catch (ValidationException $e) {
                    // Invalid legacy account assignments must not stop expiry
                    // and verification of the other merchants' pending orders.
                    $pendingMetrics['rejected']++;
                    // Advance last_checked_at so misconfigured accounts cannot starve other pending orders
                    Order::whereKey($order->id)->update(['last_checked_at' => now()]);
                    Log::warning('Pending order verification rejected', $orders->verificationLogContext($order) + [
                        'validation_fields' => array_keys($e->errors()),
                    ]);
                } catch (Throwable $e) {
                    $pendingMetrics['failed']++;
                    Order::whereKey($order->id)->update(['last_checked_at' => now()]);
                    Log::error('Scheduled order verification failed', [
                        'order_id' => $order->id,
                        'exception_type' => get_class($e),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (Throwable $e) {
            $pendingMetrics['failed']++;
            Log::error('Pending order verification phase failed unexpectedly', [
                'exception_type' => get_class($e),
                'error' => $e->getMessage(),
            ]);
            $this->error('Pending order verification phase encountered an unexpected error: '.$e->getMessage());
        } finally {
            $pendingMetrics['duration_ms'] = (int) round((microtime(true) - $phase1Start) * 1000);
        }

        $this->info("Checked {$pendingMetrics['checked']} pending orders.");
        if ($pendingMetrics['rejected'] > 0) {
            $this->warn("Rejected {$pendingMetrics['rejected']} pending orders; review their payment account configuration.");
        }

        // ── Phase 2: Late-Payment Recovery ─────────────────────────────────
        $phase2Start = microtime(true);
        try {
            $lateBatchSize = max(1, min(1000, (int) config('gateway.late_payment_batch_size', 100)));
            $lateInterval = max(120, (int) config('gateway.late_payment_check_interval_seconds', 300));

            $lateQuery = Order::recoverableExpired()
                ->where(fn ($q) => $q->whereNull('last_checked_at')
                    ->orWhere('last_checked_at', '<=', now()->subSeconds($lateInterval)));

            $lateMetrics['eligible'] = (clone $lateQuery)->count();

            $lateOrders = (clone $lateQuery)
                ->orderByRaw('CASE WHEN last_checked_at IS NULL THEN 0 ELSE 1 END ASC')
                ->orderBy('last_checked_at', 'asc')
                ->orderBy('id', 'asc')
                ->limit($lateBatchSize)
                ->get();

            $lateMetrics['selected'] = $lateOrders->count();

            foreach ($lateOrders as $order) {
                $initialStatus = $order->status;
                $initialCheckedAt = $order->last_checked_at;
                try {
                    $updated = $orders->check($order);
                    $lateMetrics['checked']++;

                    if ($updated->status === Order::PAID) {
                        $lateMetrics['recovered_paid']++;
                    } elseif ($updated->status === $initialStatus && (string) $updated->last_checked_at === (string) $initialCheckedAt) {
                        $lateMetrics['skipped']++;
                    }
                } catch (ValidationException) {
                    $lateMetrics['rejected']++;
                    // checkLatePayment already logged safe account/environment context and updated last_checked_at.
                } catch (Throwable $e) {
                    $lateMetrics['failed']++;
                    Order::whereKey($order->id)->update(['last_checked_at' => now()]);
                    Log::error('Scheduled late payment verification failed', [
                        'order_id' => $order->id,
                        'payment_account_id' => $order->payment_account_id,
                        'exception_type' => get_class($e),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (Throwable $e) {
            $lateMetrics['failed']++;
            Log::error('Late-payment recovery phase failed unexpectedly', [
                'exception_type' => get_class($e),
                'error' => $e->getMessage(),
            ]);
            $this->error('Late-payment recovery phase encountered an unexpected error: '.$e->getMessage());
        } finally {
            $lateMetrics['duration_ms'] = (int) round((microtime(true) - $phase2Start) * 1000);
        }

        $this->info("Processed {$lateMetrics['checked']} expired orders for late-payment recovery.");
        if ($lateMetrics['recovered_paid'] > 0) {
            $this->info("Successfully recovered {$lateMetrics['recovered_paid']} late payment(s).");
        }
    } finally {
        $totalDurationMs = (int) round((microtime(true) - $startTime) * 1000);

        Log::info('Order verification scheduler cycle completed', [
            'duration_ms' => $totalDurationMs,
            'pending' => $pendingMetrics,
            'late_payment' => $lateMetrics,
        ]);

        try {
            optional($lock)->release();
        } catch (Throwable) {
            // Ignore lock release failures (e.g. lock already expired)
        }
    }

    return 0;
})->purpose('Verify pending orders with providers and expire stale ones');

Artisan::command('orders:recover-verification-lease {order} {execution} {generation} {operator} {reason} {--confirm-stopped} {--confirm-node-shutdown}', function (
    string $order,
    string $execution,
    string $generation,
    string $operator,
    string $reason,
    VerificationProcessInspector $inspector,
) {
    if (! $this->option('confirm-stopped')) {
        $this->error('Refusing recovery: conclusively stop the recorded owner, then pass --confirm-stopped.');

        return 1;
    }
    $operator = trim($operator);
    $reason = trim($reason);
    if (! ctype_digit($order) || ! ctype_digit($generation) || (int) $generation < 1
        || $operator === '' || $reason === '') {
        $this->error('Order ID, expected generation, operator identity, and recovery reason are required.');

        return 1;
    }

    $recovered = DB::transaction(function () use ($order, $execution, $generation, $operator, $reason, $inspector) {
        $locked = Order::whereKey((int) $order)->lockForUpdate()->first();
        if (! $locked || $locked->verification_token !== $execution
            || (int) $locked->verification_generation !== (int) $generation
            || ! $locked->verification_expires_at || $locked->verification_expires_at->isFuture()) {
            return false;
        }
        if ($locked->status !== Order::PENDING) {
            return false;
        }

        $sameNode = $locked->verification_node_id !== null
            && $locked->verification_node_instance_id !== null
            && hash_equals($inspector->nodeId(), $locked->verification_node_id)
            && hash_equals($inspector->nodeInstanceId(), $locked->verification_node_instance_id);
        $liveness = $inspector->status(
            $locked->verification_node_id,
            $locked->verification_node_instance_id,
            $locked->verification_pid,
            $locked->verification_process_identity,
        );
        if ($liveness === VerificationProcessInspector::ALIVE) {
            return false;
        }
        if ((! $sameNode || $liveness === VerificationProcessInspector::UNKNOWN)
            && ! $this->option('confirm-node-shutdown')) {
            return false;
        }

        $previous = [
            'generation' => (int) $locked->verification_generation,
            'node_id' => $locked->verification_node_id,
            'node_instance_id' => $locked->verification_node_instance_id,
            'pid' => $locked->verification_pid,
            'process_identity' => $locked->verification_process_identity,
        ];

        $locked->forceFill([
            'verification_token' => null,
            'verification_expires_at' => null,
            'verification_pid' => null,
            'verification_node_id' => null,
            'verification_node_instance_id' => null,
            'verification_process_identity' => null,
        ])->save();

        VerificationLeaseRecoveryAudit::create([
            'order_id' => $locked->id,
            'order_no' => $locked->order_no,
            'previous_generation' => $previous['generation'],
            'previous_node_id' => $previous['node_id'],
            'previous_node_instance_id' => $previous['node_instance_id'],
            'previous_pid' => $previous['pid'],
            'previous_process_identity' => $previous['process_identity'],
            'execution_token_hash' => hash('sha256', $execution),
            'operator_identity' => $operator,
            'reason' => $reason,
            'outcome' => 'recovered',
            'recovered_at' => now(),
        ]);

        Log::warning('Operator recovered an abandoned provider verification lease', [
            'order_id' => $locked->id,
            'verification_generation' => $previous['generation'],
            'operator_identity' => $operator,
        ]);

        return true;
    });

    if (! $recovered) {
        $this->error('No matching expired execution was recovered. Recheck the order and exact execution token.');

        return 1;
    }

    $this->info('The confirmed-stopped execution lease was cleared. A later check may acquire a new generation.');

    return 0;
})->purpose('Recover an expired pending-order verification lease after its exact owner is confirmed stopped');

Artisan::command('app:create-admin {email} {--name=Admin}', function (string $email) {
    $password = $this->secret('Password (min 10 chars)');

    if (strlen((string) $password) < 10) {
        return $this->error('Password must be at least 10 characters.');
    }

    $user = User::updateOrCreate(['email' => $email], [
        'name' => $this->option('name'),
        'password' => $password,
        'role' => 'admin',
        'is_active' => true,
    ]);

    if (! $user->api_key) {
        $user->regenerateApiCredentials();
    }

    $this->info("Admin ready: {$email}");
})->purpose('Create or promote an admin user');

Artisan::command('webhooks:dispatch', function (WebhookOutboxDispatcher $dispatcher) {
    $this->info('Queued '.$dispatcher->dispatchDue().' webhook outbox events.');
})->purpose('Dispatch a bounded batch of due webhook intents and reconcile stale queued events');

Artisan::command('webhooks:retry {eventId}', function (string $eventId) {
    $event = WebhookOutbox::where('event_id', $eventId)->firstOrFail();
    if ($event->delivered_at !== null) {
        $this->error('This event has already been delivered.');

        return 1;
    }
    if ($event->payload === null) {
        $this->error("Payload is offloaded. Run 'php artisan webhooks:restore-payload {$eventId}' first.");

        return 1;
    }
    // Do not clear active dispatcher claims. They recover on their own lease.
    // Reset dispatch_attempts to 0 so manual retry establishes a fresh generation budget.
    $updated = WebhookOutbox::whereKey($event->id)->whereNull('delivered_at')
        ->where(fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<=', now()->subSeconds(config('webhooks.claim_seconds'))))
        ->update(['status' => 'pending', 'available_at' => now(), 'claim_token' => null, 'claimed_at' => null, 'dispatch_attempts' => 0, 'last_error' => null]);
    if (! $updated) {
        $this->error('Event is currently claimed; try after the claim expires.');

        return 1;
    }
    $this->info('Event scheduled for retry with its original ID and payload.');

    return 0;
})->purpose('Retry an undelivered outbox event after investigating and correcting its failure');

Schedule::command('webhooks:dispatch')->everyMinute()->withoutOverlapping(5);
Schedule::command('orders:check-pending')->everyMinute()->withoutOverlapping();
Schedule::command('webhooks:offload-payloads')->daily()->withoutOverlapping(10);
Schedule::command('webhooks:prune-logs')->daily()->withoutOverlapping(10);
