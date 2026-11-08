<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\VerificationLeaseRecoveryAudit;
use App\Models\WebhookOutbox;
use App\Services\OrderService;
use App\Services\Verifiers\VerificationResult;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProviderVerificationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private Order $order;

    protected function tearDown(): void
    {
        try {
            if ($this->app && Schema::hasTable('webhook_outbox')) {
                DB::table('webhook_outbox')->delete();
            }
            if ($this->app && Schema::hasTable('verification_lease_recovery_audits')) {
                DB::table('verification_lease_recovery_audits')->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
        Http::preventStrayRequests();

        $merchant = User::create([
            'name' => 'Concurrency Merchant',
            'email' => 'concurrency-merchant@example.com',
            'password' => 'password12345',
        ]);
        $merchant->regenerateApiCredentials();
        $merchant->update(['webhook_url' => 'https://merchant.example/webhook']);
        $merchant->paymentAccounts()->create([
            'provider' => 'paytm_business',
            'label' => 'Concurrency Account',
            'payee_name' => 'Payee Test',
            'upi_id' => 'test@upi',
            'credentials' => ['mid' => 'TESTMID'],
        ]);

        $this->order = app(OrderService::class)->create($merchant, ['amount' => 100])->load('paymentAccount');
    }

    public function test_mysql_first_provider_request_active_after_lease_expiry_prevents_second_process_verification(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL and pcntl for multi-process verification testing.');
        }

        $orderId = $this->order->id;
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $this->assertNotFalse($server, $errorMessage);
        $address = stream_socket_get_name($server, false);
        $url = 'http://'.$address.'/status';
        DB::disconnect();

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('Could not fork child process 1.');
        }

        if ($pid === 0) {
            try {
                DB::purge();
                Http::allowStrayRequests([$url.'*']);
                config(['gateway.paytm_status_url' => $url]);
                $order = Order::with('paymentAccount')->findOrFail($orderId);
                app(OrderService::class)->check($order, force: true);

                DB::disconnect();
                exit(0);
            } catch (\Throwable $e) {
                exit(1);
            }
        }

        stream_set_timeout($server, 15);
        try {
            DB::purge();
            $connection = stream_socket_accept($server, 15);
            $this->assertNotFalse($connection, 'The child must enter the real provider HTTP request.');
            stream_set_timeout($connection, 15);
            while (($line = fgets($connection)) !== false && trim($line) !== '') {
                // Consume request headers while leaving the HTTP response open.
            }

            $this->assertTrue(posix_kill($pid, 0), 'The first provider process must still be active.');
            DB::table('orders')->where('id', $orderId)->update([
                'verification_expires_at' => now()->subSeconds(10),
                'verification_started_at' => now()->subSeconds(305),
            ]);
            DB::table('cache_locks')->where('key', 'order-verification:'.$orderId)->delete();

            $fresh = Order::findOrFail($orderId);
            $this->assertTrue($fresh->verification_expires_at->isPast(), 'Lease must be expired.');
            Http::fake();
            app(OrderService::class)->check($fresh, force: true);
            Http::assertNothingSent();
            $this->assertSame(1, $fresh->fresh()->verification_generation);

            $body = json_encode([
                'STATUS' => 'TXN_SUCCESS', 'ORDERID' => $fresh->order_no,
                'TXNAMOUNT' => '100.00', 'CURRENCY' => 'INR',
                'BANKTXNID' => '123456789012', 'TXNID' => 'TXN-REAL-HTTP',
                'TXNDATE' => now()->format('Y-m-d H:i:s'),
            ]);
            fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body);
            fclose($connection);
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));

            DB::purge();
            $completedOrder = Order::findOrFail($orderId);
            $this->assertSame(Order::PAID, $completedOrder->status);
            $this->assertSame(1, $completedOrder->verification_generation);
            $this->assertNull($completedOrder->verification_token);
            $this->assertSame(1, WebhookOutbox::where('order_id', $orderId)->count());
        } finally {
            fclose($server);
        }
    }

    public function test_mysql_stale_owner_resuming_after_newer_owner_acquires_lock_is_fenced_out(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL and pcntl for multi-process verification testing.');
        }

        $orderId = $this->order->id;
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        DB::disconnect();

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('Could not fork child process.');
        }

        if ($pid === 0) {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 15);
            try {
                DB::purge();
                $order = Order::with('paymentAccount')->findOrFail($orderId);
                $service = app(OrderService::class);

                // Child acquires Generation 1 lease
                $lease1 = $service->acquireVerificationLease($order);
                if (! $lease1 || $lease1->generation !== 1) {
                    exit(1);
                }

                // Release generation 1 normally, but retain its immutable lease
                // value to simulate delayed stale application work.
                $service->releaseVerificationLease($order, $lease1);
                fwrite($pair[1], json_encode(['status' => 'ready_to_commit', 'generation' => $lease1->generation])."\n");

                // Wait for parent to take over and increment generation
                $instruction = trim((string) fgets($pair[1]));
                if ($instruction !== 'resume_stale_commit') {
                    exit(2);
                }

                // Stale child attempts to commit payment with its stale Generation 1 lease
                $staleResult = VerificationResult::fromPaytmBusiness($order, 100, '123456789012', 'TXN-STALE-CHILD');
                $service->markPaid($order, $staleResult, $lease1);
                $service->releaseVerificationLease($order, $lease1);

                DB::disconnect();
                exit(0);
            } catch (\Throwable $e) {
                exit(3);
            }
        }

        fclose($pair[1]);
        stream_set_timeout($pair[0], 15);
        try {
            DB::purge();
            $msg = json_decode(trim((string) fgets($pair[0])), true);
            $this->assertSame('ready_to_commit', $msg['status'] ?? null);
            $this->assertSame(1, $msg['generation'] ?? null);

            $order = Order::with('paymentAccount')->findOrFail($orderId);
            $lease2 = app(OrderService::class)->acquireVerificationLease($order);
            $this->assertNotNull($lease2);
            $this->assertSame(2, $lease2->generation, 'Newer owner must have generation 2.');

            // Newer owner commits payment
            $validResult = VerificationResult::fromPaytmBusiness($order, 100, '123456789012', 'TXN-VALID-OWNER-2');
            app(OrderService::class)->markPaid($order, $validResult, $lease2);
            app(OrderService::class)->releaseVerificationLease($order, $lease2);

            $this->assertSame(Order::PAID, $order->fresh()->status);
            $this->assertSame(1, WebhookOutbox::where('order_id', $orderId)->count());

            // Signal stale child to resume its commit attempt
            fwrite($pair[0], "resume_stale_commit\n");
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));

            // Verify: exactly one valid payment transition and one paid outbox event!
            DB::purge();
            $finalOrder = Order::findOrFail($orderId);
            $this->assertSame(Order::PAID, $finalOrder->status);
            $this->assertSame(2, $finalOrder->verification_generation);
            $this->assertSame('TXN-VALID-OWNER-2', $finalOrder->provider_txn_id);
            $this->assertSame(1, WebhookOutbox::where('order_id', $orderId)->where('event', 'order.paid')->count());
        } finally {
            fclose($pair[0]);
        }
    }

    public function test_mysql_real_provider_http_execution_remains_exclusive_beyond_300_seconds(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork') || ! filter_var(env('RUN_LONG_CONCURRENCY_TESTS', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set RUN_LONG_CONCURRENCY_TESTS=true on MySQL to run the 301-second HTTP lifecycle test.');
        }

        $orderId = $this->order->id;
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $this->assertNotFalse($server, $errorMessage);
        $url = 'http://'.stream_socket_get_name($server, false).'/status';
        DB::disconnect();
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('Could not fork the long-running provider worker.');
        }
        if ($pid === 0) {
            try {
                DB::purge();
                Http::allowStrayRequests([$url.'*']);
                config(['gateway.paytm_status_url' => $url]);
                app(OrderService::class)->check(Order::with('paymentAccount')->findOrFail($orderId), force: true);
                DB::disconnect();
                exit(0);
            } catch (\Throwable) {
                exit(1);
            }
        }

        $connection = false;
        try {
            stream_set_timeout($server, 15);
            $connection = stream_socket_accept($server, 15);
            $this->assertNotFalse($connection);
            while (($line = fgets($connection)) !== false && trim($line) !== '') {
                // Keep the provider response open.
            }
            $this->assertTrue(posix_kill($pid, SIGSTOP));
            $started = microtime(true);
            sleep(301);
            $this->assertGreaterThanOrEqual(300, microtime(true) - $started);
            $this->assertTrue(posix_kill($pid, 0));

            DB::purge();
            $fresh = Order::findOrFail($orderId);
            $this->assertTrue($fresh->verification_expires_at->isPast());
            Http::fake();
            app(OrderService::class)->check($fresh, force: true);
            Http::assertNothingSent();
            $this->assertSame(1, $fresh->fresh()->verification_generation);

            posix_kill($pid, SIGCONT);
            $body = json_encode(['STATUS' => 'PENDING']);
            fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body);
            fclose($connection);
            $connection = false;
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
            DB::purge();
            $this->assertSame(Order::PENDING, Order::findOrFail($orderId)->status);
            $this->assertSame(1, Order::findOrFail($orderId)->verification_generation);
            $this->assertNull(Order::findOrFail($orderId)->verification_token);
        } finally {
            @posix_kill($pid, SIGCONT);
            $waited = pcntl_waitpid($pid, $cleanupStatus, WNOHANG);
            if ($waited === 0) {
                @posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $cleanupStatus);
            }
            if (is_resource($connection)) {
                fclose($connection);
            }
            fclose($server);
        }
    }

    public function test_mysql_process_crash_and_recovery_after_lease_expiry(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL and pcntl for multi-process verification testing.');
        }

        $orderId = $this->order->id;
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        DB::disconnect();

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('Could not fork child process.');
        }

        if ($pid === 0) {
            fclose($pair[0]);
            DB::purge();
            $order = Order::with('paymentAccount')->findOrFail($orderId);

            // Child acquires lease
            $lease = app(OrderService::class)->acquireVerificationLease($order);
            if (! $lease) {
                exit(1);
            }

            // Signal parent that lease is acquired
            fwrite($pair[1], json_encode(['status' => 'acquired', 'pid' => getmypid()])."\n");
            fclose($pair[1]);

            // Abruptly kill self with SIGKILL (simulating process crash / segfault / unhandled death)
            posix_kill(getmypid(), SIGKILL);
            exit(99);
        }

        fclose($pair[1]);
        stream_set_timeout($pair[0], 15);
        try {
            DB::purge();
            $msg = json_decode(trim((string) fgets($pair[0])), true);
            $this->assertSame('acquired', $msg['status'] ?? null);

            // Wait for child to terminate
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifsignaled($status));
            $this->assertSame(SIGKILL, pcntl_wtermsig($status));

            // Child process is dead
            $this->assertFalse(posix_kill($pid, 0), 'Crashed child PID must not be alive.');

            // Advance lease past expiration
            DB::table('orders')->where('id', $orderId)->update([
                'verification_expires_at' => now()->subSecond(),
            ]);

            // Second process (Parent) attempts recovery
            $order = Order::with('paymentAccount')->findOrFail($orderId);
            $recoveredLease = app(OrderService::class)->acquireVerificationLease($order);
            $this->assertNotNull($recoveredLease, 'Crashed lock must be recoverable after lease expiry.');
            $this->assertSame(2, $recoveredLease->generation, 'Recovered lease must increment generation.');

            // Complete verification
            $result = VerificationResult::fromPaytmBusiness($order, 100, '123456789012', 'TXN-RECOVERED');
            app(OrderService::class)->markPaid($order, $result, $recoveredLease);
            app(OrderService::class)->releaseVerificationLease($order, $recoveredLease);

            $this->assertSame(Order::PAID, $order->fresh()->status);
            $this->assertSame(1, WebhookOutbox::where('order_id', $orderId)->count());
        } finally {
            fclose($pair[0]);
        }
    }

    public function test_mysql_zombie_owner_is_confirmed_terminated_before_recovery(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL and pcntl for zombie-process testing.');
        }

        $orderId = $this->order->id;
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        DB::disconnect();
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('Could not fork zombie-process worker.');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            DB::purge();
            $lease = app(OrderService::class)->acquireVerificationLease(Order::findOrFail($orderId));
            fwrite($pair[1], json_encode(['acquired' => $lease !== null])."\n");
            fclose($pair[1]);
            DB::disconnect();
            exit($lease ? 0 : 1);
        }

        fclose($pair[1]);
        $message = json_decode(trim((string) fgets($pair[0])), true);
        $this->assertTrue($message['acquired'] ?? false);
        // EOF confirms child exit, while intentionally delaying waitpid keeps
        // the terminated process as a zombie for the liveness inspection.
        stream_get_contents($pair[0]);
        fclose($pair[0]);
        usleep(100000);
        $this->assertTrue(posix_kill($pid, 0));

        DB::purge();
        DB::table('orders')->where('id', $orderId)->update(['verification_expires_at' => now()->subSecond()]);
        $lease = app(OrderService::class)->acquireVerificationLease(Order::findOrFail($orderId));
        $this->assertNotNull($lease);
        $this->assertSame(2, $lease->generation);
        pcntl_waitpid($pid, $status);
        $this->assertSame(0, pcntl_wexitstatus($status));
        app(OrderService::class)->releaseVerificationLease(Order::findOrFail($orderId), $lease);
    }

    public function test_mysql_concurrent_verification_processes_guarantee_exactly_one_payment_transition_and_one_outbox_event(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL and pcntl for multi-process verification testing.');
        }

        $orderId = $this->order->id;
        $pairs = [
            stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0),
            stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0),
        ];
        DB::disconnect();

        $pids = [];
        foreach ([0, 1] as $index) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Could not fork worker.');
            }

            if ($pid === 0) {
                foreach ($pairs as $i => $pair) {
                    foreach ($pair as $j => $sock) {
                        if (! ($i === $index && $j === 1)) {
                            fclose($sock);
                        }
                    }
                }
                stream_set_timeout($pairs[$index][1], 15);
                try {
                    DB::purge();
                    // Wait for start barrier from parent
                    fgets($pairs[$index][1]);

                    $order = Order::with('paymentAccount')->findOrFail($orderId);
                    $service = app(OrderService::class);
                    $lease = $service->acquireVerificationLease($order);

                    if ($lease) {
                        // Winner performs payment transition
                        $result = VerificationResult::fromPaytmBusiness($order, 100, '123456789012', 'TXN-WINNER-'.$index);
                        $service->markPaid($order, $result, $lease);
                        $service->releaseVerificationLease($order, $lease);
                        fwrite($pairs[$index][1], json_encode(['winner' => true, 'index' => $index])."\n");
                    } else {
                        fwrite($pairs[$index][1], json_encode(['winner' => false, 'index' => $index])."\n");
                    }

                    DB::disconnect();
                    exit(0);
                } catch (\Throwable $e) {
                    fwrite($pairs[$index][1], json_encode(['error' => $e->getMessage()])."\n");
                    exit(1);
                }
            }
            $pids[] = $pid;
        }

        foreach ($pairs as $pair) {
            fclose($pair[1]);
            stream_set_timeout($pair[0], 15);
        }

        // Release barrier to both workers simultaneously
        foreach ($pairs as $pair) {
            fwrite($pair[0], "start\n");
        }

        $results = [];
        foreach ($pairs as $pair) {
            $results[] = json_decode(trim((string) fgets($pair[0])), true);
            fclose($pair[0]);
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        DB::purge();
        $winners = array_filter($results, fn ($r) => ($r['winner'] ?? false) === true);
        $this->assertCount(1, $winners, 'Exactly one worker must win the verification lease.');

        $finalOrder = Order::findOrFail($orderId);
        $this->assertSame(Order::PAID, $finalOrder->status);
        $this->assertSame(1, $finalOrder->verification_generation);
        $this->assertSame(1, WebhookOutbox::where('order_id', $orderId)->count());
    }

    public function test_mysql_database_outages_do_not_create_or_release_ownership(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL and pcntl for connection-failure testing.');
        }

        $orderId = $this->order->id;
        $this->runDisconnectedChild(function () use ($orderId): int {
            config(['database.connections.mysql.port' => 1]);
            DB::purge('mysql');
            try {
                $order = new Order;
                $order->id = $orderId;
                app(OrderService::class)->acquireVerificationLease($order);

                return 1;
            } catch (\Throwable) {
                return 0;
            }
        });
        DB::purge();
        $this->assertNull(Order::findOrFail($orderId)->verification_token);
        $this->assertSame(0, Order::findOrFail($orderId)->verification_generation);

        $lease = app(OrderService::class)->acquireVerificationLease(Order::findOrFail($orderId));
        $this->assertNotNull($lease);
        $this->runDisconnectedChild(function () use ($orderId, $lease): int {
            config(['database.connections.mysql.port' => 1]);
            DB::purge('mysql');
            try {
                $order = new Order;
                $order->id = $orderId;
                app(OrderService::class)->releaseVerificationLease($order, $lease);

                return 1;
            } catch (\Throwable) {
                return 0;
            }
        });
        DB::purge();
        $this->assertSame($lease->token, Order::findOrFail($orderId)->verification_token);
        app(OrderService::class)->releaseVerificationLease(Order::findOrFail($orderId), $lease);
        $this->assertNull(Order::findOrFail($orderId)->verification_token);
    }

    public function test_mysql_duplicate_node_identity_is_rejected_and_unique_remote_node_fails_closed(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL and pcntl for multi-node identity testing.');
        }

        config([
            'gateway.verification_node_id' => 'shared-audit-node',
            'gateway.verification_node_installation_id' => 'installation-a',
            'gateway.verification_node_boot_id' => 'boot-a',
        ]);
        $lease = app(OrderService::class)->acquireVerificationLease($this->order);
        $this->assertNotNull($lease);
        DB::table('orders')->where('id', $this->order->id)->update(['verification_expires_at' => now()->subSecond()]);

        $duplicate = $this->runNodeWorker($this->order->id, 'shared-audit-node', 'installation-b', 'boot-b');
        $this->assertSame('duplicate', $duplicate['outcome']);
        $this->assertSame(1, Order::findOrFail($this->order->id)->verification_generation);
        $this->assertSame($lease->token, Order::findOrFail($this->order->id)->verification_token);

        $remote = $this->runNodeWorker($this->order->id, 'unique-audit-node-b', 'installation-b', 'boot-b');
        $this->assertSame('blocked', $remote['outcome']);
        $this->assertSame($lease->token, Order::findOrFail($this->order->id)->verification_token);
        $this->assertDatabaseCount('verification_nodes', 2);
    }

    public function test_mysql_concurrent_operator_recovery_creates_one_audit_record(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL and pcntl for concurrent recovery testing.');
        }

        $lease = app(OrderService::class)->acquireVerificationLease($this->order);
        $this->assertNotNull($lease);
        DB::table('orders')->where('id', $this->order->id)->update([
            'verification_expires_at' => now()->subSecond(),
            'verification_pid' => 99999999,
            'verification_process_identity' => 'ps:'.str_repeat('a', 64),
        ]);

        $pairs = [stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0), stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0)];
        $pids = [];
        DB::disconnect();
        foreach ([0, 1] as $index) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                foreach ($pairs as $i => $pair) {
                    fclose($pair[0]);
                    if ($i !== $index) {
                        fclose($pair[1]);
                    }
                }
                fgets($pairs[$index][1]);
                DB::purge();
                $code = Artisan::call('orders:recover-verification-lease', [
                    'order' => (string) $this->order->id,
                    'execution' => $lease->token,
                    'generation' => (string) $lease->generation,
                    'operator' => 'concurrency-operator',
                    'reason' => 'Confirmed terminated in concurrency test',
                    '--confirm-stopped' => true,
                ]);
                fwrite($pairs[$index][1], $code."\n");
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pairs as $pair) {
            fclose($pair[1]);
            fwrite($pair[0], "go\n");
        }
        $codes = [];
        foreach ($pairs as $pair) {
            $codes[] = (int) trim((string) fgets($pair[0]));
            fclose($pair[0]);
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        sort($codes);
        DB::purge();
        $this->assertSame([0, 1], $codes);
        $this->assertNull(Order::findOrFail($this->order->id)->verification_token);
        $this->assertSame(1, VerificationLeaseRecoveryAudit::where('order_id', $this->order->id)->count());
    }

    /** @return array{outcome: string} */
    private function runNodeWorker(int $orderId, string $nodeId, string $installationId, string $bootId): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        DB::disconnect();
        $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($pair[0]);
            DB::purge();
            config([
                'gateway.verification_node_id' => $nodeId,
                'gateway.verification_node_installation_id' => $installationId,
                'gateway.verification_node_boot_id' => $bootId,
            ]);
            try {
                $lease = app(OrderService::class)->acquireVerificationLease(Order::findOrFail($orderId));
                $outcome = $lease ? 'acquired' : 'blocked';
            } catch (\RuntimeException) {
                $outcome = 'duplicate';
            }
            fwrite($pair[1], json_encode(['outcome' => $outcome])."\n");
            exit(0);
        }
        fclose($pair[1]);
        $result = json_decode(trim((string) fgets($pair[0])), true);
        fclose($pair[0]);
        pcntl_waitpid($pid, $status);
        $this->assertSame(0, pcntl_wexitstatus($status));
        DB::purge();

        return $result;
    }

    private function runDisconnectedChild(callable $callback): void
    {
        DB::disconnect();
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('Could not fork database-outage worker.');
        }
        if ($pid === 0) {
            exit($callback());
        }
        pcntl_waitpid($pid, $status);
        $this->assertSame(0, pcntl_wexitstatus($status));
    }
}
