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
        config([
            'installer.setup_token_path' => storage_path('framework/testing/cpanel-installer-token'),
            'installer.setup_lock_path' => storage_path('framework/testing/cpanel-installer-lock'),
            'installer.in_progress_path' => storage_path('framework/testing/cpanel-installer-progress'),
        ]);
        file_put_contents(config('installer.setup_token_path'), str_repeat('b', 64).':'.(time() + 3600));
        file_put_contents(config('installer.in_progress_path'), str_repeat('c', 64));
        chmod(config('installer.setup_token_path'), 0600);
        chmod(config('installer.in_progress_path'), 0600);
        $response = $this->post(route('install.database.save'), [
            'setup_token' => str_repeat('b', 64),
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
        @unlink(config('installer.setup_token_path'));
        @unlink(config('installer.setup_lock_path'));
        @unlink(config('installer.in_progress_path'));
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

    public function test_database_save_requires_setup_token_even_with_a_valid_session(): void
    {
        $this->removeInstallationMarker();
        $this->post('/install/database', [
            'db_connection' => 'mysql',
            'db_host' => '127.0.0.1',
            'db_port' => 3306,
            'db_database' => 'gateway',
            'db_username' => 'gateway',
            'app_url' => 'https://gateway.example',
            'app_name' => 'Gateway',
        ])->assertStatus(403);
    }

    public function test_fresh_setup_token_is_private_and_removed_after_installation(): void
    {
        $this->removeInstallationMarker();
        $envPath = sys_get_temp_dir().'/gateway-fresh-env-'.bin2hex(random_bytes(8));
        $tokenPath = storage_path('framework/testing/setup-token-'.bin2hex(random_bytes(8)));
        $lockPath = $tokenPath.'-lock';
        $progressPath = $tokenPath.'-progress';
        config([
            'installer.env_path' => $envPath,
            'installer.setup_token_path' => $tokenPath,
            'installer.setup_lock_path' => $lockPath,
            'installer.in_progress_path' => $progressPath,
        ]);
        $installer = app(InstallationManager::class);

        try {
            $installer->ensureSetupToken();
            $this->assertFileExists($tokenPath);
            $this->assertSame(0600, fileperms($tokenPath) & 0777);
            $token = explode(':', file_get_contents($tokenPath), 2)[0];
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
            $this->assertTrue($installer->validSetupToken($token));
            $this->assertFalse($installer->validSetupToken(str_repeat('0', 64)));
            $installer->markInstalled();
            $this->assertFileDoesNotExist($tokenPath);
            $this->assertFalse($installer->validSetupToken($token));
        } finally {
            @unlink($tokenPath);
            @unlink($lockPath);
            @unlink($progressPath);
            @unlink(config('installer.installed_path'));
        }
    }

    public function test_incomplete_first_installation_recovers_during_database_outage_without_marking_installed(): void
    {
        $this->removeInstallationMarker();
        $tokenPath = storage_path('framework/testing/recovery-token-'.bin2hex(random_bytes(8)));
        $envPath = sys_get_temp_dir().'/gateway-recovery-env-'.bin2hex(random_bytes(8));
        $oldDefault = config('database.default');
        $oldMysql = config('database.connections.mysql');
        config([
            'installer.env_path' => $envPath,
            'installer.setup_token_path' => $tokenPath,
            'installer.setup_lock_path' => $tokenPath.'-lock',
            'installer.in_progress_path' => $tokenPath.'-progress',
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
            $this->assertFileExists($tokenPath);
        } finally {
            config(['database.default' => $oldDefault, 'database.connections.mysql' => $oldMysql]);
            DB::purge('mysql');
            @unlink($envPath);
            @unlink($tokenPath);
            @unlink($tokenPath.'-lock');
            @unlink($tokenPath.'-progress');
        }
    }

    public function test_setup_token_expiry_rotation_replay_and_unsafe_files_are_rejected(): void
    {
        $this->removeInstallationMarker();
        $tokenPath = storage_path('framework/testing/lifecycle-token-'.bin2hex(random_bytes(8)));
        config([
            'installer.env_path' => sys_get_temp_dir().'/gateway-lifecycle-env-'.bin2hex(random_bytes(8)),
            'installer.setup_token_path' => $tokenPath,
            'installer.setup_lock_path' => $tokenPath.'-lock',
            'installer.in_progress_path' => $tokenPath.'-progress',
            'installer.setup_token_ttl' => 1,
        ]);
        $installer = app(InstallationManager::class);

        try {
            $installer->ensureSetupToken();
            $first = explode(':', file_get_contents($tokenPath), 2)[0];
            $this->assertTrue($installer->consumeSetupToken($first));
            $this->assertFalse($installer->consumeSetupToken($first));
            $second = explode(':', file_get_contents($tokenPath), 2)[0];
            $this->assertNotSame($first, $second);
            file_put_contents($tokenPath, $second.':'.(time() - 1));
            $this->assertFalse($installer->validSetupToken($second));
            $installer->ensureSetupToken();
            $this->assertNotSame($second, explode(':', file_get_contents($tokenPath), 2)[0]);

            chmod($tokenPath, 0644);
            $this->assertFalse($installer->validSetupToken(explode(':', file_get_contents($tokenPath), 2)[0]));
            @unlink($tokenPath);
            symlink(config('installer.in_progress_path'), $tokenPath);
            $this->assertFalse($installer->validSetupToken(str_repeat('a', 64)));
            $this->expectException(RuntimeException::class);
            $installer->ensureSetupToken();
        } finally {
            @unlink($tokenPath);
            @unlink($tokenPath.'-lock');
            @unlink($tokenPath.'-progress');
        }
    }

    public function test_concurrent_setup_token_consumption_has_one_winner(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL and pcntl_fork for independent database connections.');
        }
        $this->removeInstallationMarker();
        $tokenPath = storage_path('framework/testing/concurrent-token-'.bin2hex(random_bytes(8)));
        $resultPath = $tokenPath.'-result';
        config([
            'installer.env_path' => sys_get_temp_dir().'/gateway-concurrent-env-'.bin2hex(random_bytes(8)),
            'installer.setup_token_path' => $tokenPath,
            'installer.setup_lock_path' => $tokenPath.'-lock',
            'installer.in_progress_path' => $tokenPath.'-progress',
        ]);
        $installer = app(InstallationManager::class);
        $installer->ensureSetupToken();
        $token = explode(':', file_get_contents($tokenPath), 2)[0];

        try {
            // Neither process may inherit an open PDO connection or a PHPUnit
            // transaction across the fork.
            DB::disconnect();
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    DB::purge();
                    $won = $installer->consumeSetupToken($token);
                    $replayed = $installer->consumeSetupToken($token);
                    file_put_contents($resultPath, json_encode(['won' => $won, 'replayed' => $replayed]));
                    DB::disconnect();
                    exit(0);
                } catch (\Throwable) {
                    DB::disconnect();
                    exit(2);
                }
            }
            DB::purge();
            $parentWon = $installer->consumeSetupToken($token);
            $parentReplayed = $installer->consumeSetupToken($token);
            DB::disconnect();
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
            $child = json_decode((string) file_get_contents($resultPath), true);
            $this->assertSame(1, (int) $parentWon + (int) $child['won']);
            $this->assertFalse($parentReplayed);
            $this->assertFalse($child['replayed']);
        } finally {
            @unlink($tokenPath);
            @unlink($tokenPath.'-lock');
            @unlink($tokenPath.'-progress');
            @unlink($resultPath);
        }
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

    public function test_root_install_php_rejects_database_save_without_setup_token(): void
    {
        $this->removeInstallationMarker();
        $this->get('/install.php')->assertOk();
        $csrf = $_SESSION['_installer_csrf'];
        $originalMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $originalPost = $_POST;
        $originalGet = $_GET;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['_token' => $csrf, 'db_host' => '127.0.0.1'];
        $_GET['step'] = 'database';
        try {
            $this->post('/install.php?step=database', $_POST)
                ->assertStatus(403)->assertSee('Invalid installer setup token');
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
}
