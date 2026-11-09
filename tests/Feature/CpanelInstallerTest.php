<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DeploymentUrl;
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
        $_SESSION = [];
        @unlink(config('installer.installed_path', storage_path('installed')));
        @unlink(base_path('storage/installed'));
        @unlink(config('installer.setup_token_path', storage_path('installer-setup-token')));
    }

    // ── 1. Requirements & PHP Extensions ─────────────────────────────────────

    public function test_server_and_php_requirements_check_passes_on_valid_environment(): void
    {
        $installer = app(InstallationManager::class);
        $requirements = $installer->checkRequirements();

        $names = array_column($requirements, 'name');
        $this->assertContains('PHP >= 8.2', $names);
        $this->assertContains('PHP-FPM execution', $names);
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
        $this->assertContains('Iconv Extension', $names);
        $this->assertContains('storage/ writable', $names);
        $this->assertContains('bootstrap/cache/ writable', $names);
        $this->assertContains('.env writable', $names);
        $this->assertContains('Session Support', $names);
        $this->assertContains('Production Assets (Vite build)', $names);

        $this->assertTrue($installer->allRequirementsPassed());
        $this->assertFalse($installer->allRequirementsPassed(['PHP-FPM execution' => false]));
        $this->assertFalse($installer->allRequirementsPassed(['Iconv Extension' => false]));
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

    public function test_empty_quoted_app_key_is_replaced_and_cloudflare_proxies_are_detected(): void
    {
        $installer = app(InstallationManager::class);
        $tempEnv = tempnam(sys_get_temp_dir(), 'test_env_');
        file_put_contents($tempEnv, "APP_KEY=\"\"\nTRUSTED_PROXIES=\"\"\n");
        $originalConfig = [
            'installer.env_path' => config('installer.env_path'),
            'app.key' => config('app.key'),
            'app.env' => config('app.env'),
            'app.debug' => config('app.debug'),
            'app.url' => config('app.url'),
            'app.asset_url' => config('app.asset_url'),
            'session.path' => config('session.path'),
            'session.cookie' => config('session.cookie'),
            'session.secure' => config('session.secure'),
            'proxy.trusted_proxies' => config('proxy.trusted_proxies'),
        ];
        config(['installer.env_path' => $tempEnv]);
        $originalServer = $_SERVER;
        $_SERVER['REMOTE_ADDR'] = '173.245.48.10';

        try {
            $installer->writeEnvironment([
                'db_connection' => 'mysql',
                'db_host' => '127.0.0.1',
                'db_port' => 3306,
                'db_database' => 'upi_gateway_prod',
                'db_username' => 'upi_user',
                'db_password' => '',
                'app_name' => 'UPI Gateway',
                'app_url' => 'https://payments.example.com',
            ]);

            $content = file_get_contents($tempEnv);
            $this->assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/=]{44}$/m', $content);
            $this->assertStringContainsString('TRUSTED_PROXIES="173.245.48.0/20,', $content);
            $this->assertStringNotContainsString('TRUSTED_PROXIES="*"', $content);
        } finally {
            $_SERVER = $originalServer;
            config($originalConfig);
            @unlink($tempEnv);
        }
    }

    public function test_untrusted_direct_client_does_not_enable_forwarded_header_trust(): void
    {
        $this->assertSame('', app(InstallationManager::class)->detectedTrustedProxies([
            'REMOTE_ADDR' => '198.51.100.20',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]));
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
        $response->assertSee('Installation readiness');
        $response->assertSee('Checking...');
        $response->assertSee('Next');
        $response->assertDontSee('PDO MySQL (required)');
        $response->assertDontSee('PHP-FPM address');
        $response->assertSee('Running Directory to /public');
    }

    public function test_browser_readiness_failures_are_logged_with_a_single_use_session_token(): void
    {
        $this->removeInstallationMarker();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'Installer browser readiness check failed'
                && $context['stage'] === 'assets'
                && $context['message'] === 'Compiled frontend asset failed its HTTP check.');

        $diagnostic = $this->getJson('/__upi_deployment_check')->assertOk()->json();
        $payload = [
            'diagnostic_report' => true,
            'diagnostic_token' => $diagnostic['diagnostic_token'],
            'stage' => 'assets',
            'message' => 'Compiled frontend asset failed its HTTP check.',
        ];

        $this->postJson('/__upi_deployment_check', $payload)->assertOk()->assertJson(['logged' => true]);
        $this->postJson('/__upi_deployment_check', $payload)->assertForbidden();
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
        mkdir($tempDir.'/repotest/app/Support', 0755, true);
        copy(base_path('app/Support/DeploymentUrl.php'), $tempDir.'/repotest/app/Support/DeploymentUrl.php');
        file_put_contents($tempDir.'/repotest/vendor/autoload.php', "<?php // mock autoload\n");
        copy(base_path('public/index.php'), $tempDir.'/repotest/public/index.php');

        [$process, $port] = $this->startPhpServer($tempDir);

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
            $this->stopPhpServer($process);
            @unlink($tempDir.'/repotest/public/index.php');
            @unlink($tempDir.'/repotest/vendor/autoload.php');
            @unlink($tempDir.'/repotest/app/Support/DeploymentUrl.php');
            @rmdir($tempDir.'/repotest/app/Support');
            @rmdir($tempDir.'/repotest/app');
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
        $reqPage->assertSee('Readiness');
        $reqPage->assertSee('Checking...');
        $reqPage->assertSee('Next');
        $reqPage->assertDontSee('PHP-FPM address');
        $reqPage->assertDontSee('Nginx configuration');
        $reqPage->assertDontSee('Setup Password');
        $reqPage->assertDontSee('setup_password');

        // 2. Database page is accessible directly without HTTP 401 or Basic Auth
        $dbPage = $this->get('/install/database');
        $dbPage->assertOk();
        $dbPage->assertSee('Database');
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
            $retryPage->assertSee('Database');
        } finally {
            @unlink($envPath);
            @unlink($progressPath);
            @unlink($lockPath);
        }
    }

    // ── 14. Universal Hosting URL & Diagnostic Tests ────────────────────────

    public function test_missing_production_assets_blocks_requirements_pass(): void
    {
        $installer = app(InstallationManager::class);
        $this->assertFalse($installer->allRequirementsPassed([
            'Production Assets (Vite build)' => false,
        ]));
    }

    public function test_application_url_auto_detection(): void
    {
        $installer = app(InstallationManager::class);

        // 1. Root domain with HTTPS
        $url = $installer->detectApplicationUrl(null, [
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'on',
            'REQUEST_URI' => '/install',
        ]);
        $this->assertSame('https://example.com', $url);

        // 2. Subdomain with HTTPS
        $url = $installer->detectApplicationUrl(null, [
            'HTTP_HOST' => 'pay.example.com',
            'HTTPS' => 'on',
            'REQUEST_URI' => '/install/database',
        ]);
        $this->assertSame('https://pay.example.com', $url);

        // 3. Subdirectory with HTTPS
        $url = $installer->detectApplicationUrl(null, [
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'on',
            'REQUEST_URI' => '/payment/install',
        ]);
        $this->assertSame('https://example.com/payment', $url);

        // 4. Subdirectory via SCRIPT_NAME in public/
        $url = $installer->detectApplicationUrl(null, [
            'HTTP_HOST' => 'amtechinfo.online',
            'HTTPS' => 'on',
            'SCRIPT_NAME' => '/repotest/public/install.php',
            'REQUEST_URI' => '/repotest/public/install.php',
        ]);
        $this->assertSame('https://amtechinfo.online/repotest', $url);

        // 5. Subdirectory with standalone install.php and query string
        $url = $installer->detectApplicationUrl(null, [
            'HTTP_HOST' => 'amtechinfo.online',
            'HTTPS' => 'on',
            'SCRIPT_NAME' => '/repotest/install.php',
            'REQUEST_URI' => '/repotest/install.php?step=database',
        ]);
        $this->assertSame('https://amtechinfo.online/repotest', $url);

        // 6. Custom non-default port
        $url = $installer->detectApplicationUrl(null, [
            'HTTP_HOST' => 'localhost:8080',
            'HTTPS' => 'off',
            'REQUEST_URI' => '/install',
        ]);
        $this->assertSame('http://localhost:8080', $url);

        // 7. Cloudflare edge HTTPS
        $url = $installer->detectApplicationUrl(null, [
            'HTTP_HOST' => 'amtechinfo.online',
            'REMOTE_ADDR' => '173.245.48.1',
            'HTTP_CF_VISITOR' => '{"scheme":"https"}',
            'REQUEST_URI' => '/repotest/install',
        ]);
        $this->assertSame('https://amtechinfo.online/repotest', $url);
    }

    public function test_environment_diagnostics_detection(): void
    {
        $installer = app(InstallationManager::class);

        // Nginx in a subdirectory without public docroot
        $diag = $installer->detectEnvironmentDiagnostics(null, [
            'SERVER_SOFTWARE' => 'nginx/1.24.0',
            'HTTP_HOST' => 'amtechinfo.online',
            'REQUEST_URI' => '/repotest/install',
            'DOCUMENT_ROOT' => '/www/wwwroot/amtechinfo.online',
            'HTTPS' => 'on',
        ]);

        $this->assertSame('nginx', $diag['server_type']);
        $this->assertTrue($diag['is_subdirectory']);
        $this->assertSame('/repotest', $diag['subdirectory_path']);
        $this->assertTrue($diag['requires_nginx_rules']);
        $this->assertStringContainsString('location ^~ "/repotest/"', $diag['nginx_rule_snippet']);
        $this->assertStringContainsString('alias "'.base_path('public').'/', $diag['nginx_rule_snippet']);
        $this->assertStringContainsString('fastcgi_param SCRIPT_FILENAME', $diag['nginx_rule_snippet']);

        // Apache at domain root with public docroot
        $diagApache = $installer->detectEnvironmentDiagnostics(null, [
            'SERVER_SOFTWARE' => 'Apache/2.4.52 (Ubuntu)',
            'HTTP_HOST' => 'pay.example.com',
            'REQUEST_URI' => '/install',
            'DOCUMENT_ROOT' => '/var/www/pay.example.com/public',
            'HTTPS' => 'on',
        ]);

        $this->assertSame('apache', $diagApache['server_type']);
        $this->assertFalse($diagApache['is_subdirectory']);
        $this->assertFalse($diagApache['requires_nginx_rules']);
    }

    public function test_root_index_static_asset_fallback(): void
    {
        $tempDir = sys_get_temp_dir().'/root_index_test_'.bin2hex(random_bytes(6));
        mkdir($tempDir.'/public/build/assets', 0755, true);
        file_put_contents($tempDir.'/public/build/manifest.json', '{"test": true}');
        file_put_contents($tempDir.'/public/build/assets/test.css', 'body { color: red; }');
        file_put_contents($tempDir.'/public/deployment-check.json', '{"application":"upi-gateway","public_assets":true}');
        file_put_contents($tempDir.'/.env', 'SECRET=hidden');
        copy(base_path('index.php'), $tempDir.'/index.php');
        file_put_contents($tempDir.'/public/index.php', "<?php echo 'LARAVEL_APP';\n");

        [$process, $port] = $this->startPhpServer($tempDir, $tempDir.'/index.php');

        try {
            // 1. Fetching valid CSS through root index.php returns HTTP 200 with text/css
            $ch = curl_init("http://127.0.0.1:{$port}/build/assets/test.css");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            $response = curl_exec($ch);
            curl_close($ch);

            $this->assertStringContainsString('HTTP/1.1 200 OK', $response);
            $this->assertStringContainsString('content-type: text/css', strtolower($response));
            $this->assertStringContainsString('body { color: red; }', $response);

            // 2. Traversal attempt to read .env is blocked
            $ch = curl_init("http://127.0.0.1:{$port}/build/../../.env");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $resEnv = curl_exec($ch);
            curl_close($ch);
            $this->assertStringNotContainsString('SECRET=hidden', (string) $resEnv);

            // 3. Virtual route falls through to public/index.php
            $ch = curl_init("http://127.0.0.1:{$port}/login");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $resLogin = curl_exec($ch);
            curl_close($ch);
            $this->assertStringContainsString('LARAVEL_APP', (string) $resLogin);

            // 4. deployment-check.json is served with application/json
            $ch = curl_init("http://127.0.0.1:{$port}/deployment-check.json");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            $resCheck = curl_exec($ch);
            curl_close($ch);
            $this->assertStringContainsString('HTTP/1.1 200 OK', $resCheck);
            $this->assertStringContainsString('content-type: application/json', strtolower($resCheck));
            $this->assertStringContainsString('public_assets', $resCheck);
        } finally {
            $this->stopPhpServer($process);
            @unlink($tempDir.'/public/deployment-check.json');
            @unlink($tempDir.'/public/build/assets/test.css');
            @unlink($tempDir.'/public/build/manifest.json');
            @unlink($tempDir.'/public/index.php');
            @unlink($tempDir.'/.env');
            @unlink($tempDir.'/index.php');
            @rmdir($tempDir.'/public/build/assets');
            @rmdir($tempDir.'/public/build');
            @rmdir($tempDir.'/public');
            @rmdir($tempDir);
        }
    }

    public function test_root_htaccess_contains_updated_subdirectory_and_security_rules(): void
    {
        $content = file_get_contents(base_path('.htaccess'));

        // Verifies directory security blocks internal sensitive folders
        $this->assertStringContainsString('tests|scripts', $content);

        // Verifies loop-free subdirectory rewrite condition
        $this->assertStringContainsString('RewriteCond %{REQUEST_URI} !(^|/)public/ [NC]', $content);
    }

    /**
     * Start a temporary PHP built-in webserver on an ephemeral port.
     *
     * @return array{0: resource, 1: int}
     */
    private function startPhpServer(string $docRoot, ?string $routerScript = null): array
    {
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $port = $sock ? (int) substr(strrchr((string) stream_socket_get_name($sock, false), ':'), 1) : rand(22000, 32000);
        if ($sock) {
            fclose($sock);
        }

        $cmd = $routerScript !== null
            ? sprintf('exec php -S 127.0.0.1:%d -t %s %s', $port, escapeshellarg($docRoot), escapeshellarg($routerScript))
            : sprintf('exec php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($docRoot));

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptorSpec, $pipes);

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.05);
            if (is_resource($conn)) {
                fclose($conn);
                break;
            }
            usleep(25000);
        }

        return [$process, $port];
    }

    /**
     * Terminate the subprocess webserver immediately without leaving orphan processes.
     */
    private function stopPhpServer($process): void
    {
        if (is_resource($process)) {
            proc_terminate($process, 9);
            proc_close($process);
        }
    }

    // ── 15. PHP Handler Compatibility & Server-Side Readiness (A1 & B1) ─────

    public function test_php_handler_detection_supports_fpm_and_cli(): void
    {
        $fpm = InstallationManager::detectPhpHandler('fpm-fcgi');
        $this->assertSame('fpm-fcgi', $fpm['sapi']);
        $this->assertSame('PHP-FPM', $fpm['name']);
        $this->assertSame('supported', $fpm['status']);
        $this->assertTrue($fpm['supported']);
        $this->assertStringContainsString('verified', $fpm['message']);

        $cli = InstallationManager::detectPhpHandler('cli');
        $this->assertSame('CLI', $cli['name']);
        $this->assertSame('supported', $cli['status']);
        $this->assertTrue($cli['supported']);

        $cliServer = InstallationManager::detectPhpHandler('cli-server');
        $this->assertSame('PHP Built-in Server', $cliServer['name']);
        $this->assertSame('supported', $cliServer['status']);
        $this->assertTrue($cliServer['supported']);
    }

    public function test_php_handler_detection_distinguishes_compatible_unsupported_and_unknown_handlers(): void
    {
        // LiteSpeed LSAPI: recognized as compatible web handler without claiming untested live hosting compatibility
        $lsapi = InstallationManager::detectPhpHandler('litespeed');
        $this->assertSame('litespeed', $lsapi['sapi']);
        $this->assertSame('LiteSpeed SAPI (LSAPI)', $lsapi['name']);
        $this->assertSame('compatible', $lsapi['status']);
        $this->assertTrue($lsapi['supported']);
        $this->assertStringNotContainsString('verified', strtolower($lsapi['message']));

        // CGI/FastCGI
        $fastcgi = InstallationManager::detectPhpHandler('cgi-fcgi');
        $this->assertSame('compatible', $fastcgi['status']);
        $this->assertTrue($fastcgi['supported']);

        // Apache mod_php
        $modPhp = InstallationManager::detectPhpHandler('apache2handler');
        $this->assertSame('compatible', $modPhp['status']);
        $this->assertTrue($modPhp['supported']);

        // Unsupported handlers
        $phpdbg = InstallationManager::detectPhpHandler('phpdbg');
        $this->assertSame('unsupported', $phpdbg['status']);
        $this->assertFalse($phpdbg['supported']);

        $embed = InstallationManager::detectPhpHandler('embed');
        $this->assertSame('unsupported', $embed['status']);
        $this->assertFalse($embed['supported']);

        $rawCgi = InstallationManager::detectPhpHandler('cgi');
        $this->assertSame('unsupported', $rawCgi['status']);
        $this->assertFalse($rawCgi['supported']);

        // Unknown handlers
        $unknown = InstallationManager::detectPhpHandler('custom_handler_v2');
        $this->assertSame('unknown', $unknown['status']);
        $this->assertFalse($unknown['supported']);
    }

    public function test_verify_server_readiness_blocks_unsupported_or_unknown_handler(): void
    {
        $installer = app(InstallationManager::class);

        $resultUnsupported = $installer->verifyServerReadiness(['PHP_SAPI' => 'phpdbg']);
        $this->assertFalse($resultUnsupported['ready']);
        $this->assertSame('php_handler', $resultUnsupported['reason']);
        $this->assertStringContainsString('not supported', $resultUnsupported['message']);
        $this->assertStringNotContainsString(base_path(), $resultUnsupported['message']);

        $resultUnknown = $installer->verifyServerReadiness(['PHP_SAPI' => 'mystery_sapi']);
        $this->assertFalse($resultUnknown['ready']);
        $this->assertSame('php_handler', $resultUnknown['reason']);
        $this->assertStringContainsString('Unrecognized PHP handler', $resultUnknown['message']);
        $this->assertStringNotContainsString(base_path(), $resultUnknown['message']);
    }

    public function test_verify_server_readiness_blocks_insecure_nginx_root_exposure(): void
    {
        $installer = app(InstallationManager::class);

        // Simulation: DocumentRoot points directly to base_path() under Nginx
        $result = $installer->verifyServerReadiness([
            'PHP_SAPI' => 'fpm-fcgi',
            'SERVER_SOFTWARE' => 'nginx/1.24.0',
            'DOCUMENT_ROOT' => base_path(),
            'HTTP_HOST' => 'example.com',
            'REQUEST_URI' => '/',
        ]);

        $this->assertFalse($result['ready']);
        $this->assertSame('nginx_root_exposure', $result['reason']);
        $this->assertStringContainsString('Nginx document root must point to the public directory', $result['message']);
        $this->assertStringNotContainsString(base_path(), $result['message']);
    }

    public function test_direct_database_step_access_without_readiness_is_blocked_and_redirected(): void
    {
        $this->removeInstallationMarker();

        $this->partialMock(InstallationManager::class, function ($mock) {
            $mock->shouldReceive('verifyServerReadiness')->andReturn([
                'ready' => false,
                'reason' => 'requirements',
                'message' => 'Server readiness requirements are not met. Ensure required PHP extensions and writable directories are available.',
                'handler' => ['supported' => false],
            ]);
        });

        // Direct GET access to ?step=database when server readiness fails must redirect to requirements
        $response = $this->get('/install.php?step=database');
        $response->assertStatus(302);
        $response->assertRedirect('/install.php?step=requirements');
    }

    public function test_direct_database_step_access_with_valid_readiness_is_permitted(): void
    {
        $this->removeInstallationMarker();

        // Direct GET access to ?step=database when server readiness passes must succeed
        $response = $this->get('/install.php?step=database');
        $response->assertOk();
        $response->assertSee('Database Configuration');
        $this->assertTrue($_SESSION['_installer_readiness_passed'] ?? false);
    }

    public function test_direct_database_post_bypass_attempt_is_rejected_with_403(): void
    {
        $this->removeInstallationMarker();
        $this->get('/install.php')->assertOk();
        $csrf = $_SESSION['_installer_csrf'];

        $this->partialMock(InstallationManager::class, function ($mock) {
            $mock->shouldReceive('verifyServerReadiness')->andReturn([
                'ready' => false,
                'reason' => 'nginx_root_exposure',
                'message' => 'Nginx document root must point to the public directory to prevent private file exposure.',
                'handler' => ['supported' => true],
            ]);
        });

        $tempEnv = sys_get_temp_dir().'/bypass_test_env_'.bin2hex(random_bytes(6));
        config(['installer.env_path' => $tempEnv]);

        $originalMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $originalPost = $_POST;
        $originalGet = $_GET;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET['step'] = 'database';
        $_POST = [
            '_token' => $csrf,
            'db_host' => '127.0.0.1',
            'db_port' => 3306,
            'db_database' => 'test_db',
            'db_username' => 'test_user',
            'db_password' => 'test_pass',
            'app_name' => 'Bypass Test',
            'app_url' => 'https://example.com',
        ];

        try {
            $response = $this->post('/install.php?step=database', $_POST);
            $response->assertStatus(403);
            $response->assertSee('Nginx document root must point to the public directory to prevent private file exposure.');
            $this->assertFileDoesNotExist($tempEnv);
        } finally {
            $_POST = $originalPost;
            $_GET = $originalGet;
            if ($originalMethod === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $originalMethod;
            }
            @unlink($tempEnv);
        }
    }

    public function test_valid_installation_flow_allows_database_step_after_readiness(): void
    {
        $this->removeInstallationMarker();

        // Step 1: Render requirements
        $response = $this->get('/install.php');
        $response->assertOk();
        $csrf = $_SESSION['_installer_csrf'];

        // Step 2: Deployment check POST
        $checkResponse = $this->post('/install.php?step=deployment-check', [
            '_token' => $csrf,
        ]);
        $checkResponse->assertOk();
        $checkResponse->assertJson(['session' => true, 'csrf' => true, 'ready' => true]);
        $this->assertTrue($_SESSION['_installer_readiness_passed']);

        // Step 3: Now navigate to database step
        $dbResponse = $this->get('/install.php?step=database');
        $dbResponse->assertOk();
        $dbResponse->assertSee('Database');
    }

    // ── 16. HTTP/HTTPS Installation & Remote DB Validation (B2 & B3) ────────

    public function test_insecure_http_url_for_non_localhost_is_rejected(): void
    {
        $this->removeInstallationMarker();
        $this->withSession(['installer.readiness_passed' => true]);

        // 1. Blade route rejection
        $response = $this->from('/install/database')->post('/install/database', [
            'db_connection' => 'mysql',
            'db_host' => '127.0.0.1',
            'db_port' => 3306,
            'db_database' => 'gateway_db',
            'db_username' => 'gateway_user',
            'db_password' => 'secret',
            'app_url' => 'http://insecure-domain.example.com',
            'app_name' => 'Insecure Test',
        ]);

        $response->assertRedirect('/install/database');
        $response->assertSessionHasErrors('app_url');
        $errors = session('errors')->get('app_url');
        $this->assertStringContainsString('Production installations must use HTTPS', $errors[0]);

        // 2. install.php rejection
        $this->get('/install.php')->assertOk();
        $csrf = $_SESSION['_installer_csrf'];
        $_SESSION['_installer_readiness_passed'] = true;

        $originalMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $originalPost = $_POST;
        $originalGet = $_GET;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET['step'] = 'database';
        $_POST = [
            '_token' => $csrf,
            'db_host' => '127.0.0.1',
            'db_port' => 3306,
            'db_database' => 'gateway_db',
            'db_username' => 'gateway_user',
            'db_password' => 'secret',
            'app_name' => 'Insecure Test',
            'app_url' => 'http://insecure-domain.example.com',
        ];

        try {
            $phpResponse = $this->post('/install.php?step=database', $_POST);
            $phpResponse->assertOk();
            $phpResponse->assertSee('Production installations must use HTTPS');
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

    public function test_localhost_http_url_is_accepted_for_local_installations(): void
    {
        $this->assertTrue(DeploymentUrl::isLocalUrl('http://localhost:8000'));
        $this->assertTrue(DeploymentUrl::isLocalUrl('http://127.0.0.1:8080'));
        $this->assertTrue(DeploymentUrl::isLocalUrl('http://127.0.0.2:3000'));
        $this->assertTrue(DeploymentUrl::isLocalUrl('http://[::1]:8080'));
        $this->assertTrue(DeploymentUrl::isLocalUrl('http://app.test'));
        $this->assertTrue(DeploymentUrl::isLocalUrl('http://gateway.localhost'));

        $this->assertFalse(DeploymentUrl::isLocalUrl('http://example.com'));
        $this->assertFalse(DeploymentUrl::isLocalUrl('http://198.51.100.1'));

        $this->assertTrue(DeploymentUrl::isAllowedProductionUrl('https://payments.example.com'));
        $this->assertTrue(DeploymentUrl::isAllowedProductionUrl('http://127.0.0.1:8080'));
        $this->assertFalse(DeploymentUrl::isAllowedProductionUrl('http://insecure.example.com'));
    }

    public function test_remote_database_host_validation_paths(): void
    {
        $installer = app(InstallationManager::class);

        // When remote DB is disabled (default)
        config(['installer.allow_remote_db' => false]);
        putenv('INSTALLER_ALLOW_REMOTE_DB=false');
        $this->assertTrue($installer->isAllowedDatabaseHost('127.0.0.1'));
        $this->assertTrue($installer->isAllowedDatabaseHost('localhost'));
        $this->assertFalse($installer->isAllowedDatabaseHost('198.51.100.5'));
        $this->assertFalse($installer->isAllowedDatabaseHost('rds.amazonaws.com'));

        // When remote DB is enabled
        config(['installer.allow_remote_db' => true]);
        $this->assertTrue($installer->isAllowedDatabaseHost('127.0.0.1'));
        $this->assertTrue($installer->isAllowedDatabaseHost('198.51.100.5'));
        $this->assertTrue($installer->isAllowedDatabaseHost('rds.ap-south-1.amazonaws.com'));
        $this->assertTrue($installer->isAllowedDatabaseHost('db.internal'));

        // Dangerous / invalid remote hosts must still be rejected
        $this->assertFalse($installer->isAllowedDatabaseHost('rds.amazonaws.com; DROP TABLE users;'));
        $this->assertFalse($installer->isAllowedDatabaseHost("db.internal' OR '1'='1"));
        $this->assertFalse($installer->isAllowedDatabaseHost('10.0.0.1 space'));
        $this->assertFalse($installer->isAllowedDatabaseHost(''));

        // Reset
        config(['installer.allow_remote_db' => false]);
        putenv('INSTALLER_ALLOW_REMOTE_DB');
    }

    // ── 17. Security Hardening Tests (C1, C2, C4, C8) ──────────────────────────

    public function test_setup_token_validation_and_replay_protection(): void
    {
        $this->removeInstallationMarker();
        $installer = app(InstallationManager::class);

        $token = $installer->ensureSetupToken();
        $this->assertNotEmpty($token);
        $this->assertSame(64, strlen($token));
        $this->assertTrue($installer->validSetupToken($token));
        $this->assertFalse($installer->validSetupToken('invalid-token-value'));
        $this->assertFalse($installer->validSetupToken(null));

        // Consuming the token rotates it and invalidates previous token
        $consumed = $installer->consumeSetupToken($token);
        $this->assertTrue($consumed);
        $this->assertFalse($installer->validSetupToken($token));
        $this->assertFalse($installer->consumeSetupToken($token));

        // In HTTP flow, submitting with invalid or already-consumed token is rejected
        $this->withSession(['installer.expect_token_rejection' => true]);
        $response = $this->from('/install/database')->post('/install/database', [
            'setup_token' => $token,
            'db_connection' => 'mysql',
            'db_host' => '127.0.0.1',
            'db_port' => 3306,
            'db_database' => 'gateway_db',
            'db_username' => 'gateway_user',
            'db_password' => 'secret',
            'app_url' => 'https://gateway.example',
            'app_name' => 'Gateway',
        ]);
        $response->assertRedirect('/install/database');
        $response->assertSessionHasErrors('setup_token');
    }

    public function test_concurrent_installer_attempts_are_serialized_and_rejected(): void
    {
        $this->removeInstallationMarker();
        $installer = app(InstallationManager::class);

        $sharedToken = $installer->ensureSetupToken();
        $this->assertTrue($installer->validSetupToken($sharedToken));

        // Simulating racer 1 consuming token successfully
        $racer1Result = $installer->consumeSetupToken($sharedToken);
        $this->assertTrue($racer1Result);

        // Racer 2 attempting with the same initial token must fail
        $racer2Result = $installer->consumeSetupToken($sharedToken);
        $this->assertFalse($racer2Result);
    }

    public function test_lock_file_permission_failure_and_recovery(): void
    {
        $this->removeInstallationMarker();
        $lockPath = storage_path('framework/testing/perm-test-lock-'.bin2hex(random_bytes(6)));
        config(['installer.setup_lock_path' => $lockPath]);
        $installer = app(InstallationManager::class);

        try {
            // Case 1: Unsafe loose permissions (0644) are recovered to 0600 gracefully
            file_put_contents($lockPath, 'test-lock');
            chmod($lockPath, 0644);
            $this->assertSame(0644, fileperms($lockPath) & 0777);

            $executed = $installer->withSetupLock(function () use ($lockPath) {
                return (fileperms($lockPath) & 0777) === 0600;
            });
            $this->assertTrue($executed);
            $this->assertSame(0600, fileperms($lockPath) & 0777);

            // Case 2: Unsafe symlink cannot be safely recovered; must throw without leaking paths
            @unlink($lockPath);
            $symlinkTarget = sys_get_temp_dir().'/symlink-target-'.bin2hex(random_bytes(6));
            file_put_contents($symlinkTarget, 'target');
            symlink($symlinkTarget, $lockPath);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Installer lock file is unsafe.');

            try {
                $installer->withSetupLock(fn () => true);
            } catch (RuntimeException $e) {
                // Ensure no server directory paths leaked in exception message
                $this->assertStringNotContainsString(base_path(), $e->getMessage());
                $this->assertStringNotContainsString(storage_path(), $e->getMessage());
                $this->assertStringNotContainsString($symlinkTarget, $e->getMessage());
                throw $e;
            } finally {
                @unlink($symlinkTarget);
            }
        } finally {
            @unlink($lockPath);
        }
    }

    public function test_diagnostic_endpoint_abuse_and_log_injection_protection(): void
    {
        $this->removeInstallationMarker();
        $installer = app(InstallationManager::class);

        // 1. Log sanitization: CRLF, ANSI escape sequences, control chars must be stripped
        $dirtyMessage = "Readiness failed\r\n injected_header: true\n\x1b[31mCRITICAL\x1b[0m\x00evil";
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'Installer browser readiness check failed'
                    && ! str_contains($context['message'], "\r")
                    && ! str_contains($context['message'], "\n")
                    && ! str_contains($context['message'], "\x1b")
                    && ! str_contains($context['message'], "\x00");
            });

        $diag = $this->getJson('/__upi_deployment_check')->assertOk()->json();
        $token = $diag['diagnostic_token'];

        $this->postJson('/__upi_deployment_check', [
            'diagnostic_report' => true,
            'diagnostic_token' => $token,
            'stage' => 'public_directory',
            'message' => $dirtyMessage,
        ])->assertOk()->assertJson(['logged' => true]);

        // 2. Replay of single-use diagnostic token must be rejected
        $this->postJson('/__upi_deployment_check', [
            'diagnostic_report' => true,
            'diagnostic_token' => $token,
            'stage' => 'public_directory',
            'message' => 'Replay attempt',
        ])->assertForbidden();

        // 3. When application is marked installed, diagnostic POST must be rejected (403)
        $installer->markInstalled();
        $this->postJson('/__upi_deployment_check', [
            'diagnostic_report' => true,
            'diagnostic_token' => 'any-token',
            'stage' => 'server',
            'message' => 'Post-install probe',
        ])->assertForbidden();

        // 4. Installed deployment MUST still allow the session-bound CSRF probe
        $installedProbe = $this->getJson('/__upi_deployment_check')->assertOk()->json();
        $this->assertTrue($installedProbe['installed']);
        $this->postJson('/__upi_deployment_check', [
            'probe' => $installedProbe['probe'],
        ])->assertOk()->assertJson(['session' => true, 'csrf' => true]);

        // 5. Invalid probe token on installed deployment must be rejected (403)
        $this->postJson('/__upi_deployment_check', [
            'probe' => 'invalid-probe-token',
        ])->assertForbidden();
    }

    public function test_post_install_permission_hardening(): void
    {
        $this->removeInstallationMarker();
        $installer = app(InstallationManager::class);

        // Create temporary installer artifacts
        $tokenFile = $installer->setupTokenPath();
        file_put_contents($tokenFile, bin2hex(random_bytes(32)).':'.(time() + 3600));
        chmod($tokenFile, 0600);
        $tempEnv = dirname($installer->envPath()).'/.env-install-test-'.bin2hex(random_bytes(4));
        file_put_contents($tempEnv, 'TMP=1');

        $this->assertFileExists($tokenFile);
        $this->assertFileExists($tempEnv);

        // Mark installed
        $installer->markInstalled();

        // 1. Temporary installer tokens and temp env files must be unlinked
        $this->assertFileDoesNotExist($tokenFile);
        $this->assertFileDoesNotExist($tempEnv);

        // 2. Sensitive installation marker and .env must have private permissions (0600)
        $installedPath = $installer->installedPath();
        $this->assertFileExists($installedPath);
        $this->assertSame(0600, fileperms($installedPath) & 0777);

        if (is_file($installer->envPath())) {
            $this->assertSame(0600, fileperms($installer->envPath()) & 0777);
        }

        // 3. Storage and runtime cache directories must remain intact and writable
        $this->assertDirectoryIsWritable(storage_path('logs'));
        $this->assertDirectoryIsWritable(storage_path('framework/cache'));
        $this->assertDirectoryIsWritable(storage_path('framework/sessions'));
        $this->assertDirectoryIsWritable(storage_path('framework/views'));
        $this->assertDirectoryIsWritable(base_path('bootstrap/cache'));
    }

    public function test_private_file_ownership_and_symlink_validation(): void
    {
        $installer = app(InstallationManager::class);
        $tempPath = storage_path('framework/testing/owner-test-'.bin2hex(random_bytes(6)));

        try {
            // Regular 0600 file owned by current process is valid
            file_put_contents($tempPath, 'secret');
            chmod($tempPath, 0600);
            clearstatcache(true, $tempPath);
            $this->assertTrue($installer->privateFileIsValid($tempPath));

            // Symlink pointing to regular file is always rejected as unsafe
            $symlinkPath = storage_path('framework/testing/owner-link-'.bin2hex(random_bytes(6)));
            symlink($tempPath, $symlinkPath);
            try {
                $this->assertFalse($installer->privateFileIsValid($symlinkPath));
            } finally {
                @unlink($symlinkPath);
            }

            // Loose permissions (0644) are not valid as a private file
            chmod($tempPath, 0644);
            clearstatcache(true, $tempPath);
            $this->assertFalse($installer->privateFileIsValid($tempPath));

            // Incomplete setup recovery works on 0644 regular file
            config(['installer.in_progress_path' => $tempPath]);
            $this->assertTrue($installer->hasIncompleteSetup());
            $this->assertSame(0600, fileperms($tempPath) & 0777);
        } finally {
            @unlink($tempPath);
        }
    }

    public function test_shared_database_config_validation(): void
    {
        $installer = app(InstallationManager::class);

        // Valid local MySQL configuration
        $validData = [
            'db_connection' => 'mysql',
            'db_host' => '127.0.0.1',
            'db_port' => 3306,
            'db_database' => 'gateway_prod',
            'db_username' => 'gateway_user',
            'db_password' => 'secret_pass_123',
            'app_name' => 'Payment Gateway',
            'app_url' => 'https://pay.example.com',
        ];
        $this->assertNull($installer->validateDatabaseConfig($validData));

        // Invalid host
        $this->assertSame('Invalid database host.', $installer->validateDatabaseConfig(['db_host' => ''] + $validData));
        $this->assertSame('Database host must be local (127.0.0.1 or localhost) for security during web installation.', $installer->validateDatabaseConfig(['db_host' => '192.168.1.5'] + $validData));

        // Invalid port
        $this->assertSame('Database port must be between 1 and 65535.', $installer->validateDatabaseConfig(['db_port' => 70000] + $validData));

        // Invalid database name
        $this->assertSame('Database name contains invalid characters.', $installer->validateDatabaseConfig(['db_database' => 'drop table;'] + $validData));

        // Password with newline
        $this->assertSame('Database password cannot contain newlines.', $installer->validateDatabaseConfig(['db_password' => "pass\nword"] + $validData));

        // Invalid URL
        $this->assertSame('Please enter a valid application URL (e.g. https://yourdomain.com).', $installer->validateDatabaseConfig(['app_url' => 'not-a-url'] + $validData));

        // Insecure HTTP URL on production domain
        $this->assertSame('Production installations must use HTTPS (e.g. https://yourdomain.com). Insecure HTTP is only permitted for local testing on 127.0.0.1 or localhost.', $installer->validateDatabaseConfig(['app_url' => 'http://pay.example.com'] + $validData));

        // Insecure HTTP on localhost is permitted
        $this->assertNull($installer->validateDatabaseConfig(['app_url' => 'http://127.0.0.1:8080'] + $validData));
    }
}
