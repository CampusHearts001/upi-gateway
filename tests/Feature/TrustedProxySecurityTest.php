<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Providers\AppServiceProvider;
use App\Support\TrustedProxyConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use Tests\TestCase;

class TrustedProxySecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('web')->get('/proxy-inspect-test', fn (Request $request) => response()->json([
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
            'login_url' => route('login'),
        ]));
    }

    public function test_only_configured_proxy_controls_client_ip_and_https_scheme(): void
    {
        config(['proxy.trusted_proxies' => '10.0.0.5']);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5', 'HTTPS' => 'off'])
            ->withHeaders([
                'X-Forwarded-For' => '198.51.100.21',
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'attacker.example',
            ])
            ->get('/proxy-inspect-test')
            ->assertOk()
            ->assertJsonPath('ip', '198.51.100.21')
            ->assertJsonPath('secure', true)
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            ->assertJsonPath('login_url', 'https://localhost/login');
    }

    public function test_cidr_proxy_range_trusts_matching_proxy_ip(): void
    {
        config(['proxy.trusted_proxies' => '10.0.0.0/24']);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.88', 'HTTPS' => 'off'])
            ->withHeaders([
                'X-Forwarded-For' => '198.51.100.77',
                'X-Forwarded-Proto' => 'https',
            ])
            ->get('/proxy-inspect-test')
            ->assertOk()
            ->assertJsonPath('ip', '198.51.100.77')
            ->assertJsonPath('secure', true)
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_untrusted_proxy_headers_cannot_spoof_ip_or_https(): void
    {
        config(['proxy.trusted_proxies' => '10.0.0.5']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9', 'HTTPS' => 'off'])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.21', 'X-Forwarded-Proto' => 'https'])
            ->get('/proxy-inspect-test')
            ->assertOk()
            ->assertJsonPath('ip', '203.0.113.9')
            ->assertJsonPath('secure', false)
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_empty_proxy_list_never_trusts_forwarded_headers(): void
    {
        config(['proxy.trusted_proxies' => '']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9', 'HTTPS' => 'off'])
            ->withHeaders([
                'Host' => 'fake.on-forge.com',
                'X-Forwarded-For' => '198.51.100.21',
                'X-Forwarded-Proto' => 'https',
            ])
            ->get('/proxy-inspect-test')
            ->assertOk()
            ->assertJsonPath('ip', '203.0.113.9')
            ->assertJsonPath('secure', false)
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_proxy_allowlist_rejects_wildcards_and_bad_ranges(): void
    {
        $invalid = [
            '*',
            '**',
            '10.0.0.1, *',
            '0.0.0.0/0',
            '::/0',
            '10.0.0.1/0',
            '10.0.0.1/33',
            '10.0.0.1/-1',
            '10.0.0.1/abc',
            '10.0.0.1/',
            '2001:db8::/129',
            '2001:db8::/-5',
            '256.0.0.1',
            'not-an-ip',
            '10.0.0.1, ',
        ];

        foreach ($invalid as $value) {
            try {
                TrustedProxyConfiguration::addresses($value);
                $this->fail("Unsafe proxy configuration '{$value}' was accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_valid_and_boundary_cidrs_including_non_network_host_cidrs(): void
    {
        // Boundary IPv4 (/1 and /32)
        $this->assertSame(['10.0.0.0/1'], TrustedProxyConfiguration::addresses('10.0.0.0/1'));
        $this->assertSame(['10.0.0.1/32'], TrustedProxyConfiguration::addresses('10.0.0.1/32'));

        // Boundary IPv6 (/1 and /128)
        $this->assertSame(['2001:db8::/1'], TrustedProxyConfiguration::addresses('2001:db8::/1'));
        $this->assertSame(['2001:db8::1/128'], TrustedProxyConfiguration::addresses('2001:db8::1/128'));

        // Non-network host CIDR (10.0.0.1/24)
        $this->assertSame(['10.0.0.1/24'], TrustedProxyConfiguration::addresses('10.0.0.1/24'));

        // Combined list with whitespace
        $this->assertSame(
            ['10.0.0.5', '10.0.0.1/24', '2001:db8::/32'],
            TrustedProxyConfiguration::addresses('  10.0.0.5 , 10.0.0.1/24 ,  2001:db8::/32  ')
        );

        // Verify non-network CIDR works in Symfony Request resolution
        config(['proxy.trusted_proxies' => '10.0.0.1/24']);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.55', 'HTTPS' => 'off'])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.99', 'X-Forwarded-Proto' => 'https'])
            ->get('/proxy-inspect-test')
            ->assertOk()
            ->assertJsonPath('ip', '198.51.100.99')
            ->assertJsonPath('secure', true);
    }

    public function test_production_payment_url_uses_canonical_https_origin(): void
    {
        $oldEnv = config('app.env');
        $oldAppEnvironment = $this->app->environment();
        $oldDb = config('database.default');
        $oldUrl = config('app.url');
        try {
            config(['app.env' => 'production', 'database.default' => 'mysql', 'app.url' => 'http://payments.example']);
            $this->app->detectEnvironment(fn () => 'production');
            (new AppServiceProvider($this->app))->boot();
            $order = new Order(['order_no' => 'TEST-PROXY-ORDER']);
            $order->environment = Order::LIVE;
            $this->assertSame('https://payments.example/pay/TEST-PROXY-ORDER', $order->paymentUrl());
        } finally {
            config(['app.env' => $oldEnv, 'database.default' => $oldDb, 'app.url' => $oldUrl]);
            $this->app->detectEnvironment(fn () => $oldAppEnvironment);
            URL::useOrigin(null);
            URL::forceScheme(null);
        }
    }
}
