<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DeploymentConfiguration;
use App\Support\DeploymentFilesystem;
use App\Support\DeploymentUrl;
use App\Support\InstallationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeploymentCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public static function deployments(): array
    {
        return [
            'main domain' => ['https://example.test', ''],
            'subdomain' => ['https://pay.example.test', ''],
            'subdirectory' => ['https://example.test/payment', '/payment'],
            'nested directory' => ['https://example.test/services/pay', '/services/pay'],
        ];
    }

    #[DataProvider('deployments')]
    public function test_routes_assets_authentication_and_api_preserve_mount(string $url, string $prefix): void
    {
        config(['app.url' => $url, 'session.path' => DeploymentUrl::cookiePath($url)]);
        $this->withServerVariables(['SCRIPT_NAME' => $prefix.'/public/index.php']);
        $this->withVite();
        $this->get($url.'/login')->assertOk()->assertSee($url.'/login', false)
            ->assertSee($url.'/build/assets/', false)->assertDontSee('/public/build/', false);
        $this->get($url.'/docs')->assertOk();
        $merchant = User::create([
            'name' => 'Mount Merchant', 'email' => 'mount@example.test', 'password' => 'securepass123',
        ]);
        $merchant->regenerateApiCredentials();
        $merchant->paymentAccounts()->create([
            'provider' => 'manual', 'label' => 'UPI', 'upi_id' => 'merchant@upi', 'payee_name' => 'Merchant', 'is_default' => true,
        ]);
        $this->post($url.'/login', ['email' => $merchant->email, 'password' => 'securepass123'])
            ->assertRedirect($url.'/dashboard');
        $this->get($url.'/dashboard')->assertOk()->assertSee($url.'/orders', false);
        // Guest middleware must retain the prefix when an authenticated merchant opens /login.
        $this->get($url.'/login')->assertRedirect($url.'/dashboard');
        $this->withHeaders(['X-Api-Key' => $merchant->api_key, 'X-Api-Secret' => $merchant->api_secret])
            ->postJson($url.'/api/v1/orders', ['amount' => 10, 'client_order_id' => 'DEPLOYMENT-1'])
            ->assertCreated()->assertJsonPath('data.payment_url', fn ($value) => str_starts_with($value, $url.'/pay/'));
    }

    #[DataProvider('deployments')]
    public function test_installer_detects_and_writes_canonical_url_assets_and_cookie_path(string $url, string $prefix): void
    {
        $installer = app(InstallationManager::class);
        $host = parse_url($url, PHP_URL_HOST);
        $this->assertSame($url, $installer->detectApplicationUrl(null, [
            'HTTP_HOST' => $host, 'HTTPS' => 'on', 'SCRIPT_NAME' => $prefix.'/public/install.php',
            'REQUEST_URI' => $prefix.'/public/install.php?step=done',
        ]));
        $env = tempnam(sys_get_temp_dir(), 'gateway-deployment-env-');
        config(['installer.env_path' => $env]);
        try {
            $installer->writeEnvironment([
                'app_url' => $url.'/public/', 'app_name' => 'Test', 'db_connection' => 'mysql',
                'db_database' => 'disposable', 'db_host' => 'localhost', 'db_port' => 3306, 'db_username' => 'test',
            ]);
            $contents = file_get_contents($env);
            $this->assertStringContainsString('APP_URL="'.$url.'"', $contents);
            $this->assertStringContainsString('ASSET_URL="'.$url.'"', $contents);
            $this->assertStringContainsString('SESSION_PATH="'.$prefix.'/"', $contents);
            $this->assertStringContainsString('SESSION_COOKIE="'.DeploymentUrl::cookieName($url).'"', $contents);
        } finally {
            @unlink($env);
        }
    }

    public function test_public_storage_works_without_symlink_and_cannot_escape_public_disk(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('receipts/example.txt', 'public receipt');
        Storage::disk('public')->put('.private', 'must not be returned');
        config(['app.url' => 'http://localhost/payment']);
        $this->get('/payment/storage/receipts/example.txt')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/payment/storage/.private')->assertNotFound();
        $this->get('/payment/storage/../.env')->assertNotFound();
        symlink(base_path('.env.example'), Storage::disk('public')->path('outside.txt'));
        $this->get('/payment/storage/outside.txt')->assertNotFound();
    }

    public function test_nginx_configuration_uses_only_public_files_and_explicit_php_targets(): void
    {
        $configuration = DeploymentConfiguration::nginx('https://example.test/payment', '/private/gateway/public', 'unix:/tmp/php-fpm.sock');
        $this->assertStringContainsString('alias "/private/gateway/public/"', $configuration);
        $this->assertStringContainsString('SCRIPT_FILENAME "/private/gateway/public/index.php"', $configuration);
        $this->assertStringContainsString('SCRIPT_NAME "/payment/index.php"', $configuration);
        $this->assertStringNotContainsString('try_files $uri $uri/', $configuration);
        $this->assertStringNotContainsString('root /private/gateway', $configuration);
    }

    public function test_storage_self_healing_creates_missing_directories_without_insecure_permissions(): void
    {
        $tempRoot = sys_get_temp_dir().'/storage_heal_'.bin2hex(random_bytes(6));
        mkdir($tempRoot, 0755, true);

        // Pre-create an existing file to verify data preservation
        mkdir($tempRoot.'/storage/app/public', 0755, true);
        file_put_contents($tempRoot.'/storage/app/public/user_file.txt', 'do not overwrite');

        try {
            $requiredDirectories = [
                $tempRoot.'/storage/app/public',
                $tempRoot.'/storage/app/private',
                $tempRoot.'/storage/framework/cache/data',
                $tempRoot.'/storage/framework/sessions',
                $tempRoot.'/storage/framework/views',
                $tempRoot.'/storage/logs',
                $tempRoot.'/bootstrap/cache',
            ];

            $this->assertSame([], DeploymentFilesystem::prepare($tempRoot));

            foreach ($requiredDirectories as $dir) {
                $this->assertTrue(is_dir($dir), "Directory {$dir} was not created.");
                $perms = fileperms($dir) & 0777;
                // Assert permissions are not insecure 0777
                $this->assertNotSame(0777, $perms, "Directory {$dir} has insecure 777 permissions.");
            }

            // Verify pre-existing data was preserved
            $this->assertSame('do not overwrite', file_get_contents($tempRoot.'/storage/app/public/user_file.txt'));
        } finally {
            @unlink($tempRoot.'/storage/app/public/user_file.txt');
            @rmdir($tempRoot.'/storage/app/public');
            @rmdir($tempRoot.'/storage/app/private');
            @rmdir($tempRoot.'/storage/app');
            @rmdir($tempRoot.'/storage/framework/cache/data');
            @rmdir($tempRoot.'/storage/framework/cache');
            @rmdir($tempRoot.'/storage/framework/sessions');
            @rmdir($tempRoot.'/storage/framework/views');
            @rmdir($tempRoot.'/storage/framework');
            @rmdir($tempRoot.'/storage/logs');
            @rmdir($tempRoot.'/storage');
            @rmdir($tempRoot.'/bootstrap/cache');
            @rmdir($tempRoot.'/bootstrap');
            @rmdir($tempRoot);
        }
    }

    public function test_runtime_skeleton_refuses_to_create_directories_through_an_external_symlink(): void
    {
        $root = storage_path('framework/testing/symlink-root-'.bin2hex(random_bytes(8)));
        $outside = $root.'-outside';
        mkdir($root, 0755, true);
        mkdir($outside, 0755, true);
        symlink($outside, $root.'/storage');
        try {
            $this->assertNotSame([], DeploymentFilesystem::prepare($root));
            $this->assertFileDoesNotExist($outside.'/app');
        } finally {
            unlink($root.'/storage');
            File::deleteDirectory($root);
            File::deleteDirectory($outside);
        }
    }

    public function test_git_attributes_preserves_storage_dotfiles_in_export(): void
    {
        $attributes = file_get_contents(base_path('.gitattributes'));

        // Root .gitignore must be anchored so nested storage dotfiles are preserved
        $this->assertStringContainsString('/.gitignore export-ignore', $attributes);
        $this->assertStringNotContainsString("\n.gitignore export-ignore", $attributes);

        // Required production assets and autoload must be present
        $this->assertFileExists(base_path('vendor/autoload.php'));
        $this->assertFileExists(base_path('public/build/manifest.json'));
        $this->assertFileExists(base_path('public/deployment-check.json'));
        $this->assertFileExists(base_path('bootstrap/cache/packages.php'));
    }

    public function test_panel_script_variations_and_extracted_archive_wrapper_preserve_the_real_mount(): void
    {
        $cases = [
            [['SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/services/pay/install.php?step=database'], '/services/pay'],
            [['SCRIPT_NAME' => '/services/pay/public/index.php/orders', 'REQUEST_URI' => '/services/pay/orders'], '/services/pay'],
            [['SCRIPT_NAME' => '/cgi-bin/php', 'ORIG_SCRIPT_NAME' => '/area/public/index.php', 'REQUEST_URI' => '/area/login'], '/area'],
            [['SCRIPT_NAME' => '/public/install.php', 'REQUEST_URI' => '/payment/install'], '/payment'],
            [['SCRIPT_NAME' => '/payment/index.php', 'REQUEST_URI' => '/payment/docs'], '/payment'],
            [['SCRIPT_NAME' => '/archive-main/public/install.php', 'REQUEST_URI' => '/archive-main/public/install.php'], '/archive-main'],
        ];
        foreach ($cases as [$server, $expected]) {
            $this->assertSame($expected, DeploymentUrl::basePath($server));
        }
        $this->assertSame('http://[::1]:8080/payment', app(InstallationManager::class)->detectApplicationUrl(null, [
            'HTTP_HOST' => '[::1]:8080', 'SCRIPT_NAME' => '/payment/install.php', 'HTTPS' => 'off',
        ]));
    }

    public function test_installation_cookie_names_are_distinct_for_parent_and_nested_apps(): void
    {
        $root = 'https://example.test';
        $urls = [$root, $root.'/one', $root.'/one/nested', $root.'/two'];
        $names = array_map(fn ($url) => DeploymentUrl::cookieName($url), $urls);
        $this->assertCount(4, array_unique($names));
        $this->assertSame('/one/nested/', DeploymentUrl::cookiePath($urls[2]));
    }
}
