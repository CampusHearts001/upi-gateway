<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\WebhookOutbox;
use App\Services\OrderService;
use App\Services\Verifiers\VerificationResult;
use App\Services\WebhookOutboxDispatcher;
use App\Services\WebhookOutboxService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WebhookOutboxDatabaseTest extends TestCase
{
    // These cases use real commits, queue inserts and worker processing.
    use DatabaseMigrations;

    private Order $order;

    protected function tearDown(): void
    {
        // DatabaseMigrations tears down this disposable test schema. The real
        // migration deliberately refuses to delete retained production events.
        try {
            if ($this->app && Schema::hasTable('webhook_outbox')) {
                DB::table('webhook_outbox')->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        config(['queue.default' => 'database']);
        Http::preventStrayRequests();
        $merchant = User::create(['name' => 'DB Outbox', 'email' => 'db-outbox@example.com', 'password' => 'password12345']);
        $merchant->regenerateApiCredentials();
        $merchant->update(['webhook_url' => 'https://merchant.example/hook']);
        $merchant->paymentAccounts()->create([
            'provider' => 'paytm_business', 'label' => 'DB Outbox', 'payee_name' => 'Test', 'upi_id' => 'test@upi',
            'credentials' => ['mid' => 'TESTMID'],
        ]);
        $this->order = app(OrderService::class)->create($merchant, ['amount' => 100])->load('paymentAccount');
    }

    private function pay(): WebhookOutbox
    {
        $this->markProviderPaid($this->order, VerificationResult::fromPaytmBusiness(
            $this->order, 100, '123456789012', 'OUTBOX-DB-TXN'
        ));

        return WebhookOutbox::where('order_id', $this->order->id)->firstOrFail();
    }

    public function test_queue_outage_cannot_lose_committed_paid_intent_and_restoration_dispatches_it(): void
    {
        DB::statement('ALTER TABLE jobs RENAME TO audit_unavailable_jobs');
        try {
            $event = $this->pay();
            $this->assertSame(Order::PAID, $this->order->fresh()->status);
            $this->assertDatabaseCount('webhook_outbox', 1);
            $this->assertSame(0, app(WebhookOutboxDispatcher::class)->dispatchDue());
            $this->assertSame('pending', $event->fresh()->status);
            $this->assertSame('queue_insert_failed', $event->fresh()->last_error);
            $this->assertNull($event->fresh()->queued_at);
            $this->assertNull($event->fresh()->delivered_at);
        } finally {
            DB::statement('ALTER TABLE audit_unavailable_jobs RENAME TO jobs');
        }

        $id = $event->event_id;
        $this->travel(61)->seconds();
        $this->assertSame(1, app(WebhookOutboxDispatcher::class)->dispatchDue());
        $this->assertSame('queued', $event->fresh()->status);
        $this->assertSame($id, $event->fresh()->event_id);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(0, app(WebhookOutboxDispatcher::class)->dispatchDue());
        Http::assertNothingSent();
    }

    public function test_rollback_preserves_neither_paid_state_nor_outbox_intent(): void
    {
        try {
            DB::transaction(function () {
                $this->pay();
                $this->assertDatabaseCount('webhook_outbox', 1);
                throw new RuntimeException('Roll back after recording intent.');
            });
            $this->fail('The transaction must roll back.');
        } catch (RuntimeException $e) {
            $this->assertSame('Roll back after recording intent.', $e->getMessage());
        }
        $this->assertSame(Order::PENDING, $this->order->fresh()->status);
        $this->assertNull($this->order->fresh()->paid_at);
        $this->assertDatabaseCount('webhook_outbox', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_missing_outbox_table_rolls_back_payment_instead_of_losing_intent(): void
    {
        DB::statement('ALTER TABLE webhook_outbox RENAME TO audit_unavailable_outbox');
        try {
            try {
                $this->pay();
                $this->fail('Payment cannot commit without its durable intent.');
            } catch (QueryException) {
                $this->assertSame(Order::PENDING, $this->order->fresh()->status);
            }
        } finally {
            DB::statement('ALTER TABLE audit_unavailable_outbox RENAME TO webhook_outbox');
        }
        $this->assertDatabaseCount('webhook_outbox', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_record_without_a_real_transaction_is_rejected(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        try {
            app(WebhookOutboxService::class)->record($this->order, 'order.paid');
            $this->fail('Recording without a transaction must fail.');
        } catch (LogicException) {
            $this->assertDatabaseCount('webhook_outbox', 0);
        }
    }

    public function test_upgrade_preserves_existing_payment_and_webhook_history_without_inventing_events(): void
    {
        $migration = require database_path('migrations/2026_11_11_000001_create_webhook_outbox.php');
        $migration->down();
        $this->order->forceFill(['status' => Order::PAID, 'paid_at' => now(), 'utr' => '123456789012'])->save();
        $logId = DB::table('webhook_logs')->insertGetId([
            'order_id' => $this->order->id, 'event' => 'order.paid', 'url' => 'https://merchant.example/hook',
            'payload' => json_encode(['event' => 'order.paid', 'data' => ['status' => 'paid']]),
            'success' => true, 'response_code' => 200,
        ]);
        $beforeOrder = (array) DB::table('orders')->where('id', $this->order->id)->first();
        $beforeLog = (array) DB::table('webhook_logs')->where('id', $logId)->first();
        $migration->up();
        $afterOrder = (array) DB::table('orders')->where('id', $this->order->id)->first();
        $afterLog = (array) DB::table('webhook_logs')->where('id', $logId)->first();
        $this->assertSame(0, $afterOrder['webhook_event_sequence']);
        unset($afterOrder['webhook_event_sequence']);
        $this->assertNull($afterLog['event_id']);
        unset($afterLog['event_id']);
        $this->assertSame($beforeOrder, $afterOrder);
        $this->assertSame($beforeLog, $afterLog);
        $this->assertDatabaseCount('webhook_outbox', 0);
    }

    public function test_real_worker_retries_and_marks_delivery_after_success_only(): void
    {
        $event = $this->pay();
        Http::fake(['merchant.example/*' => Http::sequence()->push('failure', 500)->push('ok', 200)]);
        app(WebhookOutboxDispatcher::class)->dispatchDue();
        $this->assertSame('queued', $event->fresh()->status);
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--tries' => 6]);
        $this->assertNull($event->fresh()->delivered_at);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('webhook_logs', 1);
        $this->travel(31)->seconds();
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--tries' => 6]);
        $this->assertSame('delivered', $event->fresh()->status);
        $this->assertNotNull($event->fresh()->delivered_at);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        Http::assertSentCount(2);
        $this->assertSame(Http::recorded()[0][0]['event_id'], Http::recorded()[1][0]['event_id']);
    }

    public function test_stale_database_claim_and_lost_queue_job_are_reconciled(): void
    {
        $event = $this->pay();
        $event->update(['claim_token' => '00000000-0000-4000-8000-000000000001',
            'claimed_at' => now()->subSeconds(121), 'available_at' => now()->subSecond()]);
        $this->assertSame(1, app(WebhookOutboxDispatcher::class)->dispatchDue());
        $this->assertSame('queued', $event->fresh()->status);
        $this->assertDatabaseCount('jobs', 1);
        // Simulate queue loss only in this isolated test database.
        DB::table('jobs')->delete();
        $this->travel(config('webhooks.reconcile_seconds') + 1)->seconds();
        $this->assertSame(1, app(WebhookOutboxDispatcher::class)->dispatchDue());
        $this->assertSame(2, $event->fresh()->dispatch_attempts);
        $this->assertDatabaseCount('jobs', 1);
        $jobPayload = json_decode(DB::table('jobs')->value('payload'), true);
        $this->assertStringContainsString($event->event_id, $jobPayload['data']['command']);
        $this->assertNull($event->fresh()->delivered_at);
    }

    public function test_real_worker_exhaustion_retains_failed_job_and_republishes_same_event_later(): void
    {
        $event = $this->pay();
        Http::fake(['merchant.example/*' => Http::response('unavailable', 500)]);
        app(WebhookOutboxDispatcher::class)->dispatchDue();
        $delays = [30, 120, 600, 1800, 3600];
        foreach (range(0, 5) as $attempt) {
            Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--tries' => 6]);
            if ($attempt < 5) {
                $this->assertDatabaseCount('jobs', 1);
                $this->assertSame('queued', $event->fresh()->status);
                $this->travel($delays[$attempt] + 1)->seconds();
            }
        }
        Http::assertSentCount(6);
        $this->assertSame('failed', $event->fresh()->status);
        $this->assertSame('delivery_exhausted', $event->fresh()->last_error);
        $this->assertSame(6, $event->fresh()->delivery_attempts);
        $this->assertNull($event->fresh()->delivered_at);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertSame(0, app(WebhookOutboxDispatcher::class)->dispatchDue());
        $this->travel(config('webhooks.failed_retry_seconds') + 1)->seconds();
        $this->assertSame(1, app(WebhookOutboxDispatcher::class)->dispatchDue());
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertDatabaseCount('jobs', 1);
        $payload = json_decode(DB::table('jobs')->value('payload'), true);
        $this->assertStringContainsString($event->event_id, $payload['data']['command']);
    }

    public function test_mysql_concurrent_dispatchers_queue_one_job_for_a_fresh_event(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL and pcntl for independent dispatcher processes.');
        }
        $event = $this->pay();
        $id = $event->id;
        DB::disconnect();
        $pairs = [stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0), stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0)];
        $pids = [];
        foreach ([0, 1] as $index) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Could not fork a dispatcher.');
            }
            if ($pid === 0) {
                foreach ($pairs as $i => $pair) {
                    foreach ($pair as $j => $socket) {
                        if (! ($i === $index && $j === 1)) {
                            fclose($socket);
                        }
                    }
                }
                stream_set_timeout($pairs[$index][1], 15);
                try {
                    DB::purge();
                    fgets($pairs[$index][1]);
                    $result = app(WebhookOutboxDispatcher::class)->dispatchOne($id);
                    fwrite($pairs[$index][1], json_encode(['queued' => $result])."\n");
                    DB::disconnect();
                    exit(0);
                } catch (\Throwable $e) {
                    fwrite($pairs[$index][1], json_encode(['error_type' => get_class($e)])."\n");
                    exit(1);
                }
            }
            $pids[] = $pid;
        }
        foreach ($pairs as $pair) {
            stream_set_timeout($pair[0], 15);
            fwrite($pair[0], "start\n");
        }
        $results = [];
        foreach ($pairs as $pair) {
            $results[] = json_decode((string) fgets($pair[0]), true);
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        foreach ($pairs as $pair) {
            fclose($pair[0]);
            fclose($pair[1]);
        }
        DB::purge();
        $this->assertSame(1, count(array_filter($results, fn ($result) => ($result['queued'] ?? false) === true)));
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame('queued', $event->fresh()->status);
        $this->assertSame(1, $event->fresh()->dispatch_attempts);
        $this->assertNull($event->fresh()->delivered_at);
    }

    public function test_mysql_stale_worker_failure_cannot_steal_a_concurrent_publisher_claim(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL and pcntl for separate worker/publisher processes.');
        }
        $event = $this->pay();
        app(WebhookOutboxDispatcher::class)->dispatchOne($event->id);
        $oldJob = unserialize(json_decode(DB::table('jobs')->value('payload'), true)['data']['command']);
        $this->assertSame(1, $oldJob->publicationGeneration);
        $this->travel(config('webhooks.reconcile_seconds') + 1)->seconds();
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        DB::disconnect();
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('Could not fork a publisher.');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 15);
            try {
                DB::purge();
                Queue::createPayloadUsing(function () use ($pair) {
                    fwrite($pair[1], "claimed\n");
                    if (trim((string) fgets($pair[1])) !== 'resume') {
                        throw new RuntimeException('Publisher barrier timed out.');
                    }

                    return [];
                });
                $published = app(WebhookOutboxDispatcher::class)->dispatchOne($event->id);
                DB::disconnect();
                exit($published ? 0 : 1);
            } catch (\Throwable) {
                exit(2);
            }
        }
        fclose($pair[1]);
        stream_set_timeout($pair[0], 15);
        try {
            $this->assertSame('claimed', trim((string) fgets($pair[0])));
            DB::purge();
            $before = $event->fresh()->getAttributes();
            $this->assertSame(2, $before['dispatch_attempts']);
            $this->assertNotNull($before['claim_token']);
            $oldJob->failed(new RuntimeException('The older worker exhausted its retries.'));
            $this->assertSame($before, $event->fresh()->getAttributes());
        } finally {
            fwrite($pair[0], "resume\n");
            pcntl_waitpid($pid, $status);
            fclose($pair[0]);
            DB::purge();
        }
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertSame('queued', $event->fresh()->status);
        $this->assertNotNull($event->fresh()->queued_at);
        $this->assertNull($event->fresh()->claim_token);
        $this->assertDatabaseCount('jobs', 2);
        $before = $event->fresh()->getAttributes();
        $oldJob->failed(new RuntimeException('Old failure after the new publisher acknowledgement.'));
        $this->assertSame($before, $event->fresh()->getAttributes());
        Http::fake(['merchant.example/*' => Http::response('ok')]);
        foreach (range(1, 2) as $i) {
            Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--tries' => 6]);
        }
        Http::assertSentCount(1);
        $this->assertSame('delivered', $event->fresh()->status);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_completed_outbox_migration_can_be_rerun_without_resetting_events_or_counters(): void
    {
        $event = $this->pay();
        $beforeOrder = $this->order->fresh()->getAttributes();
        $beforeEvent = $event->getAttributes();
        $migration = require database_path('migrations/2026_11_11_000001_create_webhook_outbox.php');
        $migration->up();
        $migration->up();
        $this->assertSame($beforeOrder, $this->order->fresh()->getAttributes());
        $this->assertSame($beforeEvent, $event->fresh()->getAttributes());
        $this->assertOutboxConstraints();
    }

    public static function migrationDdlStages(): array
    {
        $stages = ['counter', 'table', 'event_unique', 'transition_unique', 'sequence_unique', 'due_index', 'foreign_key', 'log_column', 'log_index'];

        return array_combine($stages, array_map(fn ($stage) => [$stage], $stages));
    }

    #[DataProvider('migrationDdlStages')]
    public function test_mysql_interrupted_outbox_ddl_can_be_rerun_without_losing_history(string $stage): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL DDL commits and pcntl process interruption.');
        }
        $migration = require database_path('migrations/2026_11_11_000001_create_webhook_outbox.php');
        $migration->down();
        $migrationName = '2026_11_11_000001_create_webhook_outbox';
        $migrationPath = 'database/migrations/'.$migrationName.'.php';
        DB::table('migrations')->where('migration', $migrationName)->delete();
        $logId = DB::table('webhook_logs')->insertGetId([
            'order_id' => $this->order->id, 'event' => 'order.expired', 'url' => 'https://merchant.example/hook',
            'payload' => json_encode(['historical' => true]), 'success' => true, 'response_code' => 200,
        ]);
        $beforeOrder = (array) DB::table('orders')->where('id', $this->order->id)->first();
        $beforeLog = (array) DB::table('webhook_logs')->where('id', $logId)->first();
        $patterns = [
            'counter' => '/^alter table .orders. add .webhook_event_sequence./i',
            'table' => '/^create table .webhook_outbox./i',
            'event_unique' => '/^alter table .webhook_outbox. add unique .webhook_outbox_event_id_unique./i',
            'transition_unique' => '/^alter table .webhook_outbox. add unique .webhook_outbox_transition_key_unique./i',
            'sequence_unique' => '/^alter table .webhook_outbox. add unique .webhook_outbox_order_id_sequence_unique./i',
            'due_index' => '/^alter table .webhook_outbox. add index .outbox_dispatch_due./i',
            'foreign_key' => '/^alter table .webhook_outbox. add constraint .webhook_outbox_order_id_foreign./i',
            'log_column' => '/^alter table .webhook_logs. add .event_id./i',
            'log_index' => '/^alter table .webhook_logs. add index .webhook_logs_event_identity_success./i',
        ];
        DB::disconnect();
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('Could not fork migration process.');
        }
        if ($pid === 0) {
            DB::purge();
            DB::listen(function ($query) use ($patterns, $stage) {
                if (preg_match($patterns[$stage], $query->sql)) {
                    exit(77); // DDL completed; migration repository has not acknowledged it.
                }
            });
            Artisan::call('migrate', ['--path' => $migrationPath, '--force' => true]);
            exit(99);
        }
        pcntl_waitpid($pid, $status);
        DB::purge();
        // Repair before asserting so even a failed crash-point assertion leaves
        // the disposable schema usable by migration teardown.
        $wasAcknowledged = DB::table('migrations')->where('migration', $migrationName)->exists();
        Artisan::call('migrate', ['--path' => $migrationPath, '--force' => true]);
        $migration->up();
        $this->assertSame(77, pcntl_wexitstatus($status), 'The requested DDL interruption must actually occur.');
        $this->assertFalse($wasAcknowledged);
        $this->assertDatabaseHas('migrations', ['migration' => $migrationName]);
        $this->assertOutboxConstraints();
        $afterOrder = (array) DB::table('orders')->where('id', $this->order->id)->first();
        $this->assertSame(0, $afterOrder['webhook_event_sequence']);
        unset($afterOrder['webhook_event_sequence']);
        $this->assertSame($beforeOrder, $afterOrder);
        $afterLog = (array) DB::table('webhook_logs')->where('id', $logId)->first();
        $this->assertNull($afterLog['event_id']);
        unset($afterLog['event_id']);
        $this->assertSame($beforeLog, $afterLog);
        $this->assertDatabaseCount('webhook_outbox', 0);
        // Confirm the recovered table can participate in an actual payment.
        $this->pay();
        $this->assertSame(Order::PAID, $this->order->fresh()->status);
        $this->assertDatabaseCount('webhook_outbox', 1);
    }

    private function assertOutboxConstraints(): void
    {
        foreach ([
            'webhook_outbox_event_id_unique', 'webhook_outbox_transition_key_unique',
            'webhook_outbox_order_id_sequence_unique', 'outbox_dispatch_due',
        ] as $index) {
            $this->assertTrue(Schema::hasIndex('webhook_outbox', $index));
        }
        $this->assertTrue(Schema::hasIndex('webhook_logs', 'webhook_logs_event_identity_success'));
        $foreign = collect(Schema::getForeignKeys('webhook_outbox'))->firstWhere('columns', ['order_id']);
        $this->assertNotNull($foreign);
        $this->assertSame('orders', $foreign['foreign_table']);
        $this->assertSame('restrict', $foreign['on_delete']);
    }
}
