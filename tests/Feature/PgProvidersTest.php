<?php

namespace Tests\Feature;

use App\Jobs\SendWebhook;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Services\WebhookOutboxDispatcher;
use App\Support\PaytmChecksum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PgProvidersTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->merchant = User::create(['name' => 'Ravi', 'business_name' => 'Ravi Store', 'email' => 'ravi@example.com', 'password' => 'password123']);
        $this->merchant->regenerateApiCredentials();
        // Allow redirect_url used in createOrder() to pass the allowlist check.
        $this->merchant->update(['redirect_domains' => ['shop.test']]);
    }

    private function createOrder(float $amount = 499): string
    {
        return $this->postJson('/api/v1/orders', ['amount' => $amount, 'client_order_id' => 'INV'.random_int(1000, 99999), 'redirect_url' => 'https://shop.test/thanks'], [
            'X-Api-Key' => $this->merchant->api_key, 'X-Api-Secret' => $this->merchant->api_secret,
        ])->assertCreated()->assertJsonPath('data.upi_intent', null)->json('data.order_id');
    }

    private function phonepeAccount(string $environment = 'production'): PaymentAccount
    {
        $this->actingAs($this->merchant)->post(route('accounts.store'), [
            'provider' => 'phonepe_pg', 'label' => 'PhonePe', 'payee_name' => 'Ravi Store', 'environment' => $environment,
            'client_id' => 'CLIENT1', 'client_secret' => 'SECRET1', 'client_version' => '1',
        ])->assertSessionHasNoErrors();
        auth()->logout();

        return $this->merchant->paymentAccounts()->first();
    }

    private function paytmPgAccount(string $environment = 'production'): PaymentAccount
    {
        $this->actingAs($this->merchant)->post(route('accounts.store'), [
            'provider' => 'paytm_pg', 'label' => 'Paytm PG', 'payee_name' => 'Ravi Store', 'environment' => $environment,
            'mid' => 'TESTMID123', 'merchant_key' => 'abcd1234EFGH5678',
        ])->assertSessionHasNoErrors();
        auth()->logout();

        return $this->merchant->paymentAccounts()->first();
    }

    public function test_pg_accounts_validate_their_credentials_and_do_not_need_upi_id(): void
    {
        $this->actingAs($this->merchant);

        $this->post(route('accounts.store'), ['provider' => 'phonepe_pg', 'label' => 'P', 'payee_name' => 'R', 'environment' => 'sandbox', 'client_id' => 'C'])
            ->assertSessionHasErrors(['client_secret', 'client_version'])
            ->assertSessionDoesntHaveErrors('upi_id');

        $this->post(route('accounts.store'), ['provider' => 'paytm_pg', 'label' => 'P', 'payee_name' => 'R', 'environment' => 'sandbox', 'mid' => 'M1'])
            ->assertSessionHasErrors('merchant_key');

        $this->post(route('accounts.store'), ['provider' => 'manual', 'label' => 'M', 'payee_name' => 'R'])
            ->assertSessionHasErrors('upi_id');

        $account = $this->paytmPgAccount('sandbox');
        $this->assertNull($account->upi_id);
        $this->assertSame('WEBSTAGING', $account->credential('website_name'));
        $this->assertSame('sandbox', $account->environment());
    }

    public function test_phonepe_full_flow(): void
    {
        $this->phonepeAccount();
        $orderNo = $this->createOrder(499);

        $this->get("/pay/{$orderNo}")->assertOk()->assertSee('Pay securely with PhonePe');
        $this->get("/pay/{$orderNo}/qr.svg")->assertNotFound();

        Http::fake([
            'api.phonepe.com/apis/identity-manager/v1/oauth/token' => Http::response(['access_token' => 'TOKEN1', 'expires_at' => time() + 3600, 'token_type' => 'O-Bearer']),
            'api.phonepe.com/apis/pg/checkout/v2/pay' => Http::response(['orderId' => 'OMO1', 'state' => 'PENDING', 'expireAt' => (time() + 900) * 1000, 'redirectUrl' => 'https://mercury.phonepe.com/transact?token=abc']),
            "api.phonepe.com/apis/pg/checkout/v2/order/{$orderNo}/status*" => Http::sequence()
                ->push(['orderId' => 'OMO1', 'merchantOrderId' => $orderNo, 'state' => 'PENDING', 'amount' => 49900])
                ->push(['orderId' => 'OMO1', 'merchantOrderId' => $orderNo, 'state' => 'COMPLETED', 'amount' => 49900, 'paymentDetails' => [
                    ['transactionId' => 'OM123', 'paymentMode' => 'UPI_QR', 'amount' => 49900, 'state' => 'COMPLETED', 'rail' => ['type' => 'UPI', 'utr' => '512345678901'], 'completedAt' => now()->getTimestampMs()],
                ]]),
        ]);

        $this->post("/pay/{$orderNo}/start")->assertRedirect('https://mercury.phonepe.com/transact?token=abc');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/checkout/v2/pay')
            && $r->header('Authorization')[0] === 'O-Bearer TOKEN1'
            && $r['merchantOrderId'] === $orderNo
            && $r['amount'] === 49900
            && $r['paymentFlow']['merchantUrls']['redirectUrl'] === route('pay.return', $orderNo));

        // Reopening reuses the same PhonePe session instead of creating another.
        $this->post("/pay/{$orderNo}/start")->assertRedirect('https://mercury.phonepe.com/transact?token=abc');
        Http::assertSentCount(2); // 1 token + 1 pay

        // Customer returns before PhonePe marks it complete: still pending.
        $this->get("/pay/{$orderNo}/return")->assertRedirect(route('pay.show', $orderNo));
        $this->assertSame(Order::PENDING, Order::where('order_no', $orderNo)->value('status'));

        // Next check: completed -> paid, with UTR, and back to the merchant's site.
        $this->get("/pay/{$orderNo}/return")->assertRedirect("https://shop.test/thanks?order_id={$orderNo}&client_order_id=".Order::where('order_no', $orderNo)->value('client_order_id').'&status=paid');

        $order = Order::where('order_no', $orderNo)->first();
        $this->assertSame(Order::PAID, $order->status);
        $this->assertSame('512345678901', $order->utr);
        $this->assertSame('OM123', $order->provider_txn_id);
        app(WebhookOutboxDispatcher::class)->dispatchDue();
        Queue::assertPushed(SendWebhook::class, fn ($j) => $j->event === 'order.paid');
    }

    public function test_phonepe_amount_mismatch_is_not_paid_and_expired_token_is_refreshed(): void
    {
        $this->phonepeAccount();
        $orderNo = $this->createOrder(499);

        Http::fake([
            '*/v1/oauth/token' => Http::sequence()
                ->push(['access_token' => 'OLD', 'expires_at' => time() + 3600])
                ->push(['access_token' => 'NEW', 'expires_at' => time() + 3600]),
            "*/checkout/v2/order/{$orderNo}/status*" => Http::sequence()
                ->push(['message' => 'Unauthorized'], 401)
                ->push(['state' => 'COMPLETED', 'merchantOrderId' => $orderNo, 'amount' => 100]),
        ]);

        $this->get("/pay/{$orderNo}/return");

        $this->assertSame(Order::PENDING, Order::where('order_no', $orderNo)->value('status'));
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/status') && $r->header('Authorization')[0] === 'O-Bearer NEW');
    }

    public function test_phonepe_error_is_shown_to_customer(): void
    {
        $this->phonepeAccount();
        $orderNo = $this->createOrder();

        Http::fake(['*/v1/oauth/token' => Http::response(['error' => 'invalid_client'], 401)]);

        $this->post("/pay/{$orderNo}/start")->assertRedirect(route('pay.show', $orderNo))->assertSessionHasErrors('checkout');
    }

    public function test_provider_cannot_redirect_checkout_to_a_non_https_url(): void
    {
        $this->phonepeAccount();
        $orderNo = $this->createOrder();

        Http::fake([
            '*/v1/oauth/token' => Http::response(['access_token' => 'TOKEN1', 'expires_at' => time() + 3600]),
            '*/checkout/v2/pay' => Http::response([
                'orderId' => 'OMO1',
                'state' => 'PENDING',
                'expireAt' => (time() + 900) * 1000,
                'redirectUrl' => 'javascript:alert(1)',
            ]),
        ]);

        $this->post("/pay/{$orderNo}/start")
            ->assertRedirect(route('pay.show', $orderNo))
            ->assertSessionHasErrors('checkout');

        $this->assertNull(Order::where('order_no', $orderNo)->value('provider_checkout'));
    }

    public function test_paytm_pg_signed_flow_and_forged_callback_is_ignored(): void
    {
        $this->paytmPgAccount();
        $orderNo = $this->createOrder(250);

        Http::fake([
            'secure.paytmpayments.com/theia/api/v1/initiateTransaction*' => Http::response(['head' => [], 'body' => ['resultInfo' => ['resultStatus' => 'S', 'resultCode' => '0000'], 'txnToken' => 'TXNTOKEN1']]),
            'secure.paytmpayments.com/v3/order/status' => Http::sequence()
                ->push(['body' => ['resultInfo' => ['resultStatus' => 'PENDING']]])
                ->push(['body' => ['resultInfo' => ['resultStatus' => 'TXN_SUCCESS'], 'txnId' => 'PTM1', 'bankTxnId' => '612345678901', 'txnAmount' => '250.00', 'orderId' => $orderNo, 'txnDate' => now()->format('Y-m-d H:i:s')]]),
        ]);

        $this->get("/pay/{$orderNo}")->assertSee('Pay securely with Paytm');

        $this->post("/pay/{$orderNo}/start")->assertOk()
            ->assertSee('https://secure.paytmpayments.com/theia/api/v1/showPaymentPage?mid=TESTMID123&amp;orderId='.$orderNo, false)
            ->assertSee('value="TXNTOKEN1"', false);

        // The request body is signed with the merchant key, exactly as Paytm verifies it.
        Http::assertSent(function (HttpRequest $r) use ($orderNo) {
            if (! str_contains($r->url(), 'initiateTransaction')) {
                return false;
            }
            $raw = $r->body();
            $bodyJson = substr($raw, strlen('{"body":'), strpos($raw, ',"head":') - strlen('{"body":'));

            return PaytmChecksum::verify($bodyJson, 'abcd1234EFGH5678', $r['head']['signature'])
                && $r['body']['orderId'] === $orderNo
                && $r['body']['txnAmount']['value'] === '250.00'
                && $r['body']['callbackUrl'] === route('pay.return', $orderNo);
        });

        // Forged callback claiming success: ignored, Status API says pending.
        $this->post("/pay/{$orderNo}/return", ['STATUS' => 'TXN_SUCCESS', 'ORDERID' => $orderNo, 'TXNAMOUNT' => '250.00', 'CHECKSUMHASH' => 'fake'])
            ->assertRedirect(route('pay.show', $orderNo));
        $this->assertSame(Order::PENDING, Order::where('order_no', $orderNo)->value('status'));

        // Real success from the Status API.
        $this->post("/pay/{$orderNo}/return", []);
        $order = Order::where('order_no', $orderNo)->first();
        $this->assertSame(Order::PAID, $order->status);
        $this->assertSame('612345678901', $order->utr);
        $this->assertSame('PTM1', $order->provider_txn_id);
    }

    public function test_hosted_orders_do_not_accept_customer_utr(): void
    {
        $this->phonepeAccount();
        $orderNo = $this->createOrder();

        $this->get("/pay/{$orderNo}")->assertDontSee('Already paid? Enter your UTR');
        $this->post("/pay/{$orderNo}/utr", ['utr' => '412345678901'])->assertSessionHasErrors('utr');
    }
}
