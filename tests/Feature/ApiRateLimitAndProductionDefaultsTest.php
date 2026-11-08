<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\InstallationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ApiRateLimitAndProductionDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('api-failed-auth:198.51.100.1');
        RateLimiter::clear('api-failed-auth:198.51.100.2');

        $this->merchant = User::create([
            'name' => 'Demo Merchant',
            'business_name' => 'Demo Store',
            'email' => 'demo@example.com',
            'password' => 'password123',
        ]);
        $this->merchant->regenerateApiCredentials();
        $this->merchant->paymentAccounts()->create([
            'provider' => 'manual',
            'label' => 'Main',
            'upi_id' => 'demo@okaxis',
            'payee_name' => 'Demo Store',
            'is_default' => true,
        ]);
    }

    public function test_repeated_invalid_credentials_are_rate_limited_by_ip(): void
    {
        config(['gateway.api_failed_auth_ip_rate_limit' => 3]);
        $clientIp = '198.51.100.1';

        for ($i = 1; $i <= 3; $i++) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => $clientIp])
                ->withHeaders([
                    'X-Api-Key' => 'invalid_key_'.$i,
                    'X-Api-Secret' => 'invalid_secret_'.$i,
                ])
                ->postJson('/api/v1/orders', ['amount' => 100]);

            $response->assertStatus(401)
                ->assertJson(['message' => 'Invalid API key or secret.'])
                ->assertHeader('X-Request-Id');
        }

        // 4th attempt must be blocked by IP throttle with 429 Too Many Requests
        $response = $this->withServerVariables(['REMOTE_ADDR' => $clientIp])
            ->withHeaders([
                'X-Api-Key' => 'invalid_key_4',
                'X-Api-Secret' => 'invalid_secret_4',
            ])
            ->postJson('/api/v1/orders', ['amount' => 100]);

        $response->assertStatus(429)
            ->assertJson(['message' => 'Too many failed authentication attempts. Please try again later.'])
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '3')
            ->assertHeader('X-RateLimit-Remaining', '0');
    }

    public function test_failed_auth_does_not_reveal_whether_key_exists(): void
    {
        $clientIp = '198.51.100.1';

        // 1. Existing valid key with incorrect secret
        $respExisting = $this->withServerVariables(['REMOTE_ADDR' => $clientIp])
            ->withHeaders([
                'X-Api-Key' => $this->merchant->api_key,
                'X-Api-Secret' => 'wrong_secret_value_123',
            ])
            ->postJson('/api/v1/orders', ['amount' => 100]);

        // 2. Non-existent key with arbitrary secret
        $respUnknown = $this->withServerVariables(['REMOTE_ADDR' => $clientIp])
            ->withHeaders([
                'X-Api-Key' => 'key_non_existent_random_key_9999',
                'X-Api-Secret' => 'wrong_secret_value_123',
            ])
            ->postJson('/api/v1/orders', ['amount' => 100]);

        // Both responses must be identical in status and payload
        $respExisting->assertStatus(401)->assertJson(['message' => 'Invalid API key or secret.']);
        $respUnknown->assertStatus(401)->assertJson(['message' => 'Invalid API key or secret.']);
        $this->assertSame($respExisting->json('message'), $respUnknown->json('message'));
    }

    public function test_failed_auth_rate_limiting_is_isolated_per_ip(): void
    {
        config(['gateway.api_failed_auth_ip_rate_limit' => 2]);
        $attackerIp = '198.51.100.1';
        $innocentIp = '198.51.100.2';

        // Attacker exhausts their limit
        for ($i = 0; $i < 2; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => $attackerIp])
                ->withHeaders(['X-Api-Key' => 'bad_key', 'X-Api-Secret' => 'bad_secret'])
                ->postJson('/api/v1/orders', ['amount' => 100])
                ->assertStatus(401);
        }

        // Attacker is now throttled
        $this->withServerVariables(['REMOTE_ADDR' => $attackerIp])
            ->withHeaders(['X-Api-Key' => 'bad_key', 'X-Api-Secret' => 'bad_secret'])
            ->postJson('/api/v1/orders', ['amount' => 100])
            ->assertStatus(429);

        // Another IP is NOT affected by attacker's failed attempts
        $this->withServerVariables(['REMOTE_ADDR' => $innocentIp])
            ->withHeaders(['X-Api-Key' => 'bad_key', 'X-Api-Secret' => 'bad_secret'])
            ->postJson('/api/v1/orders', ['amount' => 100])
            ->assertStatus(401);
    }

    public function test_valid_authentication_succeeds_and_enforces_merchant_limits(): void
    {
        config([
            'gateway.api_failed_auth_ip_rate_limit' => 5,
            'gateway.api_key_rate_limit' => 2,
        ]);
        $clientIp = '198.51.100.1';

        // Attempt 1: Success (201 Created)
        $this->withServerVariables(['REMOTE_ADDR' => $clientIp])
            ->withHeaders([
                'X-Api-Key' => $this->merchant->api_key,
                'X-Api-Secret' => $this->merchant->api_secret,
            ])
            ->postJson('/api/v1/orders', ['amount' => 100, 'client_order_id' => 'CLI-1'])
            ->assertStatus(201);

        // Attempt 2: Success (201 Created)
        $this->withServerVariables(['REMOTE_ADDR' => $clientIp])
            ->withHeaders([
                'X-Api-Key' => $this->merchant->api_key,
                'X-Api-Secret' => $this->merchant->api_secret,
            ])
            ->postJson('/api/v1/orders', ['amount' => 100, 'client_order_id' => 'CLI-2'])
            ->assertStatus(201);

        // Attempt 3: Key limit reached (429)
        $this->withServerVariables(['REMOTE_ADDR' => $clientIp])
            ->withHeaders([
                'X-Api-Key' => $this->merchant->api_key,
                'X-Api-Secret' => $this->merchant->api_secret,
            ])
            ->postJson('/api/v1/orders', ['amount' => 100, 'client_order_id' => 'CLI-3'])
            ->assertStatus(429)
            ->assertJson(['message' => 'Rate limit exceeded for this API key.']);
    }

    public function test_installer_generates_production_safe_environment_defaults(): void
    {
        $installer = app(InstallationManager::class);
        $tempEnv = tempnam(sys_get_temp_dir(), 'env_test_');
        config(['installer.env_path' => $tempEnv]);

        try {
            $installer->writeEnvironment([
                'db_connection' => 'mysql',
                'db_host' => '127.0.0.1',
                'db_port' => 3306,
                'db_database' => 'production_gateway',
                'db_username' => 'gateway_user',
                'db_password' => 'secret_db_pass',
                'app_name' => 'Acme Gateway',
                'app_url' => 'https://payments.example.com',
            ]);

            $envContent = file_get_contents($tempEnv);
            $this->assertStringContainsString('APP_ENV=production', $envContent);
            $this->assertStringContainsString('APP_DEBUG=false', $envContent);
            $this->assertStringContainsString('ALLOW_REGISTRATION=false', $envContent);
            $this->assertStringContainsString('LOG_LEVEL=error', $envContent);
        } finally {
            if (file_exists($tempEnv)) {
                @unlink($tempEnv);
            }
        }
    }
}
