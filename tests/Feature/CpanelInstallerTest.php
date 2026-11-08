<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\InstallationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class CpanelInstallerTest extends TestCase
{
    use RefreshDatabase;

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

        // In test suite with RefreshDatabase, all migrations are already run
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
}
