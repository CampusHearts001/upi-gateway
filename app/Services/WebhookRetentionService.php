<?php

namespace App\Services;

use App\Models\Order;
use App\Models\WebhookOutbox;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebhookRetentionService
{
    /**
     * Offload JSON payloads for delivered outbox events past the retention cutoff.
     * Canonical skeleton (id, event_id, transition_key, order_id, sequence, delivered_at) is NEVER deleted.
     */
    public function offloadDeliveredPayloads(int $chunkSize = 100): int
    {
        $retentionDays = max(1, (int) config('webhooks.retention_days', 30));
        $cutoff = now()->subDays($retentionDays);
        $offloadedCount = 0;

        $events = WebhookOutbox::where('status', 'delivered')
            ->whereNotNull('delivered_at')
            ->where('delivered_at', '<=', $cutoff)
            ->whereNotNull('payload')
            ->orderBy('id')
            ->limit($chunkSize)
            ->get();

        foreach ($events as $event) {
            $lock = Cache::lock('webhook-delivery:'.$event->event_id, 5);
            if (! $lock->get()) {
                Log::info("Skipping outbox payload offload for event {$event->event_id}: active cache lock held.");

                continue;
            }

            try {
                DB::transaction(function () use ($event, &$offloadedCount) {
                    $locked = WebhookOutbox::whereKey($event->id)
                        ->where('status', 'delivered')
                        ->whereNotNull('delivered_at')
                        ->whereNotNull('payload')
                        ->lockForUpdate()
                        ->first();

                    if (! $locked) {
                        return;
                    }

                    $payloadData = is_string($locked->payload) ? $locked->payload : json_encode($locked->payload);

                    DB::table('archived_webhook_payloads')->upsert([
                        [
                            'event_id' => $locked->event_id,
                            'order_id' => $locked->order_id,
                            'payload' => $payloadData,
                            'archived_at' => now(),
                        ],
                    ], ['event_id'], ['payload', 'archived_at']);

                    DB::table('webhook_outbox')
                        ->where('id', $locked->id)
                        ->update(['payload' => null, 'updated_at' => now()]);

                    $offloadedCount++;
                });
            } finally {
                $lock->release();
            }
        }

        return $offloadedCount;
    }

    /**
     * Restore an offloaded payload from archive storage back into webhook_outbox.
     * Pure storage operation: does NOT modify outbox status.
     */
    public function restorePayload(string $eventId): bool
    {
        return DB::transaction(function () use ($eventId) {
            $archived = DB::table('archived_webhook_payloads')
                ->where('event_id', $eventId)
                ->first();

            if (! $archived) {
                return false;
            }

            $affected = DB::table('webhook_outbox')
                ->where('event_id', $eventId)
                ->whereNull('payload')
                ->update([
                    'payload' => $archived->payload,
                    'updated_at' => now(),
                ]);

            return $affected > 0;
        });
    }

    /**
     * Rollback offloaded payloads in chunks across MySQL 8 and SQLite.
     */
    public function rollbackPayloads(int $chunkSize = 100, bool $purgeArchive = false): int
    {
        $restored = 0;

        DB::table('archived_webhook_payloads')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($batch) use (&$restored, $purgeArchive) {
                DB::transaction(function () use ($batch, &$restored, $purgeArchive) {
                    $idsToPurge = [];

                    foreach ($batch as $record) {
                        $affected = DB::table('webhook_outbox')
                            ->where('event_id', $record->event_id)
                            ->whereNull('payload')
                            ->update([
                                'payload' => $record->payload,
                                'updated_at' => now(),
                            ]);

                        if ($affected > 0) {
                            $restored++;
                            if ($purgeArchive) {
                                $idsToPurge[] = $record->id;
                            }
                        }
                    }

                    if (! empty($idsToPurge)) {
                        DB::table('archived_webhook_payloads')
                            ->whereIn('id', $idsToPurge)
                            ->delete();
                    }
                });
            });

        return $restored;
    }

    /**
     * Archive webhook_logs for fully settled orders past the retention cutoff.
     * Uses strict 13-column match-or-abort verification before deleting active records.
     */
    public function pruneEligibleLogs(int $chunkSize = 100): int
    {
        $retentionDays = max(1, (int) config('webhooks.retention_days', 30));
        $cutoff = now()->subDays($retentionDays);

        $graceHours = max(0, (int) config('gateway.late_payment_grace_hours', 24));
        $skewSeconds = max(0, (int) config('gateway.evidence_clock_skew_seconds', 300));
        $lateRecoveryBufferSeconds = ($graceHours * 3600) + $skewSeconds;

        $candidateLogs = DB::table('webhook_logs')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit($chunkSize)
            ->get();

        $archivedCount = 0;

        foreach ($candidateLogs as $log) {
            $order = Order::find($log->order_id);
            if (! $order || ! $this->isOrderEligibleForLogArchival($order, $retentionDays, $lateRecoveryBufferSeconds)) {
                continue;
            }

            $archived = $this->archiveSingleLogWithConflictProtection($log);
            if ($archived) {
                $archivedCount++;
            }
        }

        return $archivedCount;
    }

    /**
     * Determine whether an order is completely resolved and safe for log archival.
     */
    public function isOrderEligibleForLogArchival(Order $order, int $retentionDays, int $lateRecoveryBufferSeconds): bool
    {
        $cutoff = now()->subDays($retentionDays);

        // 1. If order has ANY outbox events, every associated outbox event must be delivered.
        $outboxEvents = DB::table('webhook_outbox')->where('order_id', $order->id)->get();
        if ($outboxEvents->isNotEmpty()) {
            foreach ($outboxEvents as $event) {
                if ($event->delivered_at === null || $event->status !== 'delivered') {
                    // Outbox event is unresolved, retryable, claimed, queued, failed, blocked, or unknown -> FAIL CLOSED
                    return false;
                }
            }
        }

        // 2. Order status eligibility check
        if ($order->status === Order::PAID) {
            return $order->paid_at !== null && $order->paid_at->lte($cutoff);
        }

        if ($order->status === Order::EXPIRED) {
            $expiryBasis = $order->original_expires_at ?? $order->expires_at;
            if ($expiryBasis === null) {
                return false;
            }

            // Expiry deadline + late payment grace + clock skew must be safely past retention cutoff
            $recoveryEnd = $expiryBasis->copy()->addSeconds($lateRecoveryBufferSeconds);

            return $recoveryEnd->lte($cutoff);
        }

        if ($order->status === Order::FAILED) {
            // Live FAILED orders fail closed. Sandbox FAILED orders evaluate sandbox_simulated_at.
            if ($order->environment === Order::SANDBOX && $order->sandbox_simulated_at !== null) {
                return $order->sandbox_simulated_at->lte($cutoff);
            }

            return false;
        }

        // PENDING, REVIEW, or unknown statuses strictly fail closed
        return false;
    }

    /**
     * Move a single log from active to archive with 13-column conflict protection.
     */
    private function archiveSingleLogWithConflictProtection(object $log): bool
    {
        return DB::transaction(function () use ($log) {
            $existing = DB::table('archived_webhook_logs')->where('id', $log->id)->first();

            if ($existing !== null) {
                // Verify all 13 columns match identically
                if ($this->doLogColumnsMatch($log, $existing)) {
                    // Proven exact copy from earlier interrupted run. Safe to delete active row.
                    DB::table('webhook_logs')->where('id', $log->id)->delete();

                    return true;
                }

                // Checksum / content mismatch: Abort active deletion to prevent data loss!
                Log::error("Archive conflict detected on webhook_log #{$log->id}: data mismatch. Active deletion aborted.");

                return false;
            }

            // New archive insert: write to archive, then delete from active
            $payloadData = is_string($log->payload) ? $log->payload : json_encode($log->payload);

            DB::table('archived_webhook_logs')->insert([
                'id' => $log->id,
                'order_id' => $log->order_id,
                'event' => $log->event,
                'event_id' => $log->event_id,
                'url' => $log->url,
                'payload' => $payloadData,
                'response_code' => $log->response_code,
                'response_body' => $log->response_body,
                'success' => (bool) $log->success,
                'attempt' => $log->attempt,
                'error_label' => $log->error_label,
                'created_at' => $log->created_at,
                'updated_at' => $log->updated_at,
            ]);

            DB::table('webhook_logs')->where('id', $log->id)->delete();

            return true;
        });
    }

    /**
     * Exact 13-column comparison preserving NULL semantics and timestamp precision.
     */
    public function doLogColumnsMatch(object $active, object $archived): bool
    {
        if ((int) $active->id !== (int) $archived->id) {
            return false;
        }
        if ((int) $active->order_id !== (int) $archived->order_id) {
            return false;
        }
        if ((string) $active->event !== (string) $archived->event) {
            return false;
        }
        if ((string) $active->event_id !== (string) $archived->event_id) {
            return false;
        }
        if ((string) $active->url !== (string) $archived->url) {
            return false;
        }
        if ((int) $active->attempt !== (int) $archived->attempt) {
            return false;
        }
        if ((bool) $active->success !== (bool) $archived->success) {
            return false;
        }
        if ($active->response_code !== $archived->response_code && (int) $active->response_code !== (int) $archived->response_code) {
            return false;
        }
        if ($active->response_body !== $archived->response_body) {
            return false;
        }
        if ($active->error_label !== $archived->error_label) {
            return false;
        }

        // Normalized JSON payload comparison
        $activePayload = is_string($active->payload) ? json_decode($active->payload, true) : (array) $active->payload;
        $archivedPayload = is_string($archived->payload) ? json_decode($archived->payload, true) : (array) $archived->payload;
        if ($activePayload !== $archivedPayload) {
            return false;
        }

        // Timestamp comparison (handling string/Carbon instances)
        $activeCreated = $active->created_at ? Carbon::parse($active->created_at)->timestamp : null;
        $archivedCreated = $archived->created_at ? Carbon::parse($archived->created_at)->timestamp : null;
        if ($activeCreated !== $archivedCreated) {
            return false;
        }

        $activeUpdated = $active->updated_at ? Carbon::parse($active->updated_at)->timestamp : null;
        $archivedUpdated = $archived->updated_at ? Carbon::parse($archived->updated_at)->timestamp : null;
        if ($activeUpdated !== $archivedUpdated) {
            return false;
        }

        return true;
    }
}
