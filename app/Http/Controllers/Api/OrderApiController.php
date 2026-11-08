<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderApiController extends Controller
{
    public function __construct(private OrderService $orders) {}

    public function store(Request $request)
    {
        $merchant = $request->user();
        $environment = $request->attributes->get('payment_environment', Order::LIVE);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:100000', 'decimal:0,2'],
            'client_order_id' => [
                'required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/',
                Rule::unique('orders')->where(fn ($query) => $query
                    ->where('user_id', $merchant->id)
                    ->where('environment', $environment)),
            ],
            'payment_account_id' => [Rule::prohibitedIf($environment === Order::SANDBOX), 'nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:100'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_mobile' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:50'],
            'redirect_url' => ['nullable', 'url:https', 'max:500'],
        ]);

        // Validate redirect_url against the merchant's domain allowlist.
        if (! empty($data['redirect_url'])) {
            $this->validateRedirectUrl($merchant, $data['redirect_url']);
        }

        $order = $this->orders->create($merchant, $data, $environment);

        return response()->json(['success' => true, 'data' => $order->toApiArray()], 201);
    }

    /**
     * Accepts our order ID or the merchant's client_order_id.
     * Prioritizes exact order_no match before falling back to client_order_id.
     */
    public function show(Request $request, string $orderNo)
    {
        $environment = $request->attributes->get('payment_environment', Order::LIVE);
        $baseQuery = $request->user()->orders()->where('environment', $environment);

        $order = (clone $baseQuery)->where('order_no', $orderNo)->first()
            ?? (clone $baseQuery)->where('client_order_id', $orderNo)->firstOrFail();

        $order = $this->orders->check($order);

        return response()->json(['success' => true, 'data' => $order->toApiArray()]);
    }

    /**
     * Validate that the redirect URL's host is in the merchant's allowlist.
     *
     * @throws ValidationException
     */
    private function validateRedirectUrl(User $merchant, string $url): void
    {
        $allowed = $merchant->allowedRedirectDomains();

        if (empty($allowed)) {
            throw ValidationException::withMessages([
                'redirect_url' => 'Redirect URLs are not enabled for your account. Add allowed domains in Settings.',
            ]);
        }

        if (! $merchant->isRedirectUrlAllowed($url)) {
            throw ValidationException::withMessages([
                'redirect_url' => 'The redirect URL domain is not in your allowed domains list.',
            ]);
        }
    }
}
