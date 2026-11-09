<?php

namespace Tests\Feature;

use App\Http\Middleware\RedirectIfInstalled;
use App\Http\Middleware\RedirectToInstall;
use App\Models\User;
use App\Services\VerificationNodeIdentity;
use App\Support\InstallationManager;
use Dotenv\Loader\Loader;
use Dotenv\Parser\Parser;
use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class InstallationWizardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        config([
            'installer.setup_lock_path' => storage_path('framework/testing/installer-lock'),
            'installer.in_progress_path' => storage_path('framework/testing/installer-progress'),
        ]);
        file_put_contents(config('installer.in_progress_path'), str_repeat('c', 64));
        chmod(config('installer.in_progress_path'), 0600);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        @unlink(config('installer.setup_lock_path'));
        @unlink(config('installer.in_progress_path'));
        parent::tearDown();
    }

    private function authorizeSetup(): void
    {
        $this->withSession(['installer.setup_authorized' => true]);
    }

    private function beginInstallation(): void
    {
        @unlink(config('installer.installed_path'));
    }

    public function test_first_access_redirects_to_installer_when_not_configured(): void
    {
        $this->beginInstallation();

        $this->get('/')->assertRedirect(route('install.index'));
        $this->get(route('install.index'))
            ->assertOk()
            ->assertSee('Readiness')
            ->assertSee('Checking...')
            ->assertDontSee('PDO MySQL (required)')
            ->assertDontSee('PDO SQLite')
            ->assertDontSee('Setup Password')
            ->assertSee('Next');

        // Database configuration page is accessible without password
        $this->get(route('install.database'))
            ->assertOk()
            ->assertSee('MySQL is required')
            ->assertDontSee('SQLite')
            ->assertDontSee('Setup Password');

        // Sensitive steps require database authorization
        $this->get(route('install.migrate'))->assertStatus(403);
        $this->get(route('install.admin'))->assertStatus(403);
    }

    public function test_uninstalled_api_returns_a_generic_service_unavailable_response(): void
    {
        $this->beginInstallation();

        $this->getJson('/api/v1/accounts')
            ->assertStatus(503)
            ->assertExactJson(['message' => 'Application installation is required.']);
    }

    public function test_successful_installation_creates_admin_and_disables_installer(): void
    {
        $this->beginInstallation();
        $this->authorizeSetup();

        $this->get(route('install.admin'))->assertOk();
        $this->post(route('install.admin.save'), [
            'name' => 'Initial Admin',
            'email' => 'owner@example.com',
            'password' => 'securepass10',
            'password_confirmation' => 'securepass10',
        ])->assertRedirect(route('install.done'));

        $admin = User::where('email', 'owner@example.com')->firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertTrue(Hash::check('securepass10', $admin->password));
        $this->assertFileExists(config('installer.installed_path'));
        $this->assertFalse(session()->has('installer.setup_authorized'));
        $this->assertFileDoesNotExist(config('installer.in_progress_path'));

        $this->get(route('install.done'))
            ->assertOk()
            ->assertSee('Setup complete')
            ->assertSee('Go to Login');

        $this->get(route('install.index'))->assertRedirect(route('home'));
        $this->get(route('install.done'))->assertRedirect(route('home'));
    }

    public function test_invalid_database_credentials_are_rejected_without_exposure(): void
    {
        $this->beginInstallation();
        $secret = 'do-not-expose-this-password';
        $installer = Mockery::mock(InstallationManager::class)->makePartial();
        $installer->shouldReceive('testDatabaseConnection')
            ->once()
            ->andThrow(new RuntimeException("Connection failed using {$secret}"));
        $this->app->instance(InstallationManager::class, $installer);
        Log::spy();

        $response = $this
            ->withoutMiddleware(RedirectIfInstalled::class)
            ->from(route('install.database'))
            ->post(route('install.database.save'), [
                'db_connection' => 'mysql',
                'db_host' => '127.0.0.1',
                'db_port' => 3306,
                'db_database' => 'gateway',
                'db_username' => 'gateway',
                'db_password' => $secret,
                'app_url' => 'https://gateway.example',
                'app_name' => 'Gateway',
            ]);

        $response->assertRedirect(route('install.database'))
            ->assertSessionHasErrors('db_host')
            ->assertDontSee($secret)
            ->assertSessionMissing('_old_input.db_password');
        Log::shouldNotHaveReceived('error');
    }

    public function test_database_authorization_rotates_laravel_session_and_authorizes_installer(): void
    {
        $this->beginInstallation();
        $this->withoutMiddleware([RedirectIfInstalled::class, RedirectToInstall::class]);
        $installer = Mockery::mock(InstallationManager::class)->makePartial();
        $installer->shouldReceive('testDatabaseConnection')->once();
        $installer->shouldReceive('writeEnvironment')->once();
        $this->app->instance(InstallationManager::class, $installer);
        $originalSessionId = $this->app['session']->driver()->getId();

        $this->post(route('install.database.save'), [
            'db_connection' => 'mysql',
            'db_host' => '127.0.0.1',
            'db_port' => 3306,
            'db_database' => 'gateway',
            'db_username' => 'gateway',
            'app_url' => 'https://gateway.example',
            'app_name' => 'Gateway',
        ])->assertRedirect(route('install.migrate'));

        $this->assertNotSame($originalSessionId, $this->app['session']->driver()->getId());
        $this->assertTrue(session('installer.setup_authorized'));
    }

    public function test_environment_is_written_atomically_with_a_generated_key(): void
    {
        $envPath = tempnam(sys_get_temp_dir(), 'gateway-env-');
        $originalEnvironment = config('app.env');
        $originalKey = config('app.key');
        $originalDebug = config('app.debug');
        $originalUrl = config('app.url');
        $originalSecureCookie = config('session.secure');
        config([
            'installer.env_path' => $envPath,
        ]);

        try {
            app(InstallationManager::class)->writeEnvironment([
                'db_connection' => 'mysql',
                'db_host' => '127.0.0.1',
                'db_port' => 3306,
                'db_database' => 'gateway',
                'db_username' => 'gateway',
                'db_password' => '',
                'app_url' => 'https://gateway.example',
                'app_name' => 'Secure Gateway',
            ]);

            $env = file_get_contents($envPath);
            $this->assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/=]+$/m', $env);
            $this->assertStringContainsString('APP_ENV=production', $env);
            $this->assertStringContainsString('APP_DEBUG=false', $env);
            $this->assertStringContainsString('SESSION_SECURE_COOKIE=true', $env);
            $this->assertSame(0600, fileperms($envPath) & 0777);
        } finally {
            config([
                'app.env' => $originalEnvironment,
                'app.key' => $originalKey,
                'app.debug' => $originalDebug,
                'app.url' => $originalUrl,
                'session.secure' => $originalSecureCookie,
            ]);
            @unlink($envPath);
        }
    }

    public function test_sqlite_is_rejected_by_installer(): void
    {
        $this->beginInstallation();
        $this->withoutMiddleware(RedirectIfInstalled::class)
            ->from(route('install.database'))
            ->post(route('install.database.save'), [
                'db_connection' => 'sqlite',
                'app_url' => 'https://gateway.example',
                'app_name' => 'Gateway',
            ])
            ->assertRedirect(route('install.database'))
            ->assertSessionHasErrors('db_connection');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only MySQL is supported');
        app(InstallationManager::class)->configureDatabase(['db_connection' => 'sqlite']);
    }

    public function test_migration_failure_is_safe_and_can_be_retried(): void
    {
        $this->authorizeSetup();
        Log::spy();
        Artisan::shouldReceive('call')->once()->andThrow(new RuntimeException(
            'SQLSTATE failed for db.internal using very-secret-password'
        ));

        $this->withoutMiddleware(RedirectIfInstalled::class)
            ->postJson(route('install.migrate.run'))
            ->assertStatus(500)
            ->assertJson([
                'success' => false,
                'message' => 'Database migration failed. Check the application logs for details.',
            ])
            ->assertDontSee('db.internal')
            ->assertDontSee('very-secret-password');

        $this->assertFalse(config('installer.completed', false));
        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) {
            $logged = $message.json_encode($context);

            return ! str_contains($logged, 'db.internal')
                && ! str_contains($logged, 'very-secret-password');
        });
    }

    public function test_existing_marker_blocks_duplicate_installation(): void
    {
        $this->get(route('install.index'))->assertRedirect(route('home'));
        $this->post(route('install.admin.save'), [
            'name' => 'Attacker',
            'email' => 'attacker@example.com',
            'password' => 'attackerpass10',
            'password_confirmation' => 'attackerpass10',
        ])->assertRedirect(route('home'));

        $this->assertDatabaseMissing('users', ['email' => 'attacker@example.com']);
    }

    public function test_existing_admin_blocks_reinstall_when_marker_is_deleted(): void
    {
        $this->beginInstallation();
        User::create([
            'name' => 'Existing Admin',
            'email' => 'existing@example.com',
            'password' => 'securepass10',
            'role' => 'admin',
        ]);

        $this->get(route('install.index'))->assertRedirect(route('home'));
        $this->get('/')->assertOk();
    }

    public function test_incomplete_installation_can_resume_after_migrations(): void
    {
        $this->beginInstallation();
        $this->authorizeSetup();

        $this->get(route('install.admin'))
            ->assertOk()
            ->assertSee('Create your admin account');

        $this->get('/dashboard')->assertRedirect(route('install.index'));
    }

    public function test_incomplete_migrations_prevent_admin_creation(): void
    {
        $this->beginInstallation();
        $this->authorizeSetup();

        // Delete the last migration record to simulate an interrupted or partial migration
        $lastMigration = DB::table('migrations')->orderByDesc('id')->first();
        $this->assertNotNull($lastMigration);
        DB::table('migrations')->where('id', $lastMigration->id)->delete();

        $this->assertFalse(app(InstallationManager::class)->databaseIsMigrated());

        $this->get(route('install.admin'))
            ->assertRedirect(route('install.migrate'))
            ->assertSessionHasErrors('database');

        $this->post(route('install.admin.save'), [
            'name' => 'Sneaky Admin',
            'email' => 'sneaky@example.com',
            'password' => 'securepass10',
            'password_confirmation' => 'securepass10',
        ])
            ->assertRedirect(route('install.migrate'))
            ->assertSessionHasErrors('database');

        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.com']);
    }

    public function test_missing_required_table_prevents_admin_creation(): void
    {
        $this->beginInstallation();
        $this->authorizeSetup();

        Schema::dropIfExists('installation_guards');
        $this->assertFalse(app(InstallationManager::class)->databaseIsMigrated());

        $this->get(route('install.admin'))
            ->assertRedirect(route('install.migrate'))
            ->assertSessionHasErrors('database');
    }

    public function test_sequential_admin_creation_cannot_duplicate_or_replace_existing_admin(): void
    {
        $this->beginInstallation();
        $this->authorizeSetup();

        // Step 1: Create initial admin
        $this->post(route('install.admin.save'), [
            'name' => 'First Admin',
            'email' => 'admin1@example.com',
            'password' => 'securepass10',
            'password_confirmation' => 'securepass10',
        ])->assertRedirect(route('install.done'));

        $this->assertDatabaseHas('users', ['email' => 'admin1@example.com', 'role' => 'admin']);
        $this->assertDatabaseHas('installation_guards', ['name' => 'primary_admin']);

        // Step 2: Attempting to create a second admin sequential request when installed file is removed
        $this->beginInstallation();

        $this->withoutMiddleware(RedirectIfInstalled::class)
            ->post(route('install.admin.save'), [
                'name' => 'Second Admin',
                'email' => 'admin2@example.com',
                'password' => 'securepass10',
                'password_confirmation' => 'securepass10',
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'admin2@example.com']);
        $this->assertSame(1, User::where('role', 'admin')->count());
    }

    public function test_concurrent_admin_creation_is_serialized_and_rejected_by_database_guard(): void
    {
        $this->beginInstallation();
        $this->authorizeSetup();

        // Simulate another concurrent process acquiring the database guard first
        DB::table('installation_guards')->insert([
            'name' => 'primary_admin',
            'created_at' => now(),
        ]);

        $this->withoutMiddleware(RedirectIfInstalled::class)
            ->post(route('install.admin.save'), [
                'name' => 'Racing Admin',
                'email' => 'racing@example.com',
                'password' => 'securepass10',
                'password_confirmation' => 'securepass10',
            ])
            ->assertStatus(409);

        $this->assertDatabaseMissing('users', ['email' => 'racing@example.com']);
        $this->assertSame(0, User::where('role', 'admin')->count());
    }

    public function test_env_value_safely_escapes_dollars_quotes_and_special_characters(): void
    {
        $installer = app(InstallationManager::class);

        // Ordinary passwords
        $this->assertSame('"simpleSecret123"', $installer->envValue('simpleSecret123'));

        // Passwords containing $
        $this->assertSame('"P@\$w0rd"', $installer->envValue('P@$w0rd'));
        $this->assertSame('"P@\$\$w0rd"', $installer->envValue('P@$$w0rd'));
        $this->assertSame('"\$"', $installer->envValue('$'));
        $this->assertSame('"\$\$\$"', $installer->envValue('$$$'));

        // Passwords containing ${VAR}
        $this->assertSame('"P@\${APP_NAME}w0rd"', $installer->envValue('P@${APP_NAME}w0rd'));
        $this->assertSame('"\${DB_DATABASE}"', $installer->envValue('${DB_DATABASE}'));

        // Backslashes and double quotes
        $this->assertSame('"secret\"with\"quotes"', $installer->envValue('secret"with"quotes'));
        $this->assertSame('"pass\\\\word"', $installer->envValue('pass\\word'));
        $this->assertSame('"\\\\\"quoted\\\\\""', $installer->envValue('\\"quoted\\"'));

        // Empty and special-character values
        $this->assertSame('""', $installer->envValue(''));
        $this->assertSame('"!@#%^&*()_+-=[]{}|;:,.<>?/~`"', $installer->envValue('!@#%^&*()_+-=[]{}|;:,.<>?/~`'));
        $this->assertSame('"   "', $installer->envValue('   '));
    }

    public function test_environment_writer_roundtrips_complex_passwords_without_variable_interpolation(): void
    {
        $installer = app(InstallationManager::class);
        $tempEnv = tempnam(sys_get_temp_dir(), 'gateway_test_env_');
        $originalEnvPath = config('installer.env_path');
        $originalEnvironment = config('app.env');
        $originalKey = config('app.key');
        $originalDebug = config('app.debug');
        $originalUrl = config('app.url');
        $originalSecureCookie = config('session.secure');
        config(['installer.env_path' => $tempEnv]);

        $testPasswords = [
            'ordinary' => 'MySecretPass123',
            'single_dollar' => 'P@$w0rd',
            'double_dollar' => 'P@$$w0rd',
            'dollar_brace' => 'P@${APP_NAME}w0rd',
            'quotes_and_slashes' => 'pass\\"word\\"with\\slashes',
            'empty' => '',
            'special' => '!@#%^&*()_+-=[]{}|;:,.<>?/~`',
        ];

        try {
            foreach ($testPasswords as $case => $rawPassword) {
                $installer->writeEnvironment([
                    'db_connection' => 'mysql',
                    'db_host' => '127.0.0.1',
                    'db_port' => 3306,
                    'db_database' => 'gateway_db',
                    'db_username' => 'gateway_user',
                    'db_password' => $rawPassword,
                    'app_name' => 'InterpolationTestApp',
                    'app_url' => 'https://gateway.example',
                ]);

                $envContent = file_get_contents($tempEnv);

                // Parse with Dotenv using an isolated in-memory repository to prevent mutating global $_ENV
                $repo = RepositoryBuilder::createWithNoAdapters()
                    ->addAdapter(ArrayAdapter::class)
                    ->make();
                $entries = (new Parser)->parse($envContent);
                (new Loader)->load($repo, $entries);

                $loadedPassword = $repo->get('DB_PASSWORD');
                $this->assertSame(
                    $rawPassword,
                    $loadedPassword,
                    "Failed to round-trip password in case [{$case}]. Expected [{$rawPassword}], got [{$loadedPassword}]."
                );
            }
        } finally {
            config([
                'installer.env_path' => $originalEnvPath,
                'app.env' => $originalEnvironment,
                'app.key' => $originalKey,
                'app.debug' => $originalDebug,
                'app.url' => $originalUrl,
                'session.secure' => $originalSecureCookie,
            ]);
            @unlink($tempEnv);
        }
    }

    public function test_installer_replaces_placeholder_node_id_with_cryptographic_id(): void
    {
        $installer = app(InstallationManager::class);
        $tempEnv = tempnam(sys_get_temp_dir(), 'env_node_test_');
        $originalEnvPath = config('installer.env_path');
        $originalNodeId = config('gateway.verification_node_id');
        $originalAppEnv = app()['env'];

        config(['installer.env_path' => $tempEnv]);

        try {
            // Write environment using standard .env.example template containing change-me-unique-node-id
            $installer->writeEnvironment([
                'db_connection' => 'mysql',
                'db_host' => '127.0.0.1',
                'db_port' => 3306,
                'db_database' => 'test_db',
                'db_username' => 'test_user',
                'db_password' => 'secret',
                'app_name' => 'GatewayNodeTest',
                'app_url' => 'https://gateway.example',
            ]);

            $envContent = file_get_contents($tempEnv);
            $this->assertStringNotContainsString('change-me-unique-node-id', $envContent);

            preg_match('/^GATEWAY_VERIFICATION_NODE_ID="(.*)"$/m', $envContent, $matches);
            $this->assertNotEmpty($matches[1]);
            $generatedNodeId = $matches[1];

            // Must be a 32-character hexadecimal string
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $generatedNodeId);
            $this->assertFalse($installer->isPlaceholderNodeId($generatedNodeId));

            // Verify production validation accepts the generated node ID
            $this->app->detectEnvironment(fn () => 'production');
            config(['gateway.verification_node_id' => $generatedNodeId]);
            $nodeIdentity = app(VerificationNodeIdentity::class);
            $this->assertSame($generatedNodeId, $nodeIdentity->nodeId());
        } finally {
            $this->app->detectEnvironment(fn () => $originalAppEnv);
            config([
                'installer.env_path' => $originalEnvPath,
                'gateway.verification_node_id' => $originalNodeId,
            ]);
            @unlink($tempEnv);
        }
    }

    public function test_installer_preserves_explicit_valid_verification_node_id(): void
    {
        $installer = app(InstallationManager::class);
        $tempEnv = tempnam(sys_get_temp_dir(), 'env_node_explicit_');
        $originalEnvPath = config('installer.env_path');
        $originalNodeId = config('gateway.verification_node_id');

        config(['installer.env_path' => $tempEnv]);

        try {
            $explicitNodeId = 'cpanel-staging-cluster-01';

            $installer->writeEnvironment([
                'db_connection' => 'mysql',
                'db_host' => '127.0.0.1',
                'db_port' => 3306,
                'db_database' => 'test_db',
                'db_username' => 'test_user',
                'db_password' => 'secret',
                'app_name' => 'GatewayNodeTest',
                'app_url' => 'https://gateway.example',
                'verification_node_id' => $explicitNodeId,
            ]);

            $envContent = file_get_contents($tempEnv);
            $this->assertStringContainsString('GATEWAY_VERIFICATION_NODE_ID="'.$explicitNodeId.'"', $envContent);
            $this->assertSame($explicitNodeId, config('gateway.verification_node_id'));
        } finally {
            config([
                'installer.env_path' => $originalEnvPath,
                'gateway.verification_node_id' => $originalNodeId,
            ]);
            @unlink($tempEnv);
        }
    }
}
