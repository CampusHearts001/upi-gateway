<?php

namespace Tests;

use App\Models\Order;
use App\Services\OrderService;
use App\Services\VerificationLease;
use App\Services\Verifiers\VerificationResult;
use App\Support\SafeUrl;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function verificationLease(Order $order): VerificationLease
    {
        $lease = app(OrderService::class)->acquireVerificationLease($order);
        $this->assertNotNull($lease, 'Expected the test order to acquire a provider verification lease.');

        return $lease;
    }

    protected function markProviderPaid(Order $order, VerificationResult $result): Order
    {
        return app(OrderService::class)->markPaid($order, $result, $this->verificationLease($order));
    }

    protected function expireOrder(Order $order): Order
    {
        return app(OrderService::class)->expire($order, $this->verificationLease($order));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $installedPath = storage_path('framework/testing/installed');
        config(['installer.installed_path' => $installedPath]);
        if (! is_dir(dirname($installedPath))) {
            mkdir(dirname($installedPath), 0775, true);
        }
        file_put_contents($installedPath, 'test installation');

        // Disable rate limiting in tests by setting very high limits.
        config([
            'gateway.api_key_rate_limit' => 100000,
            'gateway.api_ip_rate_limit' => 100000,
        ]);

        $this->app->bind(SafeUrl::class, fn () => new SafeUrl(
            resolver: fn (string $host) => ['93.184.216.34']
        ));
    }
}
