<?php

namespace App\Services\Verifiers;

use App\Models\Order;
use App\Models\PaymentAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Official PhonePe PG, Standard Checkout v2 (OAuth client credentials).
 */
class PhonePePgVerifier implements HostedCheckout, Verifier
{
    public function startCheckout(Order $order, string $returnUrl): array
    {
        $order->load('paymentAccount');
        $order->paymentAccount?->assertLiveProviderOrder($order, 'phonepe_pg');
        abort_unless($order->paymentAccount, 422, 'A matching payment account is required.');

        $secondsLeft = (int) now()->diffInSeconds($order->expires_at, false);

        $response = $this->request(
            $order->paymentAccount,
            fn (PendingRequest $http, string $base) => $http->post("$base/checkout/v2/pay", [
                'merchantOrderId' => $order->order_no,
                'amount' => $this->toPaise($order->amount),
                'expireAfter' => max(300, min(3600, $secondsLeft)),
                'paymentFlow' => [
                    'type' => 'PG_CHECKOUT',
                    'message' => $order->note ?: 'Payment for '.$order->client_order_id,
                    'merchantUrls' => ['redirectUrl' => $returnUrl],
                ],
                'metaInfo' => ['udf1' => $order->client_order_id],
            ])
        );

        $url = $response->json('redirectUrl');

        if (! $response->successful() || ! $url) {
            // Log only safe fields — never log client_secret, full response body with PII.
            Log::warning('PhonePe create payment failed', [
                'order_id' => $order->order_no,
                'http_status' => $response->status(),
                'message' => $response->json('message') ?? 'no message',
            ]);

            throw new RuntimeException('PhonePe could not start the payment. Please try again.');
        }

        return [
            'type' => 'redirect',
            'url' => $url,
            'expires_at' => (int) (($response->json('expireAt') ?? now()->addMinutes(15)->getTimestampMs()) / 1000),
        ];
    }

    public function check(Order $order): VerificationResult
    {
        $order->load('paymentAccount');
        $order->paymentAccount?->assertLiveProviderOrder($order, 'phonepe_pg');
        abort_unless($order->paymentAccount, 422, 'A matching payment account is required.');

        try {
            $response = $this->request(
                $order->paymentAccount,
                fn (PendingRequest $http, string $base) => $http->get(
                    "$base/checkout/v2/order/{$order->order_no}/status",
                    ['details' => 'false']
                )
            );
        } catch (Throwable $e) {
            return VerificationResult::pending(['error' => $e->getMessage()]);
        }

        $data = $response->json() ?? [];

        if (($data['state'] ?? null) !== 'COMPLETED') {
            return VerificationResult::pending($data);
        }

        // Verify the merchant order ID in the response matches our order.
        // PhonePe v2 status response returns `merchantOrderId` for our order number;
        // `orderId` is PhonePe's internal ID and must not be used for this check.
        if (($data['merchantOrderId'] ?? null) !== $order->order_no) {
            Log::warning('PhonePe order ID mismatch', [
                'order_id' => $order->order_no,
                'response_id' => $data['merchantOrderId'] ?? null,
            ]);

            return VerificationResult::pending(['error' => 'Order ID mismatch']);
        }

        if (! ctype_digit((string) ($data['amount'] ?? ''))
            || (int) $data['amount'] !== $this->toPaise($order->amount)) {
            Log::warning('PhonePe amount mismatch', [
                'order_id' => $order->order_no,
                'expected_paise' => $this->toPaise($order->amount),
                'got_paise' => (int) ($data['amount'] ?? 0),
                'state' => $data['state'] ?? null,
                // Omit full response — may contain PII or customer payment details.
            ]);

            return VerificationResult::pending(['error' => 'Amount mismatch']);
        }

        $payment = collect($data['paymentDetails'] ?? [])->firstWhere('state', 'COMPLETED') ?? [];

        if (($data['currency'] ?? 'INR') !== 'INR') {
            return VerificationResult::pending(['error' => 'Currency mismatch']);
        }
        if (trim((string) ($payment['rail']['utr'] ?? '')) === ''
            && trim((string) ($payment['transactionId'] ?? ($data['orderId'] ?? ''))) === '') {
            return VerificationResult::pending(['error' => 'Missing transaction reference']);
        }

        $occurredAt = VerificationResult::extractRawTimestamp('phonepe_pg', $data);
        if ($occurredAt === null && (bool) config('gateway.evidence_require_timestamp', true)) {
            Log::warning('PhonePe PG transaction missing or invalid timestamp', [
                'order_id' => $order->order_no,
            ]);

            return VerificationResult::pending(['error' => 'Missing or invalid provider transaction timestamp']);
        }

        return VerificationResult::fromPhonePePg(
            order: $order,
            amount: ((int) $data['amount']) / 100,
            utr: $payment['rail']['utr'] ?? null,
            txnId: $payment['transactionId'] ?? ($data['orderId'] ?? null),
            raw: $data,
            currency: $data['currency'] ?? 'INR',
            occurredAt: $occurredAt,
        );
    }

    /**
     * Run an authenticated call; on 401 refresh the cached token and retry once.
     *
     * @param  callable(PendingRequest, string): Response  $call
     */
    private function request(PaymentAccount $account, callable $call): Response
    {
        $urls = config('gateway.phonepe_pg.'.$this->env($account));

        foreach ([false, true] as $refresh) {
            $http = Http::timeout(15)->acceptJson()->asJson()
                ->withHeaders(['Authorization' => 'O-Bearer '.$this->token($account, $urls['token_url'], $refresh)]);
            $response = $call($http, $urls['base_url']);

            if ($response->status() !== 401) {
                return $response;
            }
        }

        return $response;
    }

    private function token(PaymentAccount $account, string $tokenUrl, bool $refresh): string
    {
        $cacheKey = 'phonepe_token:'.$account->id.':'.md5(
            $account->credential('client_id').$account->credential('client_secret')
        );

        if (! $refresh && ($token = Cache::get($cacheKey))) {
            return $token;
        }

        $response = Http::timeout(15)->asForm()->acceptJson()->post($tokenUrl, [
            'client_id' => $account->credential('client_id'),
            'client_version' => $account->credential('client_version'),
            'client_secret' => $account->credential('client_secret'),
            'grant_type' => 'client_credentials',
        ]);

        $token = $response->json('access_token');

        if (! $response->successful() || ! $token) {
            // Do NOT log client_secret — it's a credential.
            throw new RuntimeException(
                'PhonePe login failed. Check Client ID, Client Secret, Client Version and environment.'
            );
        }

        $ttl = max(60, (int) $response->json('expires_at', time() + 900) - time() - 60);
        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }

    private function env(PaymentAccount $account): string
    {
        return $account->credential('environment') === 'production' ? 'production' : 'sandbox';
    }

    private function toPaise(string|float|int $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
