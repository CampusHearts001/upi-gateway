<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\InstallationManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class CpanelInstallerTest extends TestCase
{
    use DatabaseMigrations;

    private function removeInstallationMarker(): void
    {
        @unlink(config('installer.installed_path', storage_path('installed')));
        @unlink(base_path('storage/installed'));
    }

    // ── 1. Requirements & PHP Extensions ─────────────────────────────────────

    public function test_server_and_php_requirements_check_passes_on_valid_environment(): void
    {
        $installer = app(InstallationManager::class);
        $requirements = $installer->checkRequirements();

        $names = array_column($requirements, 'name');
        $this->assertContains('PHP >= 8.2', $names);
        $this->assertContains('PDO MySQL (required)', $names);
        $this->assertContains('OpenSSL Extension', $names);
        $this->assertContains('Mbstring Extension', $names);
        $this->assertContains('Tokenizer Extension', $names);
        $this->assertContains('JSON Extension', $names);
        $this->assertContains('cURL Extension', $names);
        $this->assertContains('Fileinfo Extension', $names);
        $this->assertContains('XML Extension', $names);
        $this->assertContains('Ctype Extension', $names);
        $this->assertContains('BCMath Extension', $names);
        $this->assertContains('storage/ writable', $names);
        $this->assertContains('bootstrap/cache/ writable', $names);
        $this->assertContains('.env writable', $names);

        $this->assertTrue($installer->allRequirementsPassed());
    }

    public function test_missing_php_extension_is_detected_and_blocks_requirements_pass(): void
    {
        $installer = app(InstallationManager::class);

        // Simulate missing PDO MySQL
        $this->assertFalse($installer->allRequirementsPassed([
            'PDO MySQL (required)' => false,
        ]));

        // Simulate missing OpenSSL
        $this->assertFalse($installer->allRequirementsPassed([
            'OpenSSL Extension' => false,
        ]));

        // Simulate missing cURL
        $this->assertFalse($installer->allRequirementsPassed([
            'cURL Extension' => false,
        ]));
    }

    public function test_writable_directory_failure_is_detected_and_blocks_requirements_pass(): void
    {
        $installer = app(InstallationManager::class);

        // Simulate non-writable storage
        $this->assertFalse($installer->allRequirementsPassed([
            'storage/ writable' => false,
        ]));

        // Simulate non-writable bootstrap/cache
        $this->assertFalse($installer->allRequirementsPassed([
            'bootstrap/cache/ writable' => false,
        ]));
    }

    // ── 2. Database Verification & Credentials ───────────────────────────────

    public function test_database_credentials_verification_rejects_invalid_connection_without_exposing_credentials(): void
    {
        $installer = app(InstallationManager::class);
        $secretPassword = 'super-secret-production-password-12345';

        Log::spy();

        try {
            $installer->testDatabaseConnection([
                'db_connection' => 'mysql',
                'db_host' => '127.0.0.1',
                'db_port' => 65530, // Unlikely to have a MySQL server
                'db_database' => 'non_existent_db_xyz',
                'db_username' => 'invalid_user',
                'db_password' => $secretPassword,
            ]);
            $this->fail('Expected connection to fail.');
        } catch (\Throwable $e) {
            // Connection failed as expected
            $this->assertInstanceOf(\Throwable::class, $e);
        }

        // Verify installer error responses do not leak the secret password
        $this->removeInstallationMarker();
        $response = $this->post(route('install.database.save'), [
            'db_connection' => 'mysql',
            'db_host' => '127.0.0.1',
            'db_port' => 65530,
            'db_database' => 'non_existent_db_xyz',
            'db_username' => 'invalid_user',
            'db_password' => $secretPassword,
            'app_url' => 'https://gateway.example',
            'app_name' => 'Gateway',
        ]);

        $response->assertSessionHasErrors('db_host');
        $response->assertDontSee($secretPassword);
        $this->assertEmpty(session('_old_input.db_password'));
    }

    // ── 3. Secure .env & APP_KEY Generation ──────────────────────────────────

    public function test_app_key_generation_and_env_atomicity_and_permissions(): void
    {
        $installer = app(InstallationManager::class);
        $tempEnv = tempnam(sys_get_temp_dir(), 'test_env_');
        config(['installer.env_path' => $tempEnv]);

        try {
            $installer->writeEnvironment([
                'db_connection' => 'mysql',
                'db_host' => '127.0.0.1',
                'db_port' => 3306,
                'db_database' => 'upi_gateway_prod',
                'db_username' => 'upi_user',
                'db_password' => 'secret"with"quotes',
                'app_name' => 'Production UPI Gateway',
                'app_url' => 'https://payments.example.com',
            ]);

            $this->assertFileExists($tempEnv);
            $content = file_get_contents($tempEnv);

            // APP_KEY check
            $this->assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/=]{44}$/m', $content);

            // Production defaults check
            $this->assertStringContainsString('APP_ENV=production', $content);
            $this->assertStringContainsString('APP_DEBUG=false', $content);
            $this->assertStringContainsString('LOG_LEVEL=error', $content);
            $this->assertStringContainsString('SESSION_SECURE_COOKIE=true', $content);

            // Escaping check
            $this->assertStringContainsString('DB_PASSWORD="secret\"with\"quotes"', $content);

            // Permissions check (0600)
            $this->assertSame(0600, fileperms($tempEnv) & 0777);
        } finally {
            @unlink($tempEnv);
        }
    }

    // ── 4. Migrations & Required Tables ──────────────────────────────────────

    public function test_migration_verification_checks_all_core_tables(): void
    {
        $installer = app(InstallationManager::class);

        // DatabaseMigrations has already run all migrations for this test.
        $this->assertTrue($installer->databaseIsMigrated());

        // Drop one required table and verify databaseIsMigrated fails
        Schema::dropIfExists('webhook_outbox');
        $this->assertFalse($installer->databaseIsMigrated());
    }

    // ── 5. Administrator Creation & Guard ────────────────────────────────────

    public function test_administrator_creation_with_secure_password_hash_and_guard(): void
    {
        $this->removeInstallationMarker();
        $installer = app(InstallationManager::class);

        $admin = $installer->createAdminUser('Primary Admin', 'admin@gateway.com', 'securepass12345');

        $this->assertInstanceOf(User::class, $admin);
        $this->assertSame('Primary Admin', $admin->name);
        $this->assertSame('admin@gateway.com', $admin->email);
        $this->assertSame('admin', $admin->role);
        $this->assertTrue(Hash::check('securepass12345', $admin->password));

        // Check installation_guards table
        $this->assertDatabaseHas('installation_guards', [
            'name' => 'primary_admin',
            'user_id' => $admin->id,
        ]);
    }

    public function test_duplicate_admin_creation_is_rejected(): void
    {
        $this->removeInstallationMarker();
        $installer = app(InstallationManager::class);

        $installer->createAdminUser('First Admin', 'first@gateway.com', 'securepass12345');

        // Second attempt must throw RuntimeException
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('An administrator already exists.');
        $installer->createAdminUser('Second Admin', 'second@gateway.com', 'securepass12345');
    }

    // ── 6. Installation Lock & Marker Behavior ───────────────────────────────

    public function test_installation_marker_file_locks_installer_permanently(): void
    {
        $installer = app(InstallationManager::class);
        $markerPath = $installer->installedPath();

        $installer->markInstalled();
        $this->assertFileExists($markerPath);
        $this->assertTrue($installer->isInstalled());

        // Permissions on marker file
        $this->assertSame(0600, fileperms($markerPath) & 0777);

        // Visiting /install.php must return 403 Forbidden
        $response = $this->get('/install.php');
        $response->assertStatus(403);
        $response->assertSee('Application Already Installed');
        $response->assertSee('INSTALLATION LOCKED');
    }

    // ── 7. Database Outage & Reopen Prevention ───────────────────────────────

    public function test_database_outage_does_not_accidentally_reopen_installer_when_marker_exists(): void
    {
        $installer = app(InstallationManager::class);
        $installer->markInstalled();

        $this->assertTrue($installer->isInstalled());

        // Visiting /install.php with marker present remains locked
        $response = $this->get('/install.php');
        $response->assertStatus(403);
        $response->assertSee('Application Already Installed');
        $response->assertSee('INSTALLATION LOCKED');
    }

    public function test_existing_environment_with_missing_marker_and_database_outage_locks_all_installer_entries(): void
    {
        $this->removeInstallationMarker();
        $envPath = tempnam(sys_get_temp_dir(), 'gateway-existing-env-');
        file_put_contents($envPath, 'APP_ENV=production');
        $originalDefault = config('database.default');
        $originalMysql = config('database.connections.mysql');
        config([
            'installer.env_path' => $envPath,
            'database.default' => 'mysql',
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => 65530,
            'database.connections.mysql.database' => 'unavailable',
            'database.connections.mysql.username' => 'unavailable',
            'database.connections.mysql.password' => 'unavailable',
        ]);
        DB::purge('mysql');

        try {
            $this->assertTrue(app(InstallationManager::class)->isInstalled());
            $this->get('/install')->assertRedirect(route('home'));
            $this->post('/install/database', [])->assertRedirect(route('home'));
            $this->get('/install.php')->assertStatus(403);
            $this->assertSame('APP_ENV=production', file_get_contents($envPath));
        } finally {
            config(['database.default' => $originalDefault, 'database.connections.mysql' => $originalMysql]);
            DB::purge('mysql');
            @unlink($envPath);
            @unlink(base_path('storage/installed'));
        }
    }

    public function test_database_save_rejects_remote_database_host_for_security(): void
    {
        $this->removeInstallationMarker();
        $response = $this->from('/install/database')->post('/install/database', [
            'db_connection' => 'mysql',
            'db_host' => '198.51.100.5',
            'db_port' => 3306,
            'db_database' => 'gateway',
            'db_username' => 'gateway',
            'db_password' => 'secret123',
            'app_url' => 'https://gateway.example',
            'app_name' => 'Gateway',
        ]);

        $response->assertRedirect('/install/database');
        $response->assertSessionHasErrors('db_host');
        $errors = session('errors')->get('db_host');
        $this->assertStringContainsString('Database host must be local', $errors[0]);
    }

    public function test_unauthorized_visitor_cannot_access_migrate_or_admin_without_database_setup(): void
    {
        $this->removeInstallationMarker();

        // Direct GET to sensitive steps without authorized database session is blocked
        $this->get('/install/migrate')->assertStatus(403);
        $this->get('/install/admin')->assertStatus(403);

        // Direct POST to sensitive steps without authorized database session is blocked
        $this->post('/install/migrate')->assertStatus(403);
        $this->post('/install/admin', [
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'secretpass123',
            'password_confirmation' => 'secretpass123',
        ])->assertStatus(403);
    }

    public function test_incomplete_first_installation_recovers_during_database_outage_without_marking_installed(): void
    {
        $this->removeInstallationMarker();
        $envPath = sys_get_temp_dir().'/gateway-recovery-env-'.bin2hex(random_bytes(8));
        $oldDefault = config('database.default');
        $oldMysql = config('database.connections.mysql');
        $lockPath = storage_path('framework/testing/recovery-lock-'.bin2hex(random_bytes(8)));
        $progressPath = storage_path('framework/testing/recovery-progress-'.bin2hex(random_bytes(8)));
        config([
            'installer.env_path' => $envPath,
            'installer.setup_lock_path' => $lockPath,
            'installer.in_progress_path' => $progressPath,
        ]);
        $installer = app(InstallationManager::class);

        try {
            $this->get('/install')->assertOk();
            $this->assertTrue($installer->hasIncompleteSetup());
            file_put_contents($envPath, 'APP_ENV=production');
            config([
                'database.default' => 'mysql',
                'database.connections.mysql.host' => '127.0.0.1',
                'database.connections.mysql.port' => 65530,
            ]);
            DB::purge('mysql');
            $this->assertFalse($installer->isInstalled());
            $this->get('/install')->assertOk();
            $this->get('/install.php')->assertOk();
            $this->assertFileDoesNotExist(base_path('storage/installed'));
        } finally {
            config(['database.default' => $oldDefault, 'database.connections.mysql' => $oldMysql]);
            DB::purge('mysql');
            @unlink($envPath);
            @unlink($lockPath);
            @unlink($progressPath);
        }
    }

    public function test_local_database_host_validation_rules(): void
    {
        $installer = app(InstallationManager::class);

        // Valid local hosts
        $this->assertTrue($installer->isLocalDatabaseHost('127.0.0.1'));
        $this->assertTrue($installer->isLocalDatabaseHost('localhost'));
        $this->assertTrue($installer->isLocalDatabaseHost('::1'));
        $this->assertTrue($installer->isLocalDatabaseHost('127.0.0.2'));
        $this->assertTrue($installer->isLocalDatabaseHost('/var/run/mysqld/mysqld.sock'));
        $this->assertTrue($installer->isLocalDatabaseHost('/tmp/mysql.sock'));

        // Invalid remote hosts
        $this->assertFalse($installer->isLocalDatabaseHost('192.168.1.100'));
        $this->assertFalse($installer->isLocalDatabaseHost('10.0.0.1'));
        $this->assertFalse($installer->isLocalDatabaseHost('db.example.com'));
        $this->assertFalse($installer->isLocalDatabaseHost('198.51.100.2'));
    }

    // ── 8. Direct Access After Completion ────────────────────────────────────

    public function test_direct_access_to_wizard_routes_after_completion_is_blocked(): void
    {
        $installer = app(InstallationManager::class);
        $installer->markInstalled();

        $this->get('/install')->assertRedirect(route('home'));
        $this->get('/install/database')->assertRedirect(route('home'));
        $this->get('/install/migrate')->assertRedirect(route('home'));
        $this->get('/install/admin')->assertRedirect(route('home'));
        $this->get('/install/done')->assertRedirect(route('home'));
    }

    // ── 9. Root install.php Wizard Lifecycle ─────────────────────────────────

    public function test_root_install_php_renders_requirements_when_uninstalled(): void
    {
        $this->removeInstallationMarker();

        $response = $this->get('/install.php');
        $response->assertOk();
        $response->assertSee('Server & Extension Checks');
        $response->assertSee('PDO MySQL (required)');
        $response->assertSee('Continue to Database Setup');
    }

    public function test_root_install_php_rejects_post_without_csrf_token(): void
    {
        $this->removeInstallationMarker();

        $response = $this->post('/install.php?step=database', [
            '_token' => 'invalid-csrf-token',
            'db_host' => '127.0.0.1',
        ]);

        $response->assertStatus(403);
        $response->assertSee('Security session expired');
    }

    public function test_root_install_php_rejects_remote_database_host(): void
    {
        $this->removeInstallationMarker();
        $this->get('/install.php')->assertOk();
        $csrf = $_SESSION['_installer_csrf'];
        $originalMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $originalPost = $_POST;
        $originalGet = $_GET;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            '_token' => $csrf,
            'db_host' => 'db.attacker.com',
            'db_port' => 3306,
            'db_database' => 'testdb',
            'db_username' => 'testuser',
            'db_password' => 'testpass',
            'app_name' => 'Test',
            'app_url' => 'https://example.com',
        ];
        $_GET['step'] = 'database';
        try {
            $this->post('/install.php?step=database', $_POST)
                ->assertOk()->assertSee('Database host must be local');
        } finally {
            $_POST = $originalPost;
            $_GET = $originalGet;
            if ($originalMethod === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $originalMethod;
            }
        }
    }

    // ── 10. Procedural Installer HTTPS & Proxy Detection ─────────────────────

    public function test_installer_https_detection_with_direct_https(): void
    {
        require_once base_path('install.php');

        $this->assertTrue(installer_is_https(['HTTPS' => 'on']));
        $this->assertTrue(installer_is_https(['HTTPS' => '1']));
        $this->assertTrue(installer_is_https(['SERVER_PORT' => 443]));
        $this->assertTrue(installer_is_https(['SERVER_PORT' => '443']));
    }

    public function test_installer_https_detection_with_plain_http(): void
    {
        require_once base_path('install.php');

        $this->assertFalse(installer_is_https([]));
        $this->assertFalse(installer_is_https(['SERVER_PORT' => 80]));
        $this->assertFalse(installer_is_https(['HTTPS' => 'off', 'SERVER_PORT' => 80]));
    }

    public function test_installer_https_detection_rejects_spoofed_forwarded_headers_from_untrusted_ip(): void
    {
        require_once base_path('install.php');

        $untrustedClient = ['REMOTE_ADDR' => '203.0.113.195', 'SERVER_PORT' => 80];

        // Spoofed X-Forwarded-Proto
        $this->assertFalse(installer_is_https($untrustedClient + ['HTTP_X_FORWARDED_PROTO' => 'https']));

        // Spoofed X-Forwarded-Ssl
        $this->assertFalse(installer_is_https($untrustedClient + ['HTTP_X_FORWARDED_SSL' => 'on']));

        // Spoofed CF-Visitor
        $this->assertFalse(installer_is_https($untrustedClient + [
            'HTTP_CF_VISITOR' => '{"scheme":"https"}',
            'HTTP_CF_RAY' => 'fake-ray-id-1234',
        ]));
    }

    public function test_installer_https_detection_accepts_cloudflare_https_from_cloudflare_ip(): void
    {
        require_once base_path('install.php');

        // Cloudflare IPv4 range 173.245.48.0/20
        $cfServer = [
            'REMOTE_ADDR' => '173.245.48.15',
            'SERVER_PORT' => 80,
            'HTTP_CF_VISITOR' => '{"scheme":"https"}',
            'HTTP_CF_RAY' => '8e20abcd1234-BOM',
        ];
        $this->assertTrue(installer_is_https($cfServer));

        // Cloudflare IPv6 range 2606:4700::/32
        $cfServerIpv6 = [
            'REMOTE_ADDR' => '2606:4700:10::5',
            'SERVER_PORT' => 80,
            'HTTP_CF_VISITOR' => '{"scheme":"https"}',
            'HTTP_CF_RAY' => '8e20abcd1234-BOM',
        ];
        $this->assertTrue(installer_is_https($cfServerIpv6));

        // Cloudflare HTTP (non-secure visitor)
        $cfServerHttp = [
            'REMOTE_ADDR' => '173.245.48.15',
            'SERVER_PORT' => 80,
            'HTTP_CF_VISITOR' => '{"scheme":"http"}',
            'HTTP_CF_RAY' => '8e20abcd1234-BOM',
        ];
        $this->assertFalse(installer_is_https($cfServerHttp));
    }

    public function test_installer_https_detection_accepts_loopback_and_configured_trusted_proxies(): void
    {
        require_once base_path('install.php');

        // Local loopback proxy (127.0.0.1)
        $this->assertTrue(installer_is_https([
            'REMOTE_ADDR' => '127.0.0.1',
            'SERVER_PORT' => 80,
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]));
        $this->assertTrue(installer_is_https([
            'REMOTE_ADDR' => '127.0.0.1',
            'SERVER_PORT' => 80,
            'HTTP_X_FORWARDED_SSL' => 'on',
        ]));
        $this->assertFalse(installer_is_https([
            'REMOTE_ADDR' => '127.0.0.1',
            'SERVER_PORT' => 80,
            'HTTP_X_FORWARDED_PROTO' => 'http',
        ]));

        // IPv6 loopback (::1)
        $this->assertTrue(installer_is_https([
            'REMOTE_ADDR' => '::1',
            'SERVER_PORT' => 80,
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]));

        // Configured trusted proxy via TRUSTED_PROXIES
        $originalTrusted = getenv('TRUSTED_PROXIES');
        putenv('TRUSTED_PROXIES=192.168.1.50,10.10.0.0/16');
        try {
            $this->assertTrue(installer_is_https([
                'REMOTE_ADDR' => '192.168.1.50',
                'SERVER_PORT' => 80,
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ]));
            $this->assertTrue(installer_is_https([
                'REMOTE_ADDR' => '10.10.5.2',
                'SERVER_PORT' => 80,
                'HTTP_X_FORWARDED_SSL' => 'on',
            ]));
            // Non-matching private IP not in TRUSTED_PROXIES
            $this->assertFalse(installer_is_https([
                'REMOTE_ADDR' => '172.16.0.5',
                'SERVER_PORT' => 80,
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ]));
        } finally {
            if ($originalTrusted === false) {
                putenv('TRUSTED_PROXIES');
            } else {
                putenv("TRUSTED_PROXIES={$originalTrusted}");
            }
        }
    }

    // ── 11. Subdirectory & Missing Vendor Redirection Tests ─────────────────

    public function test_uninstalled_redirection_calculates_correct_subdirectory_installer_urls(): void
    {
        $cases = [
            '/' => '/install.php',
            '/repotest/' => '/repotest/install.php',
            '/repotest' => '/repotest/install.php',
            '/repotest/public/' => '/repotest/install.php',
            '/repotest/public' => '/repotest/install.php',
            '/repotest/public/index.php' => '/repotest/install.php',
            '/index.php' => '/install.php',
            '/public/' => '/install.php',
            '/public' => '/install.php',
            '/public/index.php' => '/install.php',
            '/sub/nested/dir/' => '/sub/nested/dir/install.php',
            '/sub/nested/dir/public/index.php' => '/sub/nested/dir/install.php',
        ];

        foreach ($cases as $path => $expected) {
            $dir = preg_match('~\.php$~i', $path) ? dirname($path) : rtrim($path, '/');
            $baseUri = rtrim(preg_replace('~/public$~i', '', $dir), '/');
            $actual = ($baseUri === '' ? '' : $baseUri).'/install.php';
            $this->assertSame($expected, $actual, "Failed resolving installer URL for path: {$path}");
        }
    }

    public function test_public_index_subprocess_uninstalled_redirection(): void
    {
        $tempDir = sys_get_temp_dir().'/upi_redirect_test_'.bin2hex(random_bytes(6));
        mkdir($tempDir.'/repotest/public', 0755, true);
        mkdir($tempDir.'/repotest/vendor', 0755, true);
        file_put_contents($tempDir.'/repotest/vendor/autoload.php', "<?php // mock autoload\n");
        copy(base_path('public/index.php'), $tempDir.'/repotest/public/index.php');

        $port = rand(18200, 19900);
        $cmd = sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($tempDir));
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptorSpec, $pipes);
        usleep(250000); // 250ms startup delay

        try {
            $testCases = [
                '/repotest/public/' => 'Location: /repotest/install.php',
                '/repotest/public/index.php' => 'Location: /repotest/install.php',
            ];

            foreach ($testCases as $uri => $expectedHeader) {
                $ch = curl_init("http://127.0.0.1:{$port}{$uri}");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HEADER, true);
                curl_setopt($ch, CURLOPT_NOBODY, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                $response = (string) curl_exec($ch);
                curl_close($ch);

                $this->assertStringContainsString(
                    $expectedHeader,
                    $response,
                    "Subprocess redirect failed for URI: {$uri}. Response:\n{$response}"
                );
                $this->assertStringContainsString('302 Found', $response);
            }
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            @unlink($tempDir.'/repotest/public/index.php');
            @unlink($tempDir.'/repotest/vendor/autoload.php');
            @rmdir($tempDir.'/repotest/public');
            @rmdir($tempDir.'/repotest/vendor');
            @rmdir($tempDir.'/repotest');
            @rmdir($tempDir);
        }
    }

    public function test_public_index_subprocess_renders_missing_vendor_card(): void
    {
        $tempDir = sys_get_temp_dir().'/upi_vendor_test_'.bin2hex(random_bytes(6));
        mkdir($tempDir.'/public', 0755, true);
        copy(base_path('public/index.php'), $tempDir.'/public/index.php');

        try {
            $cmd = sprintf(
                'php %s',
                escapeshellarg($tempDir.'/public/index.php')
            );
            $descriptorSpec = [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open($cmd, $descriptorSpec, $pipes);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('Missing Application Dependencies', $stdout);
            $this->assertStringContainsString('upi-gateway-production.zip', $stdout);
            $this->assertStringContainsString('SETUP ERROR', $stdout);
        } finally {
            @unlink($tempDir.'/public/index.php');
            @rmdir($tempDir.'/public');
            @rmdir($tempDir);
        }
    }

    public function test_passwordless_installer_progression_from_requirements_to_database(): void
    {
        $this->removeInstallationMarker();

        // 1. Requirements page renders without setup password prompt
        $reqPage = $this->get('/install');
        $reqPage->assertOk();
        $reqPage->assertSee('Server Requirements');
        $reqPage->assertSee('Continue to database setup');
        $reqPage->assertDontSee('Setup Password');
        $reqPage->assertDontSee('setup_password');

        // 2. Database page is accessible directly without HTTP 401 or Basic Auth
        $dbPage = $this->get('/install/database');
        $dbPage->assertOk();
        $dbPage->assertSee('Database Setup');
        $dbPage->assertDontSee('Setup Authentication');
        $dbPage->assertDontSee('setup_token');
        $dbPage->assertDontSee('Setup Password');
    }

    public function test_database_failure_allows_safe_retry_without_corrupting_state(): void
    {
        $this->removeInstallationMarker();
        $envPath = sys_get_temp_dir().'/gateway-fresh-env-'.bin2hex(random_bytes(6));
        $progressPath = storage_path('framework/testing/retry-progress-'.bin2hex(random_bytes(6)));
        $lockPath = storage_path('framework/testing/retry-lock-'.bin2hex(random_bytes(6)));
        config([
            'installer.env_path' => $envPath,
            'installer.in_progress_path' => $progressPath,
            'installer.setup_lock_path' => $lockPath,
        ]);

        try {
            // First attempt with non-connecting credentials fails cleanly
            $fail = $this->from('/install/database')->post('/install/database', [
                'db_connection' => 'mysql',
                'db_host' => '127.0.0.1',
                'db_port' => 65530, // Unused port triggers connection failure
                'db_database' => 'invalid_db',
                'db_username' => 'invalid_user',
                'db_password' => 'invalid_pass',
                'app_url' => 'https://gateway.example',
                'app_name' => 'Gateway',
            ]);

            $fail->assertRedirect('/install/database');
            $fail->assertSessionHasErrors('db_host');
            $this->assertFalse(session('installer.setup_authorized', false));

            // Database setup page remains accessible for retry
            $retryPage = $this->get('/install/database');
            $retryPage->assertOk();
            $retryPage->assertSee('Database Setup');
        } finally {
            @unlink($envPath);
            @unlink($progressPath);
            @unlink($lockPath);
        }
    }
}
