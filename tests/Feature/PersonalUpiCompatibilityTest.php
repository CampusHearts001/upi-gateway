<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonalUpiCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::create([
            'name' => 'Kiran Merchant',
            'business_name' => 'Kiran Store',
            'email' => 'kiran@example.com',
            'password' => 'password123',
        ]);
        $this->merchant->regenerateApiCredentials();
    }

    public function test_manual_personal_upi_omits_tr_and_retains_tn(): void
    {
        $manualAccount = $this->merchant->paymentAccounts()->create([
            'provider' => 'manual',
            'label' => 'Personal GPay',
            'upi_id' => 'kiran@okaxis',
            'payee_name' => 'Kiran Kumar',
            'is_default' => true,
        ]);

        $order = app(OrderService::class)->create($this->merchant, [
            'amount' => 299.50,
            'client_order_id' => 'CLI-1001',
            'note' => 'Book purchase',
        ]);

        $url = $order->upiIntentUrl();
        $this->assertStringStartsWith('upi://pay?', $url);

        $queryString = parse_url($url, PHP_URL_QUERY);
        parse_str($queryString, $params);

        // Manual personal UPI account must omit `tr`
        $this->assertArrayNotHasKey('tr', $params, 'tr parameter must be omitted for personal/manual UPI accounts');

        // Order reference retained in `tn`
        $this->assertSame('Book purchase', $params['tn']);
        $this->assertSame('kiran@okaxis', $params['pa']);
        $this->assertSame('Kiran Kumar', $params['pn']);
        $this->assertSame('299.50', $params['am']);
        $this->assertSame('INR', $params['cu']);

        // When order has no custom note, `tn` defaults to `order_no`
        $orderNoNote = app(OrderService::class)->create($this->merchant, [
            'amount' => 100.00,
        ]);
        parse_str(parse_url($orderNoNote->upiIntentUrl(), PHP_URL_QUERY), $paramsNoNote);
        $this->assertArrayNotHasKey('tr', $paramsNoNote);
        $this->assertSame($orderNoNote->order_no, $paramsNoNote['tn']);
    }

    public function test_provider_upi_accounts_retain_tr_parameter(): void
    {
        $this->merchant->paymentAccounts()->delete();
        $providerAccount = $this->merchant->paymentAccounts()->create([
            'provider' => 'paytm_business',
            'label' => 'Paytm Merchant QR',
            'upi_id' => 'paytmqr123@paytm',
            'payee_name' => 'Kiran Store',
            'credentials' => ['mid' => 'MID12345'],
            'is_default' => true,
        ]);

        $order = app(OrderService::class)->create($this->merchant, [
            'amount' => 500.00,
            'client_order_id' => 'CLI-2002',
        ]);

        $url = $order->upiIntentUrl();
        $queryString = parse_url($url, PHP_URL_QUERY);
        parse_str($queryString, $params);

        // Provider accounts require `tr` for transaction lookup
        $this->assertArrayHasKey('tr', $params, 'tr parameter must be present for payment provider accounts');
        $this->assertSame($order->order_no, $params['tr']);
        $this->assertSame('paytmqr123@paytm', $params['pa']);
        $this->assertSame('500.00', $params['am']);
    }

    public function test_custom_scheme_generation_for_specific_upi_apps(): void
    {
        $this->merchant->paymentAccounts()->create([
            'provider' => 'manual',
            'label' => 'Manual PhonePe',
            'upi_id' => 'kiran@ybl',
            'payee_name' => 'Kiran Kumar',
            'is_default' => true,
        ]);

        $order = app(OrderService::class)->create($this->merchant, ['amount' => 150.00]);

        $gpayUrl = $order->upiIntentUrl('gpay://upi/pay');
        $phonepeUrl = $order->upiIntentUrl('phonepe://pay');
        $paytmUrl = $order->upiIntentUrl('paytmmp://pay');

        $this->assertStringStartsWith('gpay://upi/pay?', $gpayUrl);
        $this->assertStringStartsWith('phonepe://pay?', $phonepeUrl);
        $this->assertStringStartsWith('paytmmp://pay?', $paytmUrl);

        $this->assertStringNotContainsString('tr=', $gpayUrl);
        $this->assertStringNotContainsString('tr=', $phonepeUrl);
        $this->assertStringNotContainsString('tr=', $paytmUrl);
    }

    public function test_checkout_page_renders_compatible_links_and_qr(): void
    {
        $this->merchant->paymentAccounts()->create([
            'provider' => 'manual',
            'label' => 'Personal Account',
            'upi_id' => 'kiran@upi',
            'payee_name' => 'Kiran',
            'is_default' => true,
        ]);

        $order = app(OrderService::class)->create($this->merchant, ['amount' => 199.00]);

        // Checkout page contains link without tr
        $this->get(route('pay.show', $order))
            ->assertOk()
            ->assertSee('upi://pay?pa=kiran%40upi', false)
            ->assertDontSee('tr='.$order->order_no);

        // QR code image response
        $this->get(route('pay.qr', $order))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml');
    }
}
