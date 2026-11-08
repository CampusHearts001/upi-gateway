<?php

namespace Tests\Feature;

use App\Jobs\SendWebhook;
use App\Models\Order;
use App\Models\User;
use App\Models\WebhookOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class CpanelQueueAndSchedulerReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private function createMerchantAndOrder(): array
    {
        $merchant = User::create([
            'name' => 'Cron Test Merchant',
            'business_name' => 'Cron Test Biz',
            'email' => 'cron@merchant.test',
            'password' => 'securepass12345',
            'webhook_url' => 'https://merchant.test/webhook',
        ]);
        $merchant->regenerateApiCredentials();
        $account = $merchant->paymentAccounts()->create([
            'provider' => 'paytm_business',
            'label' => 'Primary Account',
            'upi_id' => 'paytmqr123@paytm',
            'payee_name' => 'Cron Test Biz',
            'credentials' => ['mid' => 'TESTMID123'],
            'is_default' => true,
        ]);

        $order = Order::forceCreate([
            'user_id' => $merchant->id,
            'payment_account_id' => $account->id,
            'order_no' => 'ORD_CRON_'.Str::upper(Str::random(8)),
            'client_order_id' => 'CLIENT_CRON_'.Str::upper(Str::random(8)),
            'amount' => '150.00',
            'status' => Order::PENDING,
            'environment' => Order::LIVE,
            'expires_at' => now()->addMinutes(15),
            'original_expires_at' => now()->addMinutes(15),
        ]);

        return [$merchant, $order];
    }

    // ── 1. Queue Bounded Execution & Worker Lifecycle ────────────────────────

    public function test_queue_worker_executes_with_stop_when_empty_on_database_driver(): void
    {
        [$merchant, $order] = $this->createMerchantAndOrder();

        Http::fake([
            'https://merchant.test/*' => Http::response(['status' => 'ok'], 200),
        ]);

        // Push a webhook job onto the database queue
        SendWebhook::dispatch($order, 'order.paid')->onConnection('database')->onQueue('default');
        $this->assertSame(1, DB::table('jobs')->count());

        // Run queue:work with --stop-when-empty and --once
        $exitCode = Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'default',
            '--stop-when-empty' => true,
            '--once' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    // ── 2. Concurrent Worker & Duplicate Prevention ──────────────────────────

    public function test_concurrent_worker_reservation_and_delivery_lock_prevent_duplicate_execution(): void
    {
        [$merchant, $order] = $this->createMerchantAndOrder();
        $eventId = (string) Str::uuid();

        $outbox = WebhookOutbox::create([
            'event_id' => $eventId,
            'transition_key' => (string) Str::uuid(),
            'order_id' => $order->id,
            'merchant_id' => $merchant->id,
            'event' => 'order.paid',
            'environment' => Order::LIVE,
            'sequence' => 1,
            'payload' => [
                'event_id' => $eventId,
                'event' => 'order.paid',
                'event_sequence' => 1,
                'data' => array_merge($order->toApiArray(), ['environment' => Order::LIVE]),
            ],
            'status' => 'queued',
            'dispatch_attempts' => 1,
            'available_at' => now(),
        ]);

        // Simulate Worker 1 acquiring delivery cache lock
        $deliveryLock = Cache::lock('webhook-delivery:'.$eventId, 60);
        $this->assertTrue($deliveryLock->get());

        // Worker 2 attempts to process SendWebhook for the same event
        $job = new SendWebhook($order, 'order.paid', $eventId, 1);
        $job->handle();

        // Since delivery lock was held, Worker 2 released without sending or marking delivered
        $outbox->refresh();
        $this->assertNull($outbox->delivered_at);

        $deliveryLock->release();
    }

    // ── 3. Stuck Job Recovery After Lease Expiration ─────────────────────────

    public function test_stuck_job_is_recovered_after_retry_after_lease_expires(): void
    {
        [$merchant, $order] = $this->createMerchantAndOrder();

        Http::fake([
            'https://merchant.test/*' => Http::response(['status' => 'ok'], 200),
        ]);

        SendWebhook::dispatch($order, 'order.paid')->onConnection('database')->onQueue('default');
        $this->assertSame(1, DB::table('jobs')->count());

        // Simulate a crashed worker that reserved the job 100 seconds ago (retry_after is 90)
        DB::table('jobs')->update([
            'reserved_at' => time() - 100,
            'attempts' => 1,
        ]);

        // A new cPanel cron worker starts and picks up the abandoned job
        $exitCode = Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'default',
            '--stop-when-empty' => true,
            '--once' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    // ── 4. Retry Schedule & Backoff Configuration ────────────────────────────

    public function test_send_webhook_defines_correct_tries_and_exponential_backoff(): void
    {
        [$merchant, $order] = $this->createMerchantAndOrder();
        $job = new SendWebhook($order, 'order.paid');

        $this->assertSame(6, $job->tries);
        $this->assertSame([30, 120, 600, 1800, 3600], $job->backoff());
    }

    // ── 5. Structured Queue Logging ──────────────────────────────────────────

    public function test_structured_queue_logging_records_lifecycle_without_exposing_credentials(): void
    {
        [$merchant, $order] = $this->createMerchantAndOrder();

        Http::fake([
            'https://merchant.test/*' => Http::response(['status' => 'ok'], 200),
        ]);

        Log::spy();

        SendWebhook::dispatch($order, 'order.paid')->onConnection('database')->onQueue('default');

        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'default',
            '--stop-when-empty' => true,
            '--once' => true,
        ]);

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($merchant) {
            if ($message === 'Queue worker started processing job') {
                $serialized = json_encode($context);

                return isset($context['job_id'])
                    && isset($context['job_name'])
                    && ! str_contains($serialized, $merchant->api_secret)
                    && ! str_contains($serialized, 'password');
            }

            return true;
        });

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($merchant) {
            if ($message === 'Queue worker completed job successfully') {
                $serialized = json_encode($context);

                return isset($context['job_id'])
                    && ! str_contains($serialized, $merchant->api_secret)
                    && ! str_contains($serialized, 'password');
            }

            return true;
        });
    }

    // ── 6. Scheduler Distributed Lock ────────────────────────────────────────

    public function test_scheduler_distributed_lock_prevents_concurrent_orders_check_pending(): void
    {
        $lockKey = 'scheduler:orders:check-pending';
        $lock = Cache::lock($lockKey, 300);
        $this->assertTrue($lock->get());

        Log::spy();

        // Attempting to run orders:check-pending while lock is held
        $exitCode = Artisan::call('orders:check-pending');

        $this->assertSame(0, $exitCode);
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($lockKey) {
            return str_contains($message, 'another cycle is already in progress')
                && ($context['lock_key'] ?? '') === $lockKey;
        });

        $lock->release();
    }

    // ── 7. Scheduler Outbox Dispatching Integration ──────────────────────────

    public function test_scheduler_webhooks_dispatch_command_queues_due_outbox_events(): void
    {
        config(['queue.default' => 'database']);

        [$merchant, $order] = $this->createMerchantAndOrder();
        $eventId = (string) Str::uuid();

        WebhookOutbox::create([
            'event_id' => $eventId,
            'transition_key' => (string) Str::uuid(),
            'order_id' => $order->id,
            'merchant_id' => $merchant->id,
            'event' => 'order.paid',
            'environment' => Order::LIVE,
            'sequence' => 1,
            'payload' => [
                'event_id' => $eventId,
                'event' => 'order.paid',
                'event_sequence' => 1,
                'data' => array_merge($order->toApiArray(), ['environment' => Order::LIVE]),
            ],
            'status' => 'pending',
            'dispatch_attempts' => 0,
            'available_at' => now()->subMinute(),
        ]);

        $this->assertSame(0, DB::table('jobs')->count());

        // Run webhooks:dispatch (invoked by scheduler every minute)
        Artisan::call('webhooks:dispatch');

        // Event should now be queued into database jobs table
        $this->assertSame(1, DB::table('jobs')->count());
        $outbox = WebhookOutbox::where('event_id', $eventId)->first();
        $this->assertSame('queued', $outbox->status);
    }
}
