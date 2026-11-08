<?php

namespace App\Services;

use App\Jobs\SendWebhook;
use App\Models\WebhookOutbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class WebhookOutboxDispatcher
{
    public function due(): Builder
    {
        return WebhookOutbox::whereIn('status', ['pending', 'queued', 'failed'])
            ->whereNull('delivered_at')->where('available_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('claimed_at')
                ->orWhere('claimed_at', '<=', now()->subSeconds(config('webhooks.claim_seconds'))));
    }

    public function dispatchDue(): int
    {
        $count = 0;
        $ids = $this->due()->orderBy('available_at')->orderBy('id')
            ->limit(config('webhooks.outbox_batch_size'))->pluck('id');
        foreach ($ids as $id) {
            if ($this->dispatchOne($id)) {
                $count++;
            }
        }

        return $count;
    }

    public function dispatchOne(int $id): bool
    {
        $token = (string) Str::uuid();
        $event = DB::transaction(function () use ($id, $token) {
            $event = $this->due()->whereKey($id)->lockForUpdate()->first();
            if (! $event) {
                return null;
            }
            $event->forceFill([
                'claim_token' => $token, 'claimed_at' => now(),
                'available_at' => now()->addSeconds(config('webhooks.claim_seconds')),
                'dispatch_attempts' => $event->dispatch_attempts + 1,
            ])->save();

            return $event;
        });
        if (! $event) {
            return false;
        }

        try {
            if (config('queue.connections.'.config('queue.default').'.driver') === 'null') {
                throw new RuntimeException('The null queue cannot accept durable webhooks.');
            }
            // Queue insertion is immediate here, never a deferred PendingDispatch.
            // No database row locks are held during publication.
            Queue::push((new SendWebhook($event->order, $event->event, $event->event_id, $event->dispatch_attempts))->beforeCommit());
            WebhookOutbox::whereKey($id)->where('claim_token', $token)->whereNull('delivered_at')
                ->whereIn('status', ['pending', 'queued', 'failed'])->update([
                    'status' => 'queued', 'queued_at' => now(), 'claim_token' => null, 'claimed_at' => null,
                    'available_at' => now()->addSeconds(config('webhooks.reconcile_seconds')), 'last_error' => null,
                ]);

            return true;
        } catch (Throwable $e) {
            WebhookOutbox::whereKey($id)->where('claim_token', $token)->whereNull('delivered_at')
                ->whereIn('status', ['pending', 'queued', 'failed'])->update([
                    'status' => 'pending', 'claim_token' => null, 'claimed_at' => null,
                    'available_at' => now()->addSeconds(config('webhooks.dispatch_retry_seconds')),
                    'last_error' => 'queue_insert_failed',
                ]);
            Log::warning('Webhook outbox queue insertion failed', [
                'event_id' => $event->event_id, 'outbox_id' => $id, 'exception_type' => get_class($e),
            ]);

            return false;
        }
    }
}
