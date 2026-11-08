<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Services\WebhookHistoryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class TransactionDebuggerController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100', 'regex:/^[\pL\pN@._\- ]+$/u'],
            'status' => ['nullable', Rule::in([Order::PENDING, Order::PAID, Order::REVIEW, Order::FAILED, Order::EXPIRED])],
            'provider' => ['nullable', Rule::in(array_merge(['sandbox'], array_keys(PaymentAccount::PROVIDERS)))],
            'environment' => ['nullable', Rule::in([Order::LIVE, Order::SANDBOX])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        $query = Order::query()->with(['user:id,name,email', 'paymentAccount:id,label,provider'])
            ->withCount('webhookLogs')->latest('id');

        $this->applyFilters($query, $filters);

        return view('admin.transactions.index', [
            'orders' => $query->paginate(25)->withQueryString(),
            'filters' => $filters,
            'providers' => PaymentAccount::PROVIDERS,
        ]);
    }

    public function show(Request $request, Order $order)
    {
        $order->load([
            'user:id,name,email',
            'paymentAccount:id,label,provider',
        ]);
        $order->setRelation('webhookLogs', app(WebhookHistoryService::class)->getAllLogsForOrder($order));

        AdminAuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'transaction_debugger_viewed',
            'subject_type' => 'order',
            'subject_id' => $order->id,
            'meta' => ['order_no' => $order->order_no, 'environment' => $order->environment],
            'ip' => $request->ip(),
        ]);

        return view('admin.transactions.show', [
            'order' => $order,
            'timeline' => $this->timeline($order),
            'providerError' => $this->providerError($order),
            'maskedEmail' => $this->maskEmail($order->customer_email),
            'maskedMobile' => $this->maskMobile($order->customer_mobile),
            'maskedName' => $this->maskName($order->customer_name),
        ]);
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if ($search = $filters['q'] ?? null) {
            $query->where(function (Builder $query) use ($search) {
                if (ctype_digit($search)) {
                    $query->orWhere($query->qualifyColumn('id'), (int) $search);
                }
                $query->orWhere('order_no', 'like', "%{$search}%")
                    ->orWhere('client_order_id', 'like', "%{$search}%")
                    ->orWhere('utr', 'like', "%{$search}%")
                    ->orWhere('customer_utr', 'like', "%{$search}%")
                    ->orWhere('provider_txn_id', 'like', "%{$search}%")
                    ->orWhereHas('user', fn (Builder $merchant) => $merchant
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
            });
        }

        $query->when($filters['status'] ?? null, fn (Builder $q, string $value) => $q->where('status', $value))
            ->when($filters['environment'] ?? null, fn (Builder $q, string $value) => $q->where('environment', $value))
            ->when($filters['provider'] ?? null, function (Builder $q, string $value) {
                $value === 'sandbox'
                    ? $q->where('environment', Order::SANDBOX)
                    : $q->where('environment', Order::LIVE)->whereHas('paymentAccount', fn (Builder $account) => $account->where('provider', $value));
            })
            ->when($filters['date_from'] ?? null, fn (Builder $q, string $value) => $q->where('created_at', '>=', Carbon::createFromFormat('Y-m-d', $value)->startOfDay()))
            ->when($filters['date_to'] ?? null, fn (Builder $q, string $value) => $q->where('created_at', '<=', Carbon::createFromFormat('Y-m-d', $value)->endOfDay()));
    }

    private function timeline(Order $order): array
    {
        $events = [['time' => $order->created_at, 'label' => 'Order created', 'detail' => null, 'icon' => 'plus']];

        if ($order->provider_checkout) {
            $events[] = ['time' => null, 'label' => 'Checkout initiated', 'detail' => 'Stored checkout session exists; its exact creation time was not recorded.', 'icon' => 'external'];
        }
        if ($order->provider_response) {
            $events[] = ['time' => null, 'label' => 'Provider response received', 'detail' => 'Response is stored but hidden because it may contain sensitive provider data.', 'icon' => 'code'];
        }
        if ($order->last_checked_at) {
            $events[] = ['time' => $order->last_checked_at, 'label' => 'Payment verification attempted', 'detail' => null, 'icon' => 'refresh'];
        }
        if ($order->utr_submitted_at) {
            $events[] = ['time' => $order->utr_submitted_at, 'label' => 'Customer submitted a UTR', 'detail' => null, 'icon' => 'receipt'];
        }
        if ($order->sandbox_simulated_at) {
            $events[] = ['time' => $order->sandbox_simulated_at, 'label' => 'Sandbox outcome simulated', 'detail' => null, 'icon' => 'bolt'];
        }
        foreach ($order->webhookLogs as $log) {
            $events[] = [
                'time' => $log->created_at,
                'label' => 'Webhook delivery attempted',
                'detail' => $log->event.' · attempt '.$log->attempt.' · '.($log->response_code ?? $log->error_label ?? 'no response'),
                'icon' => 'link',
            ];
        }

        $finalTime = $order->paid_at
            ?? ($order->status === Order::EXPIRED ? $order->expires_at : null)
            ?? $order->sandbox_simulated_at;
        $events[] = ['time' => $finalTime, 'label' => 'Current status: '.strtoupper($order->status), 'detail' => $order->verified_by ? 'Verified by '.$order->verified_by : null, 'icon' => $order->status === Order::PAID ? 'check-circle' : 'clock'];

        usort($events, fn (array $a, array $b) => $a['time'] && $b['time'] ? $a['time']->compare($b['time']) : ($a['time'] ? -1 : ($b['time'] ? 1 : 0)));

        return $events;
    }

    private function providerError(Order $order): ?string
    {
        $values = $order->provider_response ?? [];
        foreach (['error_message', 'error', 'message', 'responseMessage', 'respMsg', 'resultMsg'] as $key) {
            $value = data_get($values, $key) ?? data_get($values, "resultInfo.{$key}");
            if (is_string($value) && trim($value) !== '') {
                return $this->sanitizeError($value);
            }
        }

        return $order->review_note ? $this->sanitizeError($order->review_note) : null;
    }

    private function sanitizeError(string $value): string
    {
        return str($value)->stripTags()
            ->replaceMatches('/https?:\/\/\S+/i', '[redacted URL]')
            ->replaceMatches('/\b(?:token|secret|password|api[_ -]?key)\s*[:=]\s*\S+/i', '[redacted credential]')
            ->replaceMatches('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted email]')
            ->replaceMatches('/\b\d{10,16}\b/', '[redacted number]')
            ->limit(240)->toString();
    }

    private function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return $email ? '***' : null;
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).str_repeat('*', max(3, mb_strlen($local) - 1)).'@'.$domain;
    }

    private function maskMobile(?string $mobile): ?string
    {
        return $mobile ? str_repeat('*', max(0, strlen($mobile) - 4)).substr($mobile, -4) : null;
    }

    private function maskName(?string $name): ?string
    {
        return $name ? mb_substr($name, 0, 1).str_repeat('*', max(2, mb_strlen($name) - 1)) : null;
    }
}
