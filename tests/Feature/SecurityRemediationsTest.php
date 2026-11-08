<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityRemediationsTest extends TestCase
{
    use RefreshDatabase;

    private function createMerchant(array $attributes = []): User
    {
        $user = User::create(array_merge([
            'name' => 'Test Merchant',
            'business_name' => 'Test Business',
            'email' => 'merchant@example.com',
            'password' => 'password12345',
        ], $attributes));
        $user->regenerateApiCredentials();
        $user->paymentAccounts()->create([
            'provider' => 'paytm_business',
            'label' => 'Primary Account',
            'upi_id' => 'paytmqr123@paytm',
            'payee_name' => 'Test Business',
            'credentials' => ['mid' => 'TESTMID123'],
            'is_default' => true,
        ]);

        return $user;
    }

    // ── 1. Live API Secret Exposure ──────────────────────────────────────────

    public function test_settings_page_masks_api_secret_by_default_and_does_not_expose_plaintext(): void
    {
        $merchant = $this->createMerchant();

        $response = $this->actingAs($merchant)->get(route('settings'));

        $response->assertOk();
        $response->assertSee('••••••••••••••••••••••••••••••••••••••••');
        $response->assertDontSee($merchant->api_secret);
        $response->assertDontSee('data-toggle-secret');
    }

    public function test_regenerating_keys_flashes_secret_once_and_renders_it_in_banner(): void
    {
        $merchant = $this->createMerchant();
        $oldSecret = $merchant->api_secret;

        $response = $this->actingAs($merchant)->post(route('settings.regenerate'));

        $merchant->refresh();
        $this->assertNotEquals($oldSecret, $merchant->api_secret);

        $response->assertRedirect();
        $response->assertSessionHas('live_api_secret_once_encrypted');

        $followResponse = $this->actingAs($merchant)->get(route('settings'));
        $followResponse->assertOk();
        $followResponse->assertSee($merchant->api_secret);
        $followResponse->assertSee('Live API secret · copy now');

        $subsequentResponse = $this->actingAs($merchant)->get(route('settings'));
        $subsequentResponse->assertOk();
        $subsequentResponse->assertDontSee($merchant->api_secret);
        $subsequentResponse->assertSee('••••••••••••••••••••••••••••••••••••••••');
    }

    public function test_live_api_authentication_works_with_regenerated_credentials(): void
    {
        $merchant = $this->createMerchant();
        $credentials = $merchant->regenerateApiCredentials();

        $response = $this->getJson('/api/v1/accounts', [
            'X-Api-Key' => $credentials['key'],
            'X-Api-Secret' => $credentials['secret'],
        ]);

        $response->assertOk();
    }

    // ── 2. Public Registration Toggle ────────────────────────────────────────

    public function test_registration_defaults_closed_while_existing_login_and_admin_controls_work(): void
    {
        $this->assertFalse(config('auth.allow_registration'));
        $this->get('/login')->assertOk()->assertDontSee(route('register'));
        $this->get('/')->assertOk()->assertDontSee(route('register'));
        $this->get('/docs')->assertOk()->assertDontSee(route('register'));
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Blocked Merchant',
            'business_name' => 'Blocked Business',
            'email' => 'blocked-default@example.com',
            'password' => 'password12345',
            'password_confirmation' => 'password12345',
        ])->assertNotFound();
        $this->assertDatabaseMissing('users', ['email' => 'blocked-default@example.com']);

        $merchant = $this->createMerchant();
        $this->post('/login', [
            'email' => $merchant->email,
            'password' => 'password12345',
        ])->assertRedirect(route('dashboard'));

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-registration@example.com',
            'password' => 'adminpass12345',
            'role' => 'admin',
        ]);
        $this->withoutMiddleware(RequirePassword::class);
        $this->actingAs($admin)->get(route('admin.merchants'))->assertOk()->assertSee($merchant->email);
        $this->actingAs($admin)->post(route('admin.merchants.toggle', $merchant))->assertRedirect();
        $this->assertFalse($merchant->fresh()->is_active);
    }

    public function test_public_registration_allowed_when_enabled(): void
    {
        config(['auth.allow_registration' => true]);

        $this->get('/login')->assertOk()->assertSee(route('register'));
        $this->get('/')->assertOk()->assertSee(route('register'));
        $this->get('/register')->assertOk()->assertSee('Create account');

        $this->post('/register', [
            'name' => 'New Merchant',
            'business_name' => 'New Biz',
            'email' => 'newbiz@example.com',
            'password' => 'password12345',
            'password_confirmation' => 'password12345',
        ])->assertRedirect(route('accounts.create'));

        $this->assertDatabaseHas('users', ['email' => 'newbiz@example.com']);
    }

    public function test_public_registration_blocked_when_disabled(): void
    {
        config(['auth.allow_registration' => false]);

        $this->get('/login')->assertOk()->assertDontSee(route('register'));
        $this->get('/register')->assertNotFound();

        $this->post('/register', [
            'name' => 'Blocked Merchant',
            'business_name' => 'Blocked Biz',
            'email' => 'blocked@example.com',
            'password' => 'password12345',
            'password_confirmation' => 'password12345',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'blocked@example.com']);
    }

    // ── 3. Order Lookup Ambiguity ────────────────────────────────────────────

    public function test_order_lookup_prioritizes_exact_order_no_over_colliding_client_order_id(): void
    {
        $merchant = $this->createMerchant();
        $account = $merchant->paymentAccounts()->first();

        // Order A: order_no is 'ORD_COLLIDE', client_order_id is 'CLIENT_A'
        Order::forceCreate([
            'user_id' => $merchant->id,
            'payment_account_id' => $account->id,
            'order_no' => 'ORD_COLLIDE',
            'client_order_id' => 'CLIENT_A',
            'amount' => '100.00',
            'status' => Order::PENDING,
            'environment' => Order::LIVE,
            'expires_at' => now()->addMinutes(15),
            'original_expires_at' => now()->addMinutes(15),
        ]);

        // Order B: order_no is 'ORD_OTHER', client_order_id is 'ORD_COLLIDE'
        Order::forceCreate([
            'user_id' => $merchant->id,
            'payment_account_id' => $account->id,
            'order_no' => 'ORD_OTHER',
            'client_order_id' => 'ORD_COLLIDE',
            'amount' => '200.00',
            'status' => Order::PENDING,
            'environment' => Order::LIVE,
            'expires_at' => now()->addMinutes(15),
            'original_expires_at' => now()->addMinutes(15),
        ]);

        $headers = [
            'X-Api-Key' => $merchant->api_key,
            'X-Api-Secret' => $merchant->api_secret,
            'Accept' => 'application/json',
        ];

        // Requesting 'ORD_COLLIDE' must return Order A (exact order_no), NOT Order B
        $responseA = $this->getJson('/api/v1/orders/ORD_COLLIDE', $headers);
        $responseA->assertOk();
        $this->assertSame('ORD_COLLIDE', $responseA->json('data.order_id'));
        $this->assertSame('100.00', $responseA->json('data.amount'));

        // Requesting 'ORD_OTHER' must return Order B (exact order_no)
        $responseB = $this->getJson('/api/v1/orders/ORD_OTHER', $headers);
        $responseB->assertOk();
        $this->assertSame('ORD_OTHER', $responseB->json('data.order_id'));
        $this->assertSame('200.00', $responseB->json('data.amount'));

        // Requesting 'CLIENT_A' falls back to client_order_id since no order_no matches
        $responseFallback = $this->getJson('/api/v1/orders/CLIENT_A', $headers);
        $responseFallback->assertOk();
        $this->assertSame('ORD_COLLIDE', $responseFallback->json('data.order_id'));
        $this->assertSame('CLIENT_A', $responseFallback->json('data.client_order_id'));
    }

    public function test_order_lookup_respects_merchant_and_environment_boundaries(): void
    {
        $merchant1 = $this->createMerchant(['email' => 'm1@example.com']);
        $merchant2 = $this->createMerchant(['email' => 'm2@example.com']);

        Order::forceCreate([
            'user_id' => $merchant1->id,
            'payment_account_id' => $merchant1->paymentAccounts()->first()->id,
            'order_no' => 'ORD_M1_ISOLATED',
            'client_order_id' => 'CLIENT_M1_ISOLATED',
            'amount' => '50.00',
            'status' => Order::PENDING,
            'environment' => Order::LIVE,
            'expires_at' => now()->addMinutes(15),
            'original_expires_at' => now()->addMinutes(15),
        ]);

        // Merchant 2 cannot access Merchant 1's order
        $this->getJson('/api/v1/orders/ORD_M1_ISOLATED', [
            'X-Api-Key' => $merchant2->api_key,
            'X-Api-Secret' => $merchant2->api_secret,
        ])->assertNotFound();

        // Merchant 1 accessing with Sandbox keys cannot access Live order
        $sandboxKeys = $merchant1->regenerateSandboxApiCredentials();
        $this->getJson('/api/v1/orders/ORD_M1_ISOLATED', [
            'X-Api-Key' => $sandboxKeys['key'],
            'X-Api-Secret' => $sandboxKeys['secret'],
        ])->assertNotFound();
    }

    // ── 4. LICENSE ───────────────────────────────────────────────────────────

    public function test_license_file_exists_and_contains_valid_mit_terms(): void
    {
        $licensePath = base_path('LICENSE');
        $this->assertFileExists($licensePath);

        $contents = file_get_contents($licensePath);
        $this->assertStringContainsString('MIT License', $contents);
        $this->assertStringContainsString('Copyright (c) 2026 Mayank Singh', $contents);
    }

    // ── 5. SECURITY.md ───────────────────────────────────────────────────────

    public function test_security_policy_does_not_contain_placeholder_emails(): void
    {
        $securityPath = base_path('SECURITY.md');
        $this->assertFileExists($securityPath);

        $contents = file_get_contents($securityPath);
        $this->assertStringNotContainsString('security@example.com', $contents);
        $this->assertStringNotContainsString('replace with your actual address', $contents);
        $this->assertStringContainsString('GitHub Security Advisories', $contents);
    }
}
