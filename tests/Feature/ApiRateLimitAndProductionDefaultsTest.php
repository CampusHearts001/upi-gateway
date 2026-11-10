<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\InstallationManager;
use App\Support\SafeUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
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

    public function test_failed_auth_from_shared_ip_does_not_block_valid_merchant(): void
    {
        config(['gateway.api_failed_auth_ip_rate_limit' => 2]);
        $clientIp = '198.51.100.1';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => $clientIp])
                ->withHeaders(['X-Api-Key' => 'invalid-'.$attempt, 'X-Api-Secret' => 'invalid'])
                ->postJson('/api/v1/orders', ['amount' => 100])
                ->assertUnauthorized();
        }

        $this->withServerVariables(['REMOTE_ADDR' => $clientIp])
            ->withHeaders(['X-Api-Key' => $this->merchant->api_key, 'X-Api-Secret' => $this->merchant->api_secret])
            ->postJson('/api/v1/orders', ['amount' => 100, 'client_order_id' => 'SHARED-IP'])
            ->assertCreated();

        $this->withServerVariables(['REMOTE_ADDR' => $clientIp])
            ->withHeaders(['X-Api-Key' => 'another-invalid', 'X-Api-Secret' => 'invalid'])
            ->postJson('/api/v1/orders', ['amount' => 100])
            ->assertTooManyRequests();
    }

    public function test_valid_authentication_succeeds_and_enforces_merchant_limits(): void
    {
        config([
            'gateway.api_failed_auth_ip_rate_limit' => 5,
            'gateway.order_create_api_key_rate_limit' => 2,
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

    public function test_order_create_uses_its_configured_per_key_limit_and_returns_retry_headers(): void
    {
        config(['gateway.order_create_api_key_rate_limit' => 2]);
        $headers = ['X-Api-Key' => $this->merchant->api_key, 'X-Api-Secret' => $this->merchant->api_secret];

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->withHeaders($headers)
            ->postJson('/api/v1/orders', ['amount' => 100, 'client_order_id' => 'FLASH-1'])->assertCreated();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->withHeaders($headers)
            ->postJson('/api/v1/orders', ['amount' => 100, 'client_order_id' => 'FLASH-2'])->assertCreated();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->withHeaders($headers)
            ->postJson('/api/v1/orders', ['amount' => 100, 'client_order_id' => 'FLASH-3'])
            ->assertTooManyRequests()->assertHeader('Retry-After')->assertHeader('X-RateLimit-Limit', '2');
    }

    public function test_order_create_default_limit_is_one_thousand_per_minute(): void
    {
        $this->assertSame(1000, config('gateway.order_create_api_key_rate_limit'));
    }

    public function test_non_order_api_endpoints_keep_the_existing_per_key_limit(): void
    {
        config([
            'gateway.api_key_rate_limit' => 1,
            'gateway.order_create_api_key_rate_limit' => 2,
        ]);
        $headers = ['X-Api-Key' => $this->merchant->api_key, 'X-Api-Secret' => $this->merchant->api_secret];

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->withHeaders($headers)
            ->getJson('/api/v1/accounts')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->withHeaders($headers)
            ->getJson('/api/v1/accounts')->assertTooManyRequests()->assertHeader('X-RateLimit-Limit', '1');

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->withHeaders($headers)
            ->postJson('/api/v1/orders', ['amount' => 100, 'client_order_id' => 'SEPARATE-BUCKET'])->assertCreated();
    }

    public function test_per_ip_limit_still_applies_to_order_creation(): void
    {
        config(['gateway.order_create_api_key_rate_limit' => 10, 'gateway.api_ip_rate_limit' => 1]);
        $headers = ['X-Api-Key' => $this->merchant->api_key, 'X-Api-Secret' => $this->merchant->api_secret];
        $ip = '198.51.100.1';

        $this->withServerVariables(['REMOTE_ADDR' => $ip])->withHeaders($headers)
            ->postJson('/api/v1/orders', ['amount' => 100, 'client_order_id' => 'IP-ONE'])->assertCreated();
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->withHeaders($headers)
            ->postJson('/api/v1/orders', ['amount' => 100, 'client_order_id' => 'IP-TWO'])
            ->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_each_merchant_has_an_independent_order_create_bucket(): void
    {
        config(['gateway.order_create_api_key_rate_limit' => 1]);
        $other = User::create(['name' => 'Other Merchant', 'email' => 'other@example.com', 'password' => 'password123']);
        $other->regenerateApiCredentials();
        $other->paymentAccounts()->create([
            'provider' => 'manual', 'label' => 'Other', 'upi_id' => 'other@upi', 'payee_name' => 'Other',
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->withHeaders([
            'X-Api-Key' => $this->merchant->api_key, 'X-Api-Secret' => $this->merchant->api_secret,
        ])->postJson('/api/v1/orders', ['amount' => 100, 'client_order_id' => 'M-ONE'])->assertCreated();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->withHeaders([
            'X-Api-Key' => $other->api_key, 'X-Api-Secret' => $other->api_secret,
        ])->postJson('/api/v1/orders', ['amount' => 100, 'client_order_id' => 'OTHER-ONE'])->assertCreated();
    }

    public function test_invalid_order_create_limit_uses_safe_default_instead_of_disabling_throttle(): void
    {
        config(['gateway.order_create_api_key_rate_limit' => 'invalid']);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->withHeaders([
            'X-Api-Key' => $this->merchant->api_key, 'X-Api-Secret' => $this->merchant->api_secret,
        ])->postJson('/api/v1/orders', ['amount' => 100, 'client_order_id' => 'SAFE-DEFAULT'])->assertCreated();
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

    public function test_production_environment_forces_insecure_flags_to_false(): void
    {
        $originalEnv = app()['env'];
        $originalDb = config('database.default');
        $originalWebhookInsecure = config('gateway.webhook_allow_insecure');
        $originalRedirectInsecure = config('gateway.allow_insecure_redirects');

        try {
            // Attempt to enable insecure configurations
            config([
                'database.default' => 'mysql',
                'gateway.webhook_allow_insecure' => true,
                'gateway.allow_insecure_redirects' => true,
            ]);

            // Switch to production
            $this->app->detectEnvironment(fn () => 'production');

            // Re-boot AppServiceProvider
            (new AppServiceProvider($this->app))->boot();

            $this->assertFalse(config('gateway.webhook_allow_insecure'));
            $this->assertFalse(config('gateway.allow_insecure_redirects'));
            $this->assertFalse(config('app.debug'));
        } finally {
            $this->app->detectEnvironment(fn () => $originalEnv);
            config([
                'database.default' => $originalDb,
                'gateway.webhook_allow_insecure' => $originalWebhookInsecure,
                'gateway.allow_insecure_redirects' => $originalRedirectInsecure,
            ]);
        }
    }

    public function test_production_blocks_http_webhooks_even_if_attempted(): void
    {
        $originalEnv = app()['env'];
        $originalDb = config('database.default');
        $originalWebhookInsecure = config('gateway.webhook_allow_insecure');

        try {
            config(['database.default' => 'mysql']);
            $this->app->detectEnvironment(fn () => 'production');
            (new AppServiceProvider($this->app))->boot();

            $safeUrl = new SafeUrl(resolver: fn (string $host) => ['93.184.216.34']);
            $this->expectException(ValidationException::class);
            $safeUrl->validate('http://example.com/webhook');
        } finally {
            $this->app->detectEnvironment(fn () => $originalEnv);
            config([
                'database.default' => $originalDb,
                'gateway.webhook_allow_insecure' => $originalWebhookInsecure,
            ]);
        }
    }

    public function test_non_production_allows_insecure_flags_when_explicitly_configured(): void
    {
        $originalWebhookInsecure = config('gateway.webhook_allow_insecure');
        $originalRedirectInsecure = config('gateway.allow_insecure_redirects');

        try {
            config([
                'gateway.webhook_allow_insecure' => true,
                'gateway.allow_insecure_redirects' => true,
            ]);

            $this->assertTrue(config('gateway.webhook_allow_insecure'));
            $this->assertTrue(config('gateway.allow_insecure_redirects'));

            // SafeUrl should allow http on port 80 in non-production when configured
            $safeUrl = new SafeUrl(resolver: fn (string $host) => ['93.184.216.34']);
            $validated = $safeUrl->validate('http://example.com/webhook');
            $this->assertSame(80, $validated['port']);
            $this->assertSame('example.com', $validated['host']);
        } finally {
            config([
                'gateway.webhook_allow_insecure' => $originalWebhookInsecure,
                'gateway.allow_insecure_redirects' => $originalRedirectInsecure,
            ]);
        }
    }
}
