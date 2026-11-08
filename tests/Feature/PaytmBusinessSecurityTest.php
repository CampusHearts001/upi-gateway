<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Verifiers\PaytmBusinessVerifier;
use App\Services\Verifiers\PaytmPgVerifier;
use App\Services\Verifiers\PhonePePgVerifier;
use App\Services\Verifiers\VerificationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaytmBusinessSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    private OrderService $orderService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orderService = app(OrderService::class);
        $this->merchant = User::create([
            'name' => 'Paytm Security Merchant',
            'email' => 'paytm-sec@example.com',
            'password' => 'password12345',
        ]);
        $this->merchant->regenerateApiCredentials();
    }

    /**
     * 1. Feature flag enabled permits Paytm Business account creation.
     */
    public function test_feature_flag_enabled_permits_paytm_business_configuration(): void
    {
        Config::set('gateway.enable_paytm_business', true);

        $response = $this->actingAs($this->merchant)->post('/accounts', [
            'provider' => 'paytm_business',
            'label' => 'Paytm Shop',
            'payee_name' => 'My Shop',
            'upi_id' => 'shop@paytm',
            'mid' => 'ACTIVEPAYTMMID123',
        ]);

        $response->assertRedirect('/accounts');
        $this->assertDatabaseHas('payment_accounts', [
            'user_id' => $this->merchant->id,
            'provider' => 'paytm_business',
            'label' => 'Paytm Shop',
        ]);

        $account = $this->merchant->paymentAccounts()->where('provider', 'paytm_business')->first();
        $this->assertSame('ACTIVEPAYTMMID123', $account->credential('mid'));
    }

    /**
     * 2. Feature flag disabled rejects new Paytm Business accounts.
     */
    public function test_feature_flag_disabled_rejects_new_paytm_business_accounts(): void
    {
        Config::set('gateway.enable_paytm_business', false);

        $response = $this->actingAs($this->merchant)->post('/accounts', [
            'provider' => 'paytm_business',
            'label' => 'Blocked Paytm',
            'payee_name' => 'My Shop',
            'upi_id' => 'shop@paytm',
            'mid' => 'BLOCKEDMID123',
        ]);

        $response->assertSessionHasErrors(['provider']);
        $this->assertDatabaseMissing('payment_accounts', [
            'user_id' => $this->merchant->id,
            'label' => 'Blocked Paytm',
        ]);
    }

    /**
     * 3. Feature flag disabled rejects resolving/configuring Paytm Business for new orders.
     */
    public function test_feature_flag_disabled_rejects_resolving_paytm_business_for_new_orders(): void
    {
        Config::set('gateway.enable_paytm_business', true);
        $account = $this->merchant->paymentAccounts()->create([
            'provider' => 'paytm_business',
            'label' => 'Existing Paytm',
            'payee_name' => 'Shop Payee',
            'upi_id' => 'existing@paytm',
            'credentials' => ['mid' => 'MIDEXISTING'],
            'is_default' => true,
            'is_active' => true,
        ]);

        Config::set('gateway.enable_paytm_business', false);

        $this->expectException(ValidationException::class);
        $this->orderService->create($this->merchant, [
            'amount' => 150.00,
            'payment_account_id' => $account->id,
        ]);
    }

    /**
     * 4. Existing Paytm Business orders cannot become PAID while disabled.
     */
    public function test_existing_paytm_business_orders_cannot_become_paid_while_disabled(): void
    {
        Config::set('gateway.enable_paytm_business', true);
        $account = $this->merchant->paymentAccounts()->create([
            'provider' => 'paytm_business',
            'label' => 'Existing Paytm',
            'payee_name' => 'Shop Payee',
            'upi_id' => 'existing@paytm',
            'credentials' => ['mid' => 'MIDEXISTING'],
            'is_default' => true,
            'is_active' => true,
        ]);
        $order = $this->orderService->create($this->merchant, [
            'amount' => 200.00,
            'payment_account_id' => $account->id,
        ])->load('paymentAccount');

        Config::set('gateway.enable_paytm_business', false);

        // Verification check must return disabled pending without marking order paid.
        $verifier = app(PaytmBusinessVerifier::class);
        $result = $verifier->check($order);

        $this->assertFalse($result->paid);
        $this->assertSame('disabled', $result->raw['code'] ?? null);
        $this->assertSame(Order::PENDING, $order->fresh()->status);
    }

    /**
     * 5. Disabled verification never silently falls back to another provider.
     */
    public function test_disabled_verification_never_silently_falls_back_to_another_provider(): void
    {
        Config::set('gateway.enable_paytm_business', true);
        $paytmAccount = $this->merchant->paymentAccounts()->create([
            'provider' => 'paytm_business',
            'label' => 'Paytm Account',
            'payee_name' => 'Shop Payee',
            'upi_id' => 'paytm@vpa',
            'credentials' => ['mid' => 'MIDPAYTM'],
            'is_default' => false,
            'is_active' => true,
        ]);
        $this->merchant->paymentAccounts()->create([
            'provider' => 'manual',
            'label' => 'Manual Account',
            'payee_name' => 'Shop Payee',
            'upi_id' => 'manual@vpa',
            'is_default' => true,
            'is_active' => true,
        ]);

        $order = $this->orderService->create($this->merchant, [
            'amount' => 100.00,
            'payment_account_id' => $paytmAccount->id,
        ])->load('paymentAccount');

        Config::set('gateway.enable_paytm_business', false);

        $result = app(PaytmBusinessVerifier::class)->check($order);

        $this->assertFalse($result->paid);
        $freshOrder = $order->fresh();
        $this->assertSame($paytmAccount->id, $freshOrder->payment_account_id);
        $this->assertSame('paytm_business', $freshOrder->paymentAccount->provider);
        $this->assertSame(Order::PENDING, $freshOrder->status);
    }

    /**
     * 6. Existing encrypted credentials and account records remain intact.
     */
    public function test_existing_encrypted_credentials_and_account_records_remain_intact(): void
    {
        Config::set('gateway.enable_paytm_business', true);
        $account = $this->merchant->paymentAccounts()->create([
            'provider' => 'paytm_business',
            'label' => 'Preserved Account',
            'payee_name' => 'Payee Name',
            'upi_id' => 'preserved@paytm',
            'credentials' => ['mid' => 'ORIGINAL_SECRET_MID_888'],
            'is_default' => true,
            'is_active' => true,
            'daily_limit' => 50000,
        ]);

        Config::set('gateway.enable_paytm_business', false);

        // Update daily limit via controller
        $this->actingAs($this->merchant)->post("/accounts/{$account->id}/limit", [
            'daily_limit' => 60000,
        ])->assertRedirect();

        $fresh = $account->fresh();
        $this->assertSame('paytm_business', $fresh->provider);
        $this->assertSame('ORIGINAL_SECRET_MID_888', $fresh->credential('mid'));
        $this->assertSame(60000.0, (float) $fresh->daily_limit);
    }

    /**
     * 7. Paytm Business is not preselected by default.
     */
    public function test_paytm_business_is_not_preselected_by_default(): void
    {
        Config::set('gateway.enable_paytm_business', true);

        $response = $this->actingAs($this->merchant)->get('/accounts/create');

        $response->assertOk();
        // Neither paytm_business nor any provider radio should be checked by default
        $response->assertDontSee('value="paytm_business" class="sr-only peer" checked', false);
        $response->assertDontSee('checked="checked"', false);
    }

    /**
     * 8. Unofficial / Experimental advisory and badge are rendered.
     */
    public function test_unofficial_experimental_advisory_and_badge_are_rendered(): void
    {
        Config::set('gateway.enable_paytm_business', true);

        $response = $this->actingAs($this->merchant)->get('/accounts/create');

        $response->assertOk();
        $response->assertSee('Unofficial / Experimental');
        $response->assertSee('Paytm for Business Integration Advisory');
        $response->assertSee('Paytm for Business status verification relies on an unofficial merchant status endpoint without signed response verification', false);
    }

    /**
     * 9. Timeout exceptions do not leak MID or JsonData into logs.
     */
    public function test_timeout_exceptions_do_not_leak_mid_or_jsondata_into_logs(): void
    {
        Config::set('gateway.enable_paytm_business', true);
        $account = $this->merchant->paymentAccounts()->create([
            'provider' => 'paytm_business',
            'label' => 'Timeout Test Account',
            'payee_name' => 'Payee',
            'upi_id' => 'payee@paytm',
            'credentials' => ['mid' => 'SENSITIVE_MID_777_XYZ'],
            'is_default' => true,
            'is_active' => true,
        ]);
        $order = $this->orderService->create($this->merchant, [
            'amount' => 50.00,
            'payment_account_id' => $account->id,
        ])->load('paymentAccount');

        // Simulate Guzzle/cURL exception including URL and JsonData in exception message
        Http::fake([
            config('gateway.paytm_status_url').'*' => fn () => throw new ConnectionException(
                'cURL error 28: Operation timed out after 10000ms for https://securegw.paytm.in/merchant-status/getTxnStatus?JsonData=%7B%22MID%22%3A%22SENSITIVE_MID_777_XYZ%22%2C%22ORDERID%22%3A%22'.$order->order_no.'%22%7D'
            ),
        ]);

        $logEntries = [];
        Log::listen(function ($event) use (&$logEntries) {
            $logEntries[] = json_encode([
                'message' => $event->message,
                'context' => $event->context,
            ]);
        });

        $result = app(PaytmBusinessVerifier::class)->check($order);

        $this->assertFalse($result->paid);
        $this->assertSame('timeout', $result->raw['code'] ?? null);
        $this->assertNotEmpty($logEntries, 'Expected warning log was not captured');

        foreach ($logEntries as $entry) {
            $this->assertStringNotContainsString('SENSITIVE_MID_777_XYZ', $entry);
            $this->assertStringNotContainsString('JsonData', $entry);
            $this->assertStringNotContainsString('securegw.paytm.in', $entry);
        }
    }

    /**
     * 10. Sanitized pending results do not persist sensitive exception data.
     */
    public function test_sanitized_pending_results_do_not_persist_sensitive_exception_data(): void
    {
        Config::set('gateway.enable_paytm_business', true);
        $account = $this->merchant->paymentAccounts()->create([
            'provider' => 'paytm_business',
            'label' => 'Leak Check Account',
            'payee_name' => 'Payee',
            'upi_id' => 'payee@paytm',
            'credentials' => ['mid' => 'SUPER_SECRET_PAYTM_MID'],
            'is_default' => true,
            'is_active' => true,
        ]);
        $order = $this->orderService->create($this->merchant, [
            'amount' => 75.00,
            'payment_account_id' => $account->id,
        ])->load('paymentAccount');

        Http::fake([
            config('gateway.paytm_status_url').'*' => fn () => throw new ConnectionException(
                'Connection refused on https://securegw.paytm.in?JsonData={"MID":"SUPER_SECRET_PAYTM_MID"}'
            ),
        ]);

        $result = app(PaytmBusinessVerifier::class)->check($order);

        $this->assertFalse($result->paid);
        $this->assertSame('connection_failed', $result->raw['code']);
        $this->assertSame('Paytm status endpoint connection failed.', $result->raw['error']);

        $serialized = json_encode($result->raw);
        $this->assertStringNotContainsString('SUPER_SECRET_PAYTM_MID', $serialized);
        $this->assertStringNotContainsString('JsonData', $serialized);
    }

    /**
     * 11. Successful Paytm Business verification remains functional when enabled.
     */
    public function test_successful_paytm_business_verification_remains_functional_when_enabled(): void
    {
        Config::set('gateway.enable_paytm_business', true);
        $account = $this->merchant->paymentAccounts()->create([
            'provider' => 'paytm_business',
            'label' => 'Valid Account',
            'payee_name' => 'Payee',
            'upi_id' => 'valid@paytm',
            'credentials' => ['mid' => 'VALIDMID123'],
            'is_default' => true,
            'is_active' => true,
        ]);
        $order = $this->orderService->create($this->merchant, [
            'amount' => 125.00,
            'payment_account_id' => $account->id,
        ])->load('paymentAccount');

        Http::fake([
            config('gateway.paytm_status_url').'*' => Http::response([
                'STATUS' => 'TXN_SUCCESS',
                'ORDERID' => $order->order_no,
                'TXNAMOUNT' => '125.00',
                'CURRENCY' => 'INR',
                'BANKTXNID' => '998877665544',
                'TXNID' => 'TXN998877',
                'TXNDATE' => now()->format('Y-m-d H:i:s'),
            ]),
        ]);

        $result = app(PaytmBusinessVerifier::class)->check($order);

        $this->assertTrue($result->paid);
        $this->assertSame('998877665544', $result->utr);
        $this->assertSame('TXN998877', $result->txnId);
        $this->assertSame('125.00', $result->amount);
    }

    /**
     * 12. Official Paytm PG checksum verification remains unaffected.
     */
    public function test_official_paytm_pg_checksum_verification_remains_unaffected(): void
    {
        Config::set('gateway.enable_paytm_business', false); // even when paytm_business is disabled

        $pgAccount = $this->merchant->paymentAccounts()->create([
            'provider' => 'paytm_pg',
            'label' => 'Official PG',
            'payee_name' => 'Paytm PG Store',
            'credentials' => [
                'mid' => 'OFFICIAL_PG_MID',
                'merchant_key' => '1234567890abcdef',
                'website_name' => 'DEFAULT',
                'environment' => 'production',
            ],
            'is_default' => true,
            'is_active' => true,
        ]);
        $order = $this->orderService->create($this->merchant, [
            'amount' => 300.00,
            'payment_account_id' => $pgAccount->id,
        ])->load('paymentAccount');

        Http::fake([
            '*/v3/order/status' => function ($request) use ($order) {
                // Verify request was signed with checksum
                $payload = json_decode($request->body(), true);
                $this->assertNotEmpty($payload['head']['signature'] ?? null);

                return Http::response([
                    'body' => [
                        'resultInfo' => ['resultStatus' => 'TXN_SUCCESS'],
                        'orderId' => $order->order_no,
                        'txnAmount' => '300.00',
                        'currency' => 'INR',
                        'bankTxnId' => 'PG_BANK_UTR_555',
                        'txnId' => 'PG_TXN_555',
                        'txnDate' => now()->format('Y-m-d H:i:s'),
                    ],
                ]);
            },
        ]);

        $result = app(PaytmPgVerifier::class)->check($order);

        $this->assertTrue($result->paid);
        $this->assertSame('PG_BANK_UTR_555', $result->utr);
        $this->assertSame('300.00', $result->amount);
    }

    /**
     * 13. PhonePe PG remains unaffected.
     */
    public function test_phonepe_pg_remains_unaffected(): void
    {
        Config::set('gateway.enable_paytm_business', false); // even when paytm_business is disabled

        $ppAccount = $this->merchant->paymentAccounts()->create([
            'provider' => 'phonepe_pg',
            'label' => 'Official PhonePe',
            'payee_name' => 'PhonePe Store',
            'credentials' => [
                'client_id' => 'PP_CLIENT_ID',
                'client_secret' => 'PP_CLIENT_SECRET',
                'client_version' => '1',
                'environment' => 'production',
            ],
            'is_default' => true,
            'is_active' => true,
        ]);
        $order = $this->orderService->create($this->merchant, [
            'amount' => 400.00,
            'payment_account_id' => $ppAccount->id,
        ])->load('paymentAccount');

        Http::fake([
            '*/v1/oauth/token' => Http::response(['access_token' => 'VALID_PP_TOKEN', 'expires_at' => time() + 3600]),
            "*/checkout/v2/order/{$order->order_no}/status*" => Http::response([
                'orderId' => 'PP_ORDER_INT_123',
                'merchantOrderId' => $order->order_no,
                'state' => 'COMPLETED',
                'amount' => 40000,
                'paymentDetails' => [
                    [
                        'transactionId' => 'PP_TXN_999',
                        'rail' => [
                            'utr' => 'PP_BANK_UTR_999',
                        ],
                        'state' => 'COMPLETED',
                        'amount' => 40000,
                        'timestamp' => now()->timestamp * 1000,
                    ],
                ],
            ]),
        ]);

        $result = app(PhonePePgVerifier::class)->check($order);

        $this->assertTrue($result->paid);
        $this->assertSame('PP_BANK_UTR_999', $result->utr);
        $this->assertSame('400.00', $result->amount);
    }

    /**
     * 14. Rotation pool excludes Paytm Business accounts when disabled.
     */
    public function test_rotation_pool_excludes_paytm_business_when_disabled(): void
    {
        Config::set('gateway.enable_paytm_business', true);
        Http::fake();

        $paytmAccount = $this->merchant->paymentAccounts()->create([
            'provider' => 'paytm_business',
            'label' => 'Default Paytm Account',
            'payee_name' => 'Paytm Payee',
            'upi_id' => 'paytm@vpa',
            'credentials' => ['mid' => 'MID_ROTATION_123'],
            'is_default' => true,
            'is_active' => true,
        ]);

        $manualAccount = $this->merchant->paymentAccounts()->create([
            'provider' => 'manual',
            'label' => 'Manual Account',
            'payee_name' => 'Manual Payee',
            'upi_id' => 'manual@vpa',
            'is_default' => false,
            'is_active' => true,
        ]);

        Config::set('gateway.enable_paytm_business', false);

        // Order created without explicitly supplying payment_account_id
        $order = $this->orderService->create($this->merchant, [
            'amount' => 500.00,
        ]);

        $this->assertSame($manualAccount->id, $order->payment_account_id);
        $this->assertSame('manual', $order->paymentAccount->provider);

        // Confirm no Paytm Business verification request was dispatched
        Http::assertNothingSent();
    }

    /**
     * 15. Direct markPaid invocation rejects Paytm Business orders while disabled without partial state.
     */
    public function test_direct_mark_paid_invocation_rejects_paytm_business_when_disabled(): void
    {
        Config::set('gateway.enable_paytm_business', true);
        $account = $this->merchant->paymentAccounts()->create([
            'provider' => 'paytm_business',
            'label' => 'Direct Mark Paid Paytm',
            'payee_name' => 'Paytm Payee',
            'upi_id' => 'direct@paytm',
            'credentials' => ['mid' => 'MID_DIRECT_MARK_PAID'],
            'is_default' => true,
            'is_active' => true,
        ]);

        $order = $this->orderService->create($this->merchant, [
            'amount' => 600.00,
            'payment_account_id' => $account->id,
        ])->load('paymentAccount');

        Config::set('gateway.enable_paytm_business', false);

        $lease = $this->orderService->acquireVerificationLease($order);
        $this->assertNotNull($lease);

        $syntheticResult = VerificationResult::fromPaytmBusiness(
            order: $order,
            amount: '600.00',
            utr: '987654321098',
            txnId: 'TXN-SYNTHETIC-999',
            raw: ['STATUS' => 'TXN_SUCCESS'],
            currency: 'INR',
            occurredAt: now()->toImmutable(),
        );

        $thrown = false;
        try {
            $this->orderService->markPaid($order, $syntheticResult, $lease);
        } catch (ValidationException $e) {
            $thrown = true;
            $this->assertArrayHasKey('order', $e->errors());
        }

        $this->assertTrue($thrown, 'Expected ValidationException was not thrown on direct markPaid call');

        // Assert order remains unpaid and no partial state is left
        $fresh = $order->fresh();
        $this->assertSame(Order::PENDING, $fresh->status);
        $this->assertNull($fresh->utr);
        $this->assertNull($fresh->provider_txn_id);
        $this->assertNull($fresh->provider_response);
        $this->assertNull($fresh->verified_by);
        $this->assertNull($fresh->paid_at);
        $this->assertDatabaseMissing('webhook_outbox', [
            'order_id' => $order->id,
            'event' => 'order.paid',
        ]);
    }
}
