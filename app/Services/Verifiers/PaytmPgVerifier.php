<?php

namespace App\Services\Verifiers;

use App\Models\Order;
use App\Models\PaymentAccount;
use App\Support\PaytmChecksum;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Official Paytm Payment Gateway.
 */
class PaytmPgVerifier implements HostedCheckout, Verifier
{
    public function startCheckout(Order $order, string $returnUrl): array
    {
        $order->load('paymentAccount');
        $order->paymentAccount?->assertLiveProviderOrder($order, 'paytm_pg');
        abort_unless($order->paymentAccount, 422, 'A matching payment account is required.');

        $account = $order->paymentAccount;
        $mid = $account->credential('mid');
        $base = $this->baseUrl($account);

        $response = $this->signedPost(
            $account,
            "$base/theia/api/v1/initiateTransaction?".http_build_query(['mid' => $mid, 'orderId' => $order->order_no]),
            [
                'requestType' => 'Payment',
                'mid' => $mid,
                'websiteName' => $account->credential('website_name') ?: 'DEFAULT',
                'orderId' => $order->order_no,
                'callbackUrl' => $returnUrl,
                'txnAmount' => [
                    'value' => number_format((float) $order->amount, 2, '.', ''),
                    'currency' => 'INR',
                ],
                'userInfo' => array_filter([
                    'custId' => 'CUST'.$order->user_id.'X'.$order->id,
                    'mobile' => $order->customer_mobile,
                    'email' => $order->customer_email,
                ]),
            ]
        );

        $token = $response->json('body.txnToken');

        if ($response->json('body.resultInfo.resultStatus') !== 'S' || ! $token) {
            // Log only safe fields — never log merchant_key or full body.
            Log::warning('Paytm initiate transaction failed', [
                'order_id' => $order->order_no,
                'result_code' => $response->json('body.resultInfo.resultCode'),
                'result_msg' => $response->json('body.resultInfo.resultMsg'),
            ]);

            throw new RuntimeException('Paytm could not start the payment. Please try again.');
        }

        return [
            'type' => 'form',
            'url' => "$base/theia/api/v1/showPaymentPage?".http_build_query(['mid' => $mid, 'orderId' => $order->order_no]),
            'fields' => ['mid' => $mid, 'orderId' => $order->order_no, 'txnToken' => $token],
            'expires_at' => now()->addMinutes(14)->timestamp,
        ];
    }

    public function check(Order $order): VerificationResult
    {
        $order->load('paymentAccount');
        $order->paymentAccount?->assertLiveProviderOrder($order, 'paytm_pg');
        abort_unless($order->paymentAccount, 422, 'A matching payment account is required.');

        $account = $order->paymentAccount;

        try {
            $response = $this->signedPost($account, $this->baseUrl($account).'/v3/order/status', [
                'mid' => $account->credential('mid'),
                'orderId' => $order->order_no,
            ]);
        } catch (Throwable $e) {
            return VerificationResult::pending(['error' => $e->getMessage()]);
        }

        $body = $response->json('body') ?? [];

        if (($body['resultInfo']['resultStatus'] ?? null) !== 'TXN_SUCCESS') {
            return VerificationResult::pending($body);
        }

        // Verify the order ID in the response matches our order.
        if (($body['orderId'] ?? null) !== $order->order_no) {
            Log::warning('Paytm PG order ID mismatch', [
                'order_id' => $order->order_no,
                'response_id' => $body['orderId'] ?? null,
            ]);

            return VerificationResult::pending(['error' => 'Order ID mismatch']);
        }

        if (! is_numeric($body['txnAmount'] ?? null)
            || ! preg_match('/^\d+(?:\.\d{1,2})?$/', (string) $body['txnAmount'])
            || $this->toPaise($body['txnAmount']) !== $this->toPaise($order->amount)) {
            Log::warning('Paytm PG amount mismatch', [
                'order_id' => $order->order_no,
                'expected_paise' => $this->toPaise($order->amount),
                'got_paise' => is_numeric($body['txnAmount'] ?? null) ? $this->toPaise($body['txnAmount']) : null,
                // Do NOT log $body — may contain customer PII or payment details.
            ]);

            return VerificationResult::pending(['error' => 'Amount mismatch']);
        }

        if (($body['currency'] ?? 'INR') !== 'INR') {
            return VerificationResult::pending(['error' => 'Currency mismatch']);
        }
        if (trim((string) ($body['bankTxnId'] ?? '')) === '' && trim((string) ($body['txnId'] ?? '')) === '') {
            return VerificationResult::pending(['error' => 'Missing transaction reference']);
        }

        $occurredAt = VerificationResult::extractRawTimestamp('paytm_pg', $body);
        if ($occurredAt === null && (bool) config('gateway.evidence_require_timestamp', true)) {
            Log::warning('Paytm PG transaction missing or invalid timestamp', [
                'order_id' => $order->order_no,
            ]);

            return VerificationResult::pending(['error' => 'Missing or invalid provider transaction timestamp']);
        }

        return VerificationResult::fromPaytmPg(
            order: $order,
            amount: $body['txnAmount'],
            utr: $body['bankTxnId'] ?? null,
            txnId: $body['txnId'] ?? null,
            raw: $body,
            currency: $body['currency'] ?? 'INR',
            occurredAt: $occurredAt,
        );
    }

    /**
     * Paytm signs the exact JSON string of `body`.
     * merchant_key is a credential — never logged.
     */
    private function signedPost(PaymentAccount $account, string $url, array $body): Response
    {
        $bodyJson = json_encode($body, JSON_UNESCAPED_SLASHES);
        $signature = PaytmChecksum::generate($bodyJson, (string) $account->credential('merchant_key'));
        $payload = '{"body":'.$bodyJson.',"head":{"signature":'.json_encode($signature).'}}';

        return Http::timeout(15)->acceptJson()->withBody($payload, 'application/json')->post($url);
    }

    private function baseUrl(PaymentAccount $account): string
    {
        return rtrim(
            config('gateway.paytm_pg.'.($account->credential('environment') === 'production' ? 'production' : 'sandbox')),
            '/'
        );
    }

    private function toPaise(string|float|int $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
