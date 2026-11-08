<?php

namespace Tests\Feature;

use App\Jobs\SendWebhook;
use App\Models\Order;
use App\Models\User;
use App\Services\WebhookOutboxDispatcher;
use App\Support\WebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(): User
    {
        $user = User::create([
            'name' => 'Ravi', 'business_name' => 'Ravi Store', 'email' => 'ravi@example.com', 'password' => 'password123',
        ]);
        $user->regenerateApiCredentials();
        $user->paymentAccounts()->create([
            'provider' => 'paytm_business', 'label' => 'Main', 'upi_id' => 'paytmqr123@paytm',
            'payee_name' => 'Ravi Store', 'credentials' => ['mid' => 'TESTMID123'], 'is_default' => true,
        ]);

        return $user;
    }

    private function apiHeaders(User $user): array
    {
        return ['X-Api-Key' => $user->api_key, 'X-Api-Secret' => $user->api_secret, 'Accept' => 'application/json'];
    }

    public function test_register_and_add_upi_account(): void
    {
        config(['auth.allow_registration' => true]);

        $this->post('/register', [
            'name' => 'Asha', 'business_name' => 'Asha Foods', 'email' => 'asha@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertRedirect(route('accounts.create'));

        $this->post('/accounts', [
            'provider' => 'paytm_business', 'label' => 'Shop', 'upi_id' => 'paytmqr999@paytm',
            'payee_name' => 'Asha Foods', 'mid' => 'MID999',
        ])->assertRedirect(route('accounts.index'));

        $account = User::where('email', 'asha@example.com')->first()->paymentAccounts()->first();
        $this->assertTrue($account->is_default);
        $this->assertSame('MID999', $account->credential('mid'));
        $this->get('/dashboard')->assertOk()->assertSee('Asha Foods');
    }

    public function test_api_rejects_bad_credentials(): void
    {
        $this->merchant();
        $this->postJson('/api/v1/orders', ['amount' => 10], ['X-Api-Key' => 'nope', 'X-Api-Secret' => 'nope'])
            ->assertUnauthorized();
    }

    public function test_full_paytm_flow_marks_paid_and_sends_signed_webhook(): void
    {
        $user = $this->merchant();
        $user->update(['webhook_url' => 'https://merchant.test/hook']);

        $res = $this->postJson('/api/v1/orders', ['amount' => 199.5, 'client_order_id' => 'INV1'], $this->apiHeaders($user))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', '199.50');

        $orderNo = $res->json('data.order_id');
        $this->assertStringContainsString("tr={$orderNo}", $res->json('data.upi_intent'));
        $this->assertStringContainsString('pa=paytmqr123%40paytm', $res->json('data.upi_intent'));

        // Duplicate client_order_id is rejected.
        $this->postJson('/api/v1/orders', ['amount' => 5, 'client_order_id' => 'INV1'], $this->apiHeaders($user))
            ->assertUnprocessable();

        $this->get("/pay/{$orderNo}")->assertOk()->assertSee('Ravi Store')->assertSee('199.50');
        $this->get("/pay/{$orderNo}/qr.svg")->assertOk()->assertHeader('Content-Type', 'image/svg+xml');

        // Not paid yet.
        Http::fake(['securegw.paytm.in/*' => Http::sequence()
            ->push(['STATUS' => 'TXN_FAILURE', 'RESPMSG' => 'Invalid Order Id'])
            ->push(['STATUS' => 'TXN_SUCCESS', 'TXNAMOUNT' => '199.50', 'BANKTXNID' => '412345678901', 'TXNID' => 'T1', 'ORDERID' => $orderNo, 'TXNDATE' => now()->format('Y-m-d H:i:s')]),
            'merchant.test/*' => Http::response('ok'),
        ]);

        $this->getJson("/pay/{$orderNo}/status")->assertJson(['status' => 'pending']);

        $this->travel(5)->seconds();
        $this->getJson("/pay/{$orderNo}/status")->assertJson(['status' => 'paid']);

        $order = Order::where('order_no', $orderNo)->first();
        $this->assertSame('412345678901', $order->utr);
        $this->assertSame('provider', $order->verified_by);

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'securegw.paytm.in')
            && str_contains(urldecode($r->url()), '"MID":"TESTMID123"'));

        // Webhook (sync queue in tests) is signed with the API secret.
        app(WebhookOutboxDispatcher::class)->dispatchDue();
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://merchant.test/hook'
            && WebhookSignature::verify($r->body(), $r->header('X-Signature')[0], $user->api_secret)
            && $r['event'] === 'order.paid');
        $this->assertTrue($order->webhookLogs()->first()->success);

        $this->getJson('/api/v1/orders/INV1', $this->apiHeaders($user))->assertJsonPath('data.status', 'paid');
    }

    public function test_amount_mismatch_is_not_marked_paid(): void
    {
        $user = $this->merchant();
        Queue::fake();
        Http::fake(['securegw.paytm.in/*' => Http::response(['STATUS' => 'TXN_SUCCESS', 'TXNAMOUNT' => '1.00'])]);

        $orderNo = $this->postJson('/api/v1/orders', ['amount' => 500, 'client_order_id' => 'INV2'], $this->apiHeaders($user))
            ->json('data.order_id');

        $this->getJson("/pay/{$orderNo}/status")->assertJson(['status' => 'pending']);
        Queue::assertNothingPushed();
    }

    public function test_order_expires_and_manual_mark_paid_works(): void
    {
        $user = $this->merchant();
        Queue::fake();
        Http::fake(['securegw.paytm.in/*' => Http::response(['STATUS' => 'PENDING'])]);

        $orderNo = $this->postJson('/api/v1/orders', ['amount' => 50, 'client_order_id' => 'INV3'], $this->apiHeaders($user))
            ->json('data.order_id');

        $this->travel(20)->minutes();
        $this->artisan('orders:check-pending')->assertSuccessful();
        $this->assertSame('expired', Order::where('order_no', $orderNo)->value('status'));
        app(WebhookOutboxDispatcher::class)->dispatchDue();
        Queue::assertPushed(SendWebhook::class, fn ($job) => $job->event === 'order.expired');

        // Merchant-submitted UTR is never sufficient to mark a Live order paid.
        $this->actingAs($user)->post("/orders/{$orderNo}/mark-paid", ['utr' => '998877665544'])
            ->assertRedirect()->assertSessionHasErrors('utr');
        $this->assertSame('expired', Order::where('order_no', $orderNo)->value('status'));
    }

    public function test_merchant_cannot_see_other_merchants_orders(): void
    {
        $owner = $this->merchant();
        $orderNo = $this->postJson('/api/v1/orders', ['amount' => 10, 'client_order_id' => 'X1'], $this->apiHeaders($owner))
            ->json('data.order_id');

        $other = User::create(['name' => 'B', 'email' => 'b@example.com', 'password' => 'password123']);
        $this->actingAs($other)->get("/orders/{$orderNo}")->assertNotFound();
        $this->actingAs($other)->post("/orders/{$orderNo}/mark-paid", ['utr' => '123456789'])->assertNotFound();
        $this->actingAs($other)->get('/admin/merchants')->assertForbidden();
    }
}
