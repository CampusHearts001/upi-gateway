<?php

namespace App\Services;

use App\Models\Order;
use App\Models\WebhookOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class WebhookOutboxService
{
    public function record(Order $order, string $event): WebhookOutbox
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Webhook intent must be recorded in the order transaction.');
        }
        // Serialize event numbering and snapshot the state on this transaction's
        // connection, never a stale relation or a model restored by a later job.
        $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
        $expectedStatus = match ($event) {
            'order.paid' => Order::PAID,
            'order.expired' => Order::EXPIRED,
            'order.under_review' => Order::REVIEW,
            'order.failed' => Order::FAILED,
            'order.pending', 'order.timeout' => Order::PENDING,
            default => throw new LogicException('Unsupported webhook event.'),
        };
        if ($locked->status !== $expectedStatus || ! in_array($locked->environment, [Order::LIVE, Order::SANDBOX], true)) {
            throw new LogicException('Webhook event must match the recorded order state and environment.');
        }
        if ($event === 'order.paid') {
            $existing = WebhookOutbox::where('transition_key', 'paid:'.$locked->id)->first();
            if ($existing) {
                return $existing;
            }
        }
        // Keep the counter on the already-locked parent. MAX()/gap locks on
        // an empty outbox can deadlock unrelated orders under InnoDB RR.
        $sequence = ((int) $locked->webhook_event_sequence) + 1;
        $locked->forceFill(['webhook_event_sequence' => $sequence])->save();
        $eventId = (string) Str::uuid();

        return WebhookOutbox::create([
            'event_id' => $eventId,
            'order_id' => $locked->id,
            'sequence' => $sequence,
            'transition_key' => $event === 'order.paid' ? 'paid:'.$locked->id : 'transition:'.$locked->id.':'.$sequence,
            'event' => $event,
            'environment' => $locked->environment,
            'payload' => [
                'event_id' => $eventId,
                'event_sequence' => $sequence,
                'occurred_at' => now()->toIso8601String(),
                'event' => $event,
                'data' => $locked->toApiArray(),
            ],
            'status' => 'pending',
            'available_at' => now(),
        ]);
    }
}
