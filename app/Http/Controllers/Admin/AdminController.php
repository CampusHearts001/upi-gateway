<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Services\WebhookHistoryService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function manualPaymentOverride(Request $request, Order $order, OrderService $orders)
    {
        $this->authorize('manual-payment-override');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'verification_reference' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_\-]{6,80}$/'],
            'reason' => ['required', 'string', 'min:20', 'max:500'],
        ]);

        if (number_format((float) $data['amount'], 2, '.', '') !== number_format((float) $order->amount, 2, '.', '')) {
            throw ValidationException::withMessages(['amount' => 'The confirmed amount must match the order amount exactly.']);
        }

        $orders->adminOverridePaid($order, $request->user(), strtoupper($data['verification_reference']), $data['reason'], (string) $request->ip(), (string) $data['amount']);

        return back()->with('status', 'Administrator payment override recorded and audited.');
    }

    public function merchants()
    {
        $merchants = User::where('role', 'merchant')
            ->withCount([
                'orders' => fn ($q) => $q->where('environment', Order::LIVE),
                'orders as paid_orders_count' => fn ($q) => $q
                    ->where('environment', Order::LIVE)->where('status', Order::PAID),
            ])
            ->withSum(['orders as paid_amount' => fn ($q) => $q
                ->where('environment', Order::LIVE)->where('status', Order::PAID)], 'amount')
            ->latest()
            ->paginate(25);

        return view('admin.merchants', compact('merchants'));
    }

    public function toggleMerchant(Request $request, User $user)
    {
        abort_if($user->isAdmin(), 403);

        $user->update(['is_active' => ! $user->is_active]);

        // Audit log
        AdminAuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => $user->is_active ? 'merchant_enabled' : 'merchant_disabled',
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'meta' => ['email' => $user->email],
            'ip' => $request->ip(),
        ]);

        return back()->with('status', "{$user->displayName()} ".($user->is_active ? 'enabled.' : 'disabled.'));
    }

    public function orders(Request $request)
    {
        $query = Order::with('user', 'paymentAccount')->latest();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return view('admin.orders', ['orders' => $query->paginate(30)->withQueryString()]);
    }

    /**
     * Admin view of a specific merchant order — read-only.
     * Logs the access for audit trail.
     */
    public function showOrder(Request $request, Order $order)
    {
        AdminAuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'order_viewed',
            'subject_type' => 'order',
            'subject_id' => $order->id,
            'meta' => ['order_no' => $order->order_no, 'merchant_id' => $order->user_id],
            'ip' => $request->ip(),
        ]);

        $order->load('paymentAccount', 'user');
        $order->setRelation('webhookLogs', app(WebhookHistoryService::class)->getAllLogsForOrder($order));

        return view('orders.show', [
            'order' => $order,
            'readOnly' => true,
        ]);
    }
}
