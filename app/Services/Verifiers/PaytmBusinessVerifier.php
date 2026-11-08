<?php

namespace App\Services\Verifiers;

use App\Models\Order;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Checks a Paytm for Business merchant's transaction by our order number
 * (passed as `tr` in the UPI link). Uses Paytm's unofficial status endpoint,
 * so any error is treated as "still pending" rather than a failure.
 */
class PaytmBusinessVerifier implements Verifier
{
    public function check(Order $order): VerificationResult
    {
        $order->load('paymentAccount');
        $order->paymentAccount?->assertLiveProviderOrder($order, 'paytm_business');
        abort_unless($order->paymentAccount, 422, 'A matching payment account is required.');

        if (! (bool) config('gateway.enable_paytm_business', true)) {
            Log::info('Paytm Business verification skipped: provider disabled by policy', [
                'order_id' => $order->order_no,
            ]);

            return VerificationResult::pending([
                'error' => 'Paytm Business verification is disabled by platform policy',
                'code' => 'disabled',
            ]);
        }

        $mid = $order->paymentAccount->credential('mid');
        if (! $mid) {
            return VerificationResult::pending(['error' => 'Paytm MID not configured', 'code' => 'missing_mid']);
        }

        try {
            $response = Http::timeout(10)->acceptJson()->get(config('gateway.paytm_status_url'), [
                'JsonData' => json_encode(['MID' => $mid, 'ORDERID' => $order->order_no]),
            ]);
            $data = is_array($response->json()) ? $response->json() : [];
        } catch (Throwable $e) {
            $errorInfo = $this->categorizeException($e);

            Log::warning('Paytm status check failed', [
                'order_id' => $order->order_no,
                'error_code' => $errorInfo['code'],
                'message' => $errorInfo['message'],
                // Never log $mid, credentials, raw exception message, or full URL here.
            ]);

            return VerificationResult::pending([
                'error' => $errorInfo['message'],
                'code' => $errorInfo['code'],
            ]);
        }

        if (($data['STATUS'] ?? null) !== 'TXN_SUCCESS') {
            return VerificationResult::pending($this->sanitizeProviderData($data));
        }

        // Verify the order ID in the response matches our order to prevent
        // a response for a different order being accepted.
        if (($data['ORDERID'] ?? null) !== $order->order_no) {
            Log::warning('Paytm Business order ID mismatch', [
                'order_id' => $order->order_no,
            ]);

            return VerificationResult::pending([
                'error' => 'Order ID mismatch',
                'code' => 'order_id_mismatch',
            ]);
        }

        // Never trust status alone: the amount paid must match the order exactly.
        if (! is_numeric($data['TXNAMOUNT'] ?? null)
            || ! preg_match('/^\d+(?:\.\d{1,2})?$/', (string) $data['TXNAMOUNT'])
            || $this->toPaise($data['TXNAMOUNT']) !== $this->toPaise($order->amount)) {
            Log::warning('Paytm amount mismatch', [
                'order_id' => $order->order_no,
                'expected_paise' => $this->toPaise($order->amount),
                'got_paise' => is_numeric($data['TXNAMOUNT'] ?? null) ? $this->toPaise($data['TXNAMOUNT']) : null,
                'status' => $data['STATUS'] ?? null,
                // Omit full provider response — may contain PII or credentials.
            ]);

            return VerificationResult::pending([
                'error' => 'Amount mismatch',
                'code' => 'amount_mismatch',
            ]);
        }

        if (($data['CURRENCY'] ?? 'INR') !== 'INR') {
            return VerificationResult::pending([
                'error' => 'Currency mismatch',
                'code' => 'currency_mismatch',
            ]);
        }
        if (trim((string) ($data['BANKTXNID'] ?? '')) === '' && trim((string) ($data['TXNID'] ?? '')) === '') {
            return VerificationResult::pending([
                'error' => 'Missing transaction reference',
                'code' => 'missing_reference',
            ]);
        }

        $occurredAt = VerificationResult::extractRawTimestamp('paytm_business', $data);
        if ($occurredAt === null && (bool) config('gateway.evidence_require_timestamp', true)) {
            Log::warning('Paytm Business transaction missing or invalid timestamp', [
                'order_id' => $order->order_no,
            ]);

            return VerificationResult::pending([
                'error' => 'Missing or invalid provider transaction timestamp',
                'code' => 'invalid_timestamp',
            ]);
        }

        return VerificationResult::fromPaytmBusiness(
            order: $order,
            amount: $data['TXNAMOUNT'],
            utr: $data['BANKTXNID'] ?? null,
            txnId: $data['TXNID'] ?? null,
            raw: $this->sanitizeProviderData($data),
            currency: $data['CURRENCY'] ?? 'INR',
            occurredAt: $occurredAt,
        );
    }

    private function sanitizeProviderData(array $data): array
    {
        unset($data['MID'], $data['mid']);

        return $data;
    }

    private function categorizeException(Throwable $e): array
    {
        $message = strtolower($e->getMessage());

        if (str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'curl error 28')) {
            return [
                'code' => 'timeout',
                'message' => 'Paytm status check timed out.',
            ];
        }

        if (str_contains($message, 'connection refused')
            || str_contains($message, 'could not resolve host')
            || str_contains($message, 'curl error 7')
            || str_contains($message, 'curl error 6')) {
            return [
                'code' => 'connection_failed',
                'message' => 'Paytm status endpoint connection failed.',
            ];
        }

        if ($e instanceof ConnectionException) {
            return [
                'code' => 'connection_failed',
                'message' => 'Paytm status endpoint connection failed.',
            ];
        }

        if ($e instanceof RequestException) {
            return [
                'code' => 'http_error',
                'message' => 'Paytm status endpoint returned an HTTP error.',
            ];
        }

        return [
            'code' => 'verification_error',
            'message' => 'Paytm status check failed.',
        ];
    }

    private function toPaise(string|float|int $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
