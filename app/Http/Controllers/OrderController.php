<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private OrderService $orders) {}

    public function index(Request $request)
    {
        $environment = $request->query('environment') === Order::SANDBOX ? Order::SANDBOX : Order::LIVE;
        $query = $request->user()->orders()->where('environment', $environment)->with('paymentAccount')->latest();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('q')) {
            $query->where(fn ($q) => $q->where('order_no', $search)
                ->orWhere('client_order_id', $search)
                ->orWhere('utr', $search));
        }

        $counts = $request->user()->orders()
            ->where('environment', $environment)
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('orders.index', [
            'orders' => $query->paginate(20)->withQueryString(),
            'counts' => $counts,
            'environment' => $environment,
        ]);
    }

    public function create(Request $request)
    {
        return view('orders.create', [
            'accounts' => $request->user()->paymentAccounts()->where('is_active', true)->get(),
        ]);
    }

    /**
     * Create a payment link by hand from the dashboard (no API needed).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:100000'],
            'payment_account_id' => ['nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:100'],
            'customer_mobile' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:50'],
        ]);

        $order = $this->orders->create($request->user(), $data);

        return redirect()->route('orders.show', $order)
            ->with('status', 'Payment link created. Share it with your customer.');
    }

    public function show(Request $request, Order $order)
    {
        // Admin can view any order (read-only); merchants see only their own.
        $this->authorizeView($request, $order);

        return view('orders.show', [
            'order' => $order->load('paymentAccount', 'webhookLogs'),
            'readOnly' => ($request->user()->isAdmin() && $order->user_id !== $request->user()->id)
                || $order->environment === Order::SANDBOX,
        ]);
    }

    public function recheck(Request $request, Order $order)
    {
        $this->authorizeOwnerOnly($request, $order);
        $order = $this->orders->check($order);

        return back()->with('status', "Status: {$order->status}");
    }

    /**
     * Manual confirmation after the merchant finds the UTR in their bank/UPI app.
     */
    public function markPaid(Request $request, Order $order)
    {
        $this->authorizeOwnerOnly($request, $order);

        return back()->withErrors(['utr' => 'Merchant-side manual payment confirmation is disabled. Use provider verification or administrator review.']);
    }

    public function approveUtr(Request $request, Order $order)
    {
        $this->authorizeOwnerOnly($request, $order);
        if ($request->has('bank_utr')) {
            $request->merge(['bank_utr' => preg_replace('/\s+/', '', (string) $request->input('bank_utr'))]);
        }
        $data = $request->validate([
            'bank_utr' => ['required', 'string', 'regex:/^\d{12}$/'],
            'received_amount' => ['required', 'numeric', 'min:0.01'],
        ], [
            'bank_utr.regex' => 'The bank-verified UTR must be the 12-digit number from your bank statement.',
        ]);

        $this->orders->approveCustomerUtr($order, $data['bank_utr'], (float) $data['received_amount']);

        return back()->with('status', 'Payment verified from bank statement and marked as paid.');
    }

    public function rejectUtr(Request $request, Order $order)
    {
        $this->authorizeOwnerOnly($request, $order);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:150']]);

        $order = $this->orders->rejectCustomerUtr($order, $data['reason'] ?? null);

        return back()->with('status', $order->status === Order::PENDING
            ? 'UTR rejected. The customer can check and submit again.'
            : 'UTR rejected. No attempts left, so the order is now expired.');
    }

    // ── Authorization helpers ─────────────────────────────────────────────────

    /**
     * View (read-only) access: merchant owner OR any admin.
     */
    private function authorizeView(Request $request, Order $order): void
    {
        abort_unless(
            $order->user_id === $request->user()->id || $request->user()->isAdmin(),
            404
        );
    }

    /**
     * Write access: merchant owner ONLY. Admins cannot mutate orders.
     */
    private function authorizeOwnerOnly(Request $request, Order $order): void
    {
        abort_unless($order->user_id === $request->user()->id, 404);
    }
}
