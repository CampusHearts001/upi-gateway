<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CheckoutController extends Controller
{
    public function __construct(private OrderService $orders) {}

    public function show(Order $order)
    {
        $this->assertLive($order);
        if (($order->isPending() && ($order->expires_at === null || $order->isPastExpiry()))
            || $order->canRecoverLatePayment()) {
            $order = $this->orders->check($order);
        }

        return view('pay', ['order' => $order->load('paymentAccount', 'user')]);
    }

    /**
     * Polled by the checkout page every few seconds.
     */
    public function status(Order $order)
    {
        $this->assertLive($order);
        $order = $this->orders->check($order);

        return response()->json([
            'status' => $order->status,
            'redirect' => in_array($order->status, [Order::PAID, Order::EXPIRED])
                ? $this->redirectUrl($order)
                : null,
        ]);
    }

    /**
     * Customer submits the UTR after paying to a manual-verification UPI ID.
     */
    public function submitUtr(Request $request, Order $order)
    {
        $this->assertLive($order);
        $data = $request->validate([
            'utr' => ['required', 'string', 'regex:/^\s*\d{12}\s*$/'],
        ], ['utr.regex' => 'UTR must be the 12-digit number shown in your UPI app after payment.']);

        $this->orders->submitCustomerUtr($order->load('paymentAccount'), $data['utr']);

        return redirect()->route('pay.show', $order);
    }

    /**
     * Send the customer to the PG's hosted checkout (PhonePe PG / Paytm PG).
     */
    public function start(Order $order)
    {
        $this->assertLive($order);
        $order->load('paymentAccount');
        abort_unless($order->paymentAccount->isHosted(), 404);

        if ($order->isPending() && $order->expires_at === null) {
            $order = $this->orders->check($order);
        }

        if (! $order->isPending() || $order->isPastExpiry()) {
            return redirect()->route('pay.show', $order);
        }

        try {
            $checkout = DB::transaction(function () use ($order) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $account = $locked->paymentAccount()->lockForUpdate()->firstOrFail();
                $account->assertCompatibleWithOrder($locked);
                abort_unless($locked->environment === Order::LIVE, 404);
                $locked->setRelation('paymentAccount', $account);

                if (! $locked->isPending() || $locked->expires_at === null || $locked->isPastExpiry()) {
                    return null;
                }

                $checkout = $locked->provider_checkout;
                if (! $checkout || ($checkout['expires_at'] ?? 0) <= now()->timestamp + 30
                    || ($checkout['payment_account_id'] ?? null) !== $account->id
                    || ($checkout['payment_environment'] ?? null) !== Order::LIVE
                    || ($checkout['provider'] ?? null) !== $account->provider) {
                    $verifier = $locked->paymentAccount->verifier();
                    $checkout = $verifier->startCheckout($locked, route('pay.return', $locked));
                    $this->validateCheckout($checkout);
                    $checkout['payment_account_id'] = $account->id;
                    $checkout['payment_environment'] = Order::LIVE;
                    $checkout['provider'] = $account->provider;
                    $locked->forceFill(['provider_checkout' => $checkout])->save();
                }

                return $checkout;
            });
        } catch (RuntimeException $e) {
            return redirect()->route('pay.show', $order)
                ->withErrors(['checkout' => $e->getMessage()]);
        }

        if ($checkout === null) {
            return redirect()->route('pay.show', $order);
        }

        $this->validateCheckout($checkout);

        return $checkout['type'] === 'redirect'
            ? redirect()->away($checkout['url'])
            : view('pay-redirect', ['order' => $order, 'checkout' => $checkout]);
    }

    private function validateCheckout(array $checkout): void
    {
        if (! in_array($checkout['type'] ?? null, ['redirect', 'form'], true)
            || ! isset($checkout['url'])
            || ! filter_var($checkout['url'], FILTER_VALIDATE_URL)
            || strtolower((string) parse_url($checkout['url'], PHP_URL_SCHEME)) !== 'https') {
            throw new RuntimeException('The payment provider returned an invalid checkout URL. Please try again.');
        }

        if (($checkout['type'] ?? null) === 'form' && ! is_array($checkout['fields'] ?? null)) {
            throw new RuntimeException('The payment provider returned an invalid checkout response. Please try again.');
        }
    }

    /**
     * The PG sends the customer back here. We ignore whatever the PG posted
     * and ask its Status API instead.
     */
    public function return(Order $order)
    {
        $this->assertLive($order);
        $order = $this->orders->check($order->load('paymentAccount'), force: true);

        if ($order->status === Order::PAID && $order->redirect_url) {
            $redirectUrl = $this->redirectUrl($order);
            if ($redirectUrl !== null) {
                return redirect()->away($redirectUrl);
            }
        }

        return redirect()->route('pay.show', $order);
    }

    public function qr(Order $order)
    {
        $this->assertLive($order);
        abort_unless($order->isPending() && ! $order->paymentAccount->isHosted(), 404);

        $writer = new Writer(
            new ImageRenderer(new RendererStyle(320, 1), new SvgImageBackEnd)
        );

        return response($writer->writeString($order->upiIntentUrl()), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, max-age=600',
        ]);
    }

    /**
     * Build the redirect URL — re-validates the host allowlist at redirect time
     * so that a domain that was removed after order creation is no longer honoured.
     */
    private function redirectUrl(Order $order): ?string
    {
        if (! $order->redirect_url) {
            return null;
        }

        // Re-check at redirect time: merchant may have updated their allowlist.
        $merchant = $order->user ?? $order->load('user')->user;
        if (! $merchant->isRedirectUrlAllowed($order->redirect_url)) {
            return null;
        }

        // Only allow https unless explicitly configured otherwise.
        $scheme = strtolower(parse_url($order->redirect_url, PHP_URL_SCHEME) ?? '');
        if ($scheme === 'http' && ! config('gateway.allow_insecure_redirects', false)) {
            return null;
        }

        $separator = str_contains($order->redirect_url, '?') ? '&' : '?';

        return $order->redirect_url.$separator.http_build_query([
            'order_id' => $order->order_no,
            'client_order_id' => $order->client_order_id,
            'status' => $order->status,
        ]);
    }

    private function assertLive(Order $order): void
    {
        abort_unless($order->environment === Order::LIVE, 404);
        $order->load('paymentAccount');
        abort_unless($order->paymentAccount, 422, 'A matching payment account is required.');
        $order->paymentAccount->assertCompatibleWithOrder($order);
    }
}
