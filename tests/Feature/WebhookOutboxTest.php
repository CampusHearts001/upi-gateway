<?php

namespace Tests\Feature;

use App\Jobs\SendWebhook;
use App\Models\Order;
use App\Models\User;
use App\Models\WebhookOutbox;
use App\Services\OrderService;
use App\Services\Verifiers\VerificationResult;
use App\Services\WebhookOutboxDispatcher;
use App\Services\WebhookOutboxService;
use App\Support\WebhookSignature;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WebhookOutboxTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        Http::preventStrayRequests();
        $this->merchant = User::create(['name' => 'Outbox Test', 'email' => 'outbox@example.com', 'password' => 'password12345']);
        $this->merchant->regenerateApiCredentials();
        $this->merchant->update(['webhook_url' => 'https://merchant.example/hook']);
        $this->merchant->paymentAccounts()->create([
            'provider' => 'paytm_business', 'label' => 'Outbox Test', 'payee_name' => 'Test', 'upi_id' => 'test@upi',
            'credentials' => ['mid' => 'TESTMID'],
        ]);
    }

    private function order(): Order
    {
        return app(OrderService::class)->create($this->merchant, ['amount' => 100]);
    }

    private function pay(Order $order): WebhookOutbox
    {
        $this->markProviderPaid($order, VerificationResult::fromPaytmBusiness(
            $order->load('paymentAccount'), 100, sprintf('%012d', 123450000000 + $order->id), 'TXN-'.$order->id
        ));

        return WebhookOutbox::where('order_id', $order->id)->where('event', 'order.paid')->firstOrFail();
    }

    public function test_payment_only_persists_intent_and_dispatch_is_idempotent(): void
    {
        Queue::fake();
        $order = $this->order();
        $event = $this->pay($order);
        $this->assertDatabaseCount('webhook_outbox', 1);
        Queue::assertNothingPushed();
        $this->assertSame(Order::PAID, $order->fresh()->status);
        $this->assertSame('pending', $event->status);
        $this->assertSame('paid:'.$order->id, $event->transition_key);
        $dispatcher = app(WebhookOutboxDispatcher::class);
        $this->assertSame(1, $dispatcher->dispatchDue());
        $this->assertSame(0, $dispatcher->dispatchDue());
        $this->assertSame('queued', $event->fresh()->status);
        $this->assertNull($event->fresh()->delivered_at);
        Queue::assertPushed(SendWebhook::class, fn ($job) => $job->outboxEventId === $event->event_id);
        Queue::assertPushed(SendWebhook::class, 1);
    }

    public function test_expiry_snapshot_survives_late_payment_and_reversed_delivery_order(): void
    {
        Queue::fake();
        $order = $this->order();
        $order->forceFill(['expires_at' => now()->subHour()])->save();
        $this->expireOrder($order);
        $expired = WebhookOutbox::where('order_id', $order->id)->firstOrFail();
        $snapshot = $expired->payload;
        $paid = $this->pay($order);
        $this->assertSame(1, $expired->sequence);
        $this->assertSame(2, $paid->sequence);
        $this->assertSame('expired', $expired->fresh()->payload['data']['status']);
        $this->assertSame($snapshot, $expired->fresh()->payload);
        Http::fake(['merchant.example/*' => Http::response('ok')]);
        (new SendWebhook($order->fresh(), 'order.paid', $paid->event_id))->handle();
        (new SendWebhook($order->fresh(), 'order.expired', $expired->event_id))->handle();
        $requests = Http::recorded();
        $this->assertSame('paid', $requests[0][0]['data']['status']);
        $this->assertSame('expired', $requests[1][0]['data']['status']);
        $this->assertSame($expired->event_id, $requests[1][0]['event_id']);
        $this->assertSame(2, $requests[0][0]['event_sequence']);
        $this->assertSame(1, $requests[1][0]['event_sequence']);
        $this->assertSame('delivered', $paid->fresh()->status);
        $this->assertSame('delivered', $expired->fresh()->status);
    }

    public function test_snapshot_and_identity_cannot_be_edited_through_model(): void
    {
        $event = $this->pay($this->order());
        $original = $event->payload;
        try {
            $event->update(['payload' => ['event' => 'forged']]);
            $this->fail('Snapshot mutation must be rejected.');
        } catch (LogicException) {
            $this->assertSame($original, $event->fresh()->payload);
        }
    }

    public function test_nonterminal_events_receive_snapshots_inside_the_order_transaction(): void
    {
        $order = $this->order();
        $this->assertSame(0, WebhookOutbox::where('order_id', $order->id)->count());
        $event = DB::transaction(fn () => app(WebhookOutboxService::class)->record($order, 'order.pending'));
        $this->assertSame('order.pending', $event->event);
    }

    public function test_paid_intent_cannot_be_recorded_for_an_unpaid_order(): void
    {
        $order = $this->order();
        try {
            DB::transaction(fn () => app(WebhookOutboxService::class)->record($order, 'order.paid'));
            $this->fail('An unpaid order cannot produce a paid notification.');
        } catch (LogicException) {
            $this->assertSame(Order::PENDING, $order->fresh()->status);
            $this->assertDatabaseCount('webhook_outbox', 0);
        }
    }

    public function test_corrupt_snapshot_is_blocked_without_using_a_live_destination(): void
    {
        $event = $this->pay($this->order());
        // Direct SQL represents corruption outside the model's immutable API.
        DB::table('webhook_outbox')->where('id', $event->id)->update(['environment' => 'unknown']);
        (new SendWebhook($event->order, $event->event, $event->event_id))->handle();
        $this->assertSame('blocked', $event->fresh()->status);
        $this->assertSame('invalid_snapshot', $event->fresh()->last_error);
        $this->assertNull($event->fresh()->delivered_at);
        Http::assertNothingSent();
    }

    public function test_schema_rollback_refuses_to_erase_retained_events(): void
    {
        $event = $this->pay($this->order());
        $migration = require database_path('migrations/2026_11_11_000001_create_webhook_outbox.php');
        try {
            $migration->down();
            $this->fail('Rollback must not delete retained event snapshots.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('retained events exist', $e->getMessage());
            $this->assertSame($event->payload, $event->fresh()->payload);
            $this->assertSame(Order::PAID, $event->order->fresh()->status);
        }
    }

    public function test_legacy_job_without_snapshot_fails_for_review_without_reconstructing_history(): void
    {
        $job = new SendWebhook($this->order(), 'order.expired');
        $job->legacySnapshot = null;
        // SerializesModels omits default fields, matching old queued jobs
        // that contained only the order identifier and event name.
        $restored = unserialize(serialize($job));
        try {
            $restored->handle();
            $this->fail('An old event must not be reconstructed from current order state.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('operator review', $e->getMessage());
            $restored->failed($e);
        }
        $this->assertDatabaseCount('webhook_outbox', 0);
        Http::assertNothingSent();
    }

    public function test_fresh_claim_is_skipped_and_stale_claim_is_recovered(): void
    {
        Queue::fake();
        $event = $this->pay($this->order());
        $event->update(['claim_token' => '00000000-0000-4000-8000-000000000001', 'claimed_at' => now(),
            'available_at' => now()->addSeconds(120)]);
        $this->assertSame(0, app(WebhookOutboxDispatcher::class)->dispatchDue());
        Queue::assertNothingPushed();
        $this->travel(121)->seconds();
        $this->assertSame(1, app(WebhookOutboxDispatcher::class)->dispatchDue());
        $this->assertNull($event->fresh()->claim_token);
        $this->assertSame('queued', $event->fresh()->status);
        Queue::assertPushed(SendWebhook::class, 1);
    }

    public function test_queued_but_undelivered_event_is_reconciled_with_same_identity(): void
    {
        Queue::fake();
        $event = $this->pay($this->order());
        $dispatcher = app(WebhookOutboxDispatcher::class);
        $dispatcher->dispatchDue();
        $this->travel(config('webhooks.reconcile_seconds') + 1)->seconds();
        $dispatcher->dispatchDue();
        Queue::assertPushed(SendWebhook::class, 2);
        foreach (Queue::pushed(SendWebhook::class) as $job) {
            $this->assertSame($event->event_id, $job->outboxEventId);
        }
        $this->assertDatabaseCount('webhook_outbox', 1);
        $this->assertNull($event->fresh()->delivered_at);
    }

    public function test_worker_retry_keeps_payload_signature_and_event_id_and_marks_delivery_only_on_success(): void
    {
        $event = $this->pay($this->order());
        Http::fake(['merchant.example/*' => Http::sequence()->push('unavailable', 500)->push('ok', 200)]);
        $job = new SendWebhook($event->order, $event->event, $event->event_id);
        $error = null;
        try {
            $job->handle();
        } catch (RuntimeException $exception) {
            $error = $exception;
        }
        $this->assertInstanceOf(RuntimeException::class, $error);
        $this->assertSame(RuntimeException::class, $error::class);
        $this->assertSame('Webhook delivery failed with 500', $error->getMessage());
        $this->assertNull($event->fresh()->delivered_at);
        $this->assertSame('http_500', $event->fresh()->last_error);
        $job->handle();
        $job->handle();
        Http::assertSentCount(2);
        $requests = Http::recorded();
        $this->assertSame($requests[0][0]->body(), $requests[1][0]->body());
        $this->assertSame($event->event_id, $requests[1][0]->header('X-Event-Id')[0]);
        $this->assertTrue(WebhookSignature::verify($requests[1][0]->body(), $requests[1][0]->header('X-Signature')[0], $this->merchant->api_secret));
        $this->assertSame(hash_hmac('sha256', $requests[1][0]->body(), $this->merchant->api_secret), $requests[1][0]->header('X-Signature-Legacy')[0]);
        $this->assertSame('delivered', $event->fresh()->status);
        $this->assertNotNull($event->fresh()->delivered_at);
        $this->assertSame(2, $event->fresh()->delivery_attempts);
        $this->assertDatabaseCount('webhook_logs', 2);
    }

    public function test_exhausted_worker_is_failed_and_requeued_later_without_changing_identity(): void
    {
        Queue::fake();
        $event = $this->pay($this->order());
        app(WebhookOutboxDispatcher::class)->dispatchDue();
        $job = Queue::pushed(SendWebhook::class)->first();
        $this->assertSame(1, $job->publicationGeneration);
        $this->assertSame(6, $job->tries);
        $this->assertSame([30, 120, 600, 1800, 3600], $job->backoff());
        $job->failed(new RuntimeException('Sensitive failure must not be stored'));
        $this->assertSame('failed', $event->fresh()->status);
        $this->assertSame('delivery_exhausted', $event->fresh()->last_error);
        $this->assertSame(0, app(WebhookOutboxDispatcher::class)->dispatchDue());
        $this->travel(config('webhooks.failed_retry_seconds') + 1)->seconds();
        $this->assertSame(1, app(WebhookOutboxDispatcher::class)->dispatchDue());
        Queue::assertPushed(SendWebhook::class, fn ($queued) => $queued->outboxEventId === $event->event_id);
        $this->assertNull($event->fresh()->delivered_at);
    }

    public function test_blocked_url_is_not_delivered_or_automatically_retried_and_operator_can_retry(): void
    {
        $this->merchant->update(['webhook_url' => 'https://127.0.0.1/hook']);
        $event = $this->pay($this->order());
        (new SendWebhook($event->order, $event->event, $event->event_id))->handle();
        $this->assertSame('blocked', $event->fresh()->status);
        $this->assertSame('blocked_url', $event->fresh()->last_error);
        $this->assertNull($event->fresh()->delivered_at);
        $this->assertSame(0, app(WebhookOutboxDispatcher::class)->dispatchDue());
        Http::assertNothingSent();
        $this->merchant->update(['webhook_url' => 'https://merchant.example/hook']);
        $this->artisan('webhooks:retry', ['eventId' => $event->event_id])->assertSuccessful();
        Queue::fake();
        $this->assertSame(1, app(WebhookOutboxDispatcher::class)->dispatchDue());
        Queue::assertPushed(SendWebhook::class, fn ($job) => $job->outboxEventId === $event->event_id);
    }

    public function test_delivery_lease_suppresses_overlapping_jobs_and_delivered_events_are_not_requeued(): void
    {
        $event = $this->pay($this->order());
        Http::fake(['merchant.example/*' => Http::response('ok')]);
        $lock = Cache::lock('webhook-delivery:'.$event->event_id, 60);
        $this->assertTrue($lock->get());
        $job = new SendWebhook($event->order, $event->event, $event->event_id, $event->dispatch_attempts);
        $job->handle();
        Http::assertNothingSent();
        $lock->release();
        $job->handle();
        $job->failed(new RuntimeException('A stale failed duplicate cannot reopen delivery.'));
        $this->assertSame('delivered', $event->fresh()->status);
        $this->artisan('webhooks:retry', ['eventId' => $event->event_id])->assertFailed();
        $this->travel(20000)->seconds();
        $this->assertSame(0, app(WebhookOutboxDispatcher::class)->dispatchDue());
        Http::assertSentCount(1);
    }

    public function test_dispatcher_batches_are_bounded_and_paid_identity_is_unique(): void
    {
        Queue::fake();
        config(['webhooks.outbox_batch_size' => 2]);
        foreach (range(1, 3) as $i) {
            $this->pay($this->order());
        }
        $this->assertSame(2, app(WebhookOutboxDispatcher::class)->dispatchDue());
        $this->assertSame(1, app(WebhookOutboxDispatcher::class)->dispatchDue());
        Queue::assertPushed(SendWebhook::class, 3);
        $this->assertSame(3, WebhookOutbox::distinct()->count('event_id'));
    }

    public function test_successful_log_recovers_delivery_mark_after_worker_crash(): void
    {
        $event = $this->pay($this->order());
        $event->order->webhookLogs()->create([
            'event_id' => $event->event_id, 'event' => $event->event, 'url' => 'https://merchant.example/hook',
            'payload' => $event->payload, 'success' => true, 'response_code' => 200,
        ]);
        (new SendWebhook($event->order, $event->event, $event->event_id))->handle();
        $this->assertSame('delivered', $event->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_expired_publisher_cannot_acknowledge_a_newer_claim_and_both_jobs_share_identity(): void
    {
        $event = $this->pay($this->order());
        $identities = [];
        Queue::shouldReceive('push')->twice()->andReturnUsing(function ($job) use ($event, &$identities) {
            $identities[] = $job->outboxEventId;
            if (count($identities) === 1) {
                // Simulate a publisher paused beyond its lease, then a second
                // publisher accepting the job before the original resumes.
                $event->update(['claimed_at' => now()->subSeconds(121), 'available_at' => now()->subSecond()]);
                $this->assertTrue(app(WebhookOutboxDispatcher::class)->dispatchOne($event->id));
            }

            return 'accepted-queue-id';
        });
        $this->assertTrue(app(WebhookOutboxDispatcher::class)->dispatchOne($event->id));
        $this->assertSame([$event->event_id, $event->event_id], $identities);
        $this->assertSame(2, $event->fresh()->dispatch_attempts);
        $this->assertSame('queued', $event->fresh()->status);
        $this->assertNull($event->fresh()->claim_token);
        $this->assertTrue($event->fresh()->available_at->eq(now()->addSeconds(config('webhooks.reconcile_seconds'))));
    }

    public function test_database_rejects_duplicate_event_ids_and_duplicate_paid_transition_keys(): void
    {
        $event = $this->pay($this->order());
        foreach (['event_id', 'transition_key'] as $duplicateField) {
            $copy = $event->getAttributes();
            unset($copy['id']);
            $copy['sequence'] = 2;
            if ($duplicateField === 'event_id') {
                $copy['transition_key'] = 'different-transition';
            } else {
                $copy['event_id'] = (string) Str::uuid();
            }
            try {
                DB::transaction(fn () => DB::table('webhook_outbox')->insert($copy));
                $this->fail('The duplicate identity must be rejected by the database.');
            } catch (UniqueConstraintViolationException) {
                $this->assertDatabaseCount('webhook_outbox', 1);
            }
        }
    }

    public function test_outbox_sandbox_snapshot_never_uses_live_destination_or_secret_after_model_changes(): void
    {
        $this->merchant->regenerateSandboxApiCredentials();
        $this->merchant->update(['sandbox_webhook_url' => 'https://sandbox.example/hook']);
        $order = app(OrderService::class)->create($this->merchant, ['amount' => 100], Order::SANDBOX);
        app(OrderService::class)->simulateSandbox($order, 'success');
        $event = WebhookOutbox::where('order_id', $order->id)->firstOrFail();
        $order->forceFill(['environment' => Order::LIVE])->save();
        Http::fake(['sandbox.example/*' => Http::response('ok')]);
        (new SendWebhook($order->fresh(), $event->event, $event->event_id))->handle();
        Http::assertSentCount(1);
        $request = Http::recorded()[0][0];
        $this->assertSame('https://sandbox.example/hook', $request->url());
        $this->assertSame(Order::SANDBOX, $request['data']['environment']);
        $this->assertTrue($request['data']['test']);
        $this->assertTrue(WebhookSignature::verify($request->body(), $request->header('X-Signature')[0], $this->merchant->fresh()->sandbox_api_secret));
        $this->assertSame(hash_hmac('sha256', $request->body(), $this->merchant->fresh()->sandbox_api_secret), $request->header('X-Signature-Legacy')[0]);
        $this->assertSame('delivered', $event->fresh()->status);
    }

    public function test_stale_failure_cannot_change_a_newer_publication_or_its_active_claim(): void
    {
        Queue::fake();
        $event = $this->pay($this->order());
        $dispatcher = app(WebhookOutboxDispatcher::class);
        $dispatcher->dispatchDue();
        $oldJob = unserialize(serialize(Queue::pushed(SendWebhook::class)->first()));
        $this->assertSame(1, $oldJob->publicationGeneration);
        $this->travel(config('webhooks.reconcile_seconds') + 1)->seconds();
        Queue::shouldReceive('push')->once()->andReturnUsing(function ($job) use ($event, $oldJob) {
            $before = $event->fresh()->getAttributes();
            $this->assertSame(2, $job->publicationGeneration);
            $this->assertNotNull($before['claim_token']);
            $oldJob->failed(new RuntimeException('An older publication exhausted its retries.'));
            $this->assertSame($before, $event->fresh()->getAttributes());

            return 'queued';
        });
        $this->assertTrue($dispatcher->dispatchOne($event->id));
        $before = $event->fresh()->getAttributes();
        $oldJob->failed(new RuntimeException('Even after acknowledgement, the old generation is stale.'));
        $this->assertSame($before, $event->fresh()->getAttributes());
        $this->assertSame('queued', $event->fresh()->status);
    }

    public function test_failure_without_generation_or_during_its_own_active_claim_cannot_mutate_outbox(): void
    {
        $event = $this->pay($this->order());
        $event->update(['dispatch_attempts' => 1, 'claim_token' => (string) Str::uuid(), 'claimed_at' => now()]);
        $before = $event->fresh()->getAttributes();
        foreach ([null, 1] as $generation) {
            $job = new SendWebhook($event->order, $event->event, $event->event_id, $generation);
            $job = unserialize(serialize($job));
            $job->failed(new RuntimeException('Do not steal a publisher claim.'));
            $this->assertSame($before, $event->fresh()->getAttributes());
        }
        $event->update(['status' => 'queued', 'claim_token' => null, 'claimed_at' => null]);
        $before = $event->fresh()->getAttributes();
        $legacy = unserialize(serialize(new SendWebhook($event->order, $event->event, $event->event_id)));
        $this->assertNull($legacy->publicationGeneration);
        $legacy->failed(new RuntimeException('A pre-generation job must defer to reconciliation.'));
        $this->assertSame($before, $event->fresh()->getAttributes());
        Http::fake(['merchant.example/*' => Http::response('ok')]);
        $legacy->handle();
        $this->assertSame('delivered', $event->fresh()->status);
        Http::assertSentCount(1);
    }

    public static function removedWebhookConfiguration(): array
    {
        return ['url removed' => ['url'], 'secret removed' => ['secret'], 'already blocked' => ['blocked']];
    }

    #[DataProvider('removedWebhookConfiguration')]
    public function test_success_log_is_reconciled_even_after_configuration_is_removed(string $change): void
    {
        $event = $this->pay($this->order());
        $event->order->webhookLogs()->create([
            'event_id' => $event->event_id, 'event' => $event->event, 'url' => 'https://merchant.example/hook',
            'payload' => $event->payload, 'success' => true, 'response_code' => 200,
        ]);
        $this->merchant->update($change === 'secret' ? ['api_secret' => null] : ['webhook_url' => null]);
        if ($change === 'blocked') {
            $event->update(['status' => 'blocked', 'last_error' => 'configuration_missing', 'available_at' => null]);
        }
        (new SendWebhook($event->order->fresh(), $event->event, $event->event_id))->handle();
        $this->assertSame('delivered', $event->fresh()->status);
        $this->assertNotNull($event->fresh()->delivered_at);
        $this->assertNull($event->fresh()->last_error);
        $this->assertDatabaseCount('webhook_logs', 1);
        Http::assertNothingSent();
    }
}
