<?php

namespace App\Services\Verifiers;

use App\Models\Order;
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

        $mid = $order->paymentAccount->credential('mid');
        if (! $mid) {
            return VerificationResult::pending(['error' => 'Paytm MID not configured']);
        }

        try {
            $response = Http::timeout(10)->acceptJson()->get(config('gateway.paytm_status_url'), [
                'JsonData' => json_encode(['MID' => $mid, 'ORDERID' => $order->order_no]),
            ]);
            $data = $response->json() ?? [];
        } catch (Throwable $e) {
            Log::warning('Paytm status check failed', [
                'order_id' => $order->order_no,
                'error' => $e->getMessage(),
                // Never log $mid, credentials, or full response here.
            ]);

            return VerificationResult::pending(['error' => $e->getMessage()]);
        }

        if (($data['STATUS'] ?? null) !== 'TXN_SUCCESS') {
            return VerificationResult::pending($data);
        }

        // Verify the order ID in the response matches our order to prevent
        // a response for a different order being accepted.
        if (($data['ORDERID'] ?? null) !== $order->order_no) {
            Log::warning('Paytm Business order ID mismatch', [
                'order_id' => $order->order_no,
                'response_id' => $data['ORDERID'] ?? null,
            ]);

            return VerificationResult::pending(['error' => 'Order ID mismatch']);
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

            return VerificationResult::pending(['error' => 'Amount mismatch']);
        }

        if (($data['CURRENCY'] ?? 'INR') !== 'INR') {
            return VerificationResult::pending(['error' => 'Currency mismatch']);
        }
        if (trim((string) ($data['BANKTXNID'] ?? '')) === '' && trim((string) ($data['TXNID'] ?? '')) === '') {
            return VerificationResult::pending(['error' => 'Missing transaction reference']);
        }

        $occurredAt = VerificationResult::extractRawTimestamp('paytm_business', $data);
        if ($occurredAt === null && (bool) config('gateway.evidence_require_timestamp', true)) {
            Log::warning('Paytm Business transaction missing or invalid timestamp', [
                'order_id' => $order->order_no,
            ]);

            return VerificationResult::pending(['error' => 'Missing or invalid provider transaction timestamp']);
        }

        return VerificationResult::fromPaytmBusiness(
            order: $order,
            amount: $data['TXNAMOUNT'],
            utr: $data['BANKTXNID'] ?? null,
            txnId: $data['TXNID'] ?? null,
            raw: $data,
            currency: $data['CURRENCY'] ?? 'INR',
            occurredAt: $occurredAt,
        );
    }

    private function toPaise(string|float|int $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
