<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DeploymentConfiguration;
use App\Support\DeploymentUrl;
use App\Support\InstallationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
