@extends('layouts.app')
@section('title', '₹'.number_format($order->amount, 2))
@section('back', auth()->user()->isAdmin() && $order->user_id !== auth()->id() ? route('admin.orders') : route('orders.index'))
@section('actions')
    <button type="button" data-copy="{{ $order->paymentUrl() }}" class="btn btn-secondary"><x-icon name="copy" class="w-4 h-4" /><span data-copy-label>Copy link</span></button>
    <a href="{{ $order->paymentUrl() }}" target="_blank" class="btn btn-secondary"><x-icon name="external" class="w-4 h-4" /> Open checkout</a>
@endsection

@php
    $details = [
        'Order ID' => [$order->order_no, true],
        'Your reference' => [$order->client_order_id, true],
        'Environment' => [strtoupper($order->environment).($order->environment === 'sandbox' ? ' · TEST ONLY' : ''), false],
        'Payment account' => [$order->paymentAccount ? $order->paymentAccount->label.' · '.($order->paymentAccount->upi_id ?? $order->paymentAccount->providerLabel()) : 'Internal TEST simulator', false],
        'Verification' => [$order->paymentAccount?->providerLabel() ?? 'Sandbox simulation', false],
        'UTR' => [$order->utr, true],
        'Customer-submitted UTR' => [$order->customer_utr ? ($order->status === 'review' ? '••••••••'.substr($order->customer_utr, -4) : $order->customer_utr).($order->utr_attempts > 1 ? " (attempt {$order->utr_attempts})" : '') : null, true],
        'Provider txn ID' => [$order->provider_txn_id, true],
        'Customer name' => [$order->customer_name, false],
        'Customer mobile' => [$order->customer_mobile, false],
        'Customer email' => [$order->customer_email, false],
        'Note' => [$order->note, false],
    ];
    $timeline = [['Order created', $order->created_at, 'plus', 'bg-slate-100 text-slate-600']];
    if ($order->utr_submitted_at) {
        $submittedUtrLabel = $order->status === 'review' ? '••••••••'.substr($order->customer_utr, -4) : $order->customer_utr;
        $timeline[] = ['Customer submitted UTR '.$submittedUtrLabel, $order->utr_submitted_at, 'receipt', 'bg-sky-100 text-sky-700'];
    }
    if ($order->review_note && $order->status !== 'paid') {
        $timeline[] = ['UTR rejected: '.$order->review_note, null, 'x', 'bg-red-100 text-red-700'];
    }
    if ($order->status === 'review') {
        $timeline[] = ['Waiting for your review', null, 'shield', 'bg-sky-100 text-sky-700'];
    } elseif ($order->status === 'paid') {
        $timeline[] = ['Payment received'.match ($order->verified_by) { 'manual' => ' (marked manually)', 'utr_review' => ' (UTR matched)', 'sandbox' => ' (TEST simulation)', default => ' (auto verified)' }, $order->paid_at, 'check', 'bg-emerald-100 text-emerald-700'];
    } elseif ($order->status === 'failed') {
        $timeline[] = ['TEST payment failed', $order->sandbox_simulated_at, 'x', 'bg-red-100 text-red-700'];
    } elseif ($order->status === 'expired') {
        $timeline[] = ['Link expired without payment', $order->expires_at, 'clock', 'bg-slate-100 text-slate-500'];
    } else {
        $timeline[] = ['Waiting for payment · '.($order->expires_at ? 'expires '.$order->expires_at->diffForHumans() : 'expiry not recorded'), null, 'clock', 'bg-amber-100 text-amber-700'];
    }
@endphp

@section('subtitle')
    <span class="inline-flex items-center gap-2"><x-status :status="$order->status" /> <span class="font-mono">{{ $order->order_no }}</span></span>
@endsection

@section('content')
@if ($order->environment === 'sandbox')
    <div class="mb-6 rounded-xl bg-amber-100 px-5 py-4 text-sm font-semibold text-amber-900 ring-1 ring-amber-300">SANDBOX · TEST TRANSACTION · This order never contacts a real payment provider.</div>
@endif
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <div class="card">
            <div class="card-header"><div class="card-title">Payment details</div></div>
            <dl class="grid grid-cols-1 sm:grid-cols-2">
                @foreach ($details as $label => [$value, $mono])
                    <div class="px-5 py-3.5 border-b border-slate-100 {{ $loop->odd ? 'sm:border-r' : '' }}">
                        <dt class="text-xs font-medium text-slate-500">{{ $label }}</dt>
                        <dd class="mt-1 text-sm text-slate-900 break-all {{ $mono && $value ? 'font-mono' : '' }}">{{ $value ?: '—' }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        <div class="card overflow-hidden">
            <div class="card-header">
                <div class="card-title">Webhook deliveries</div>
                @php $webhookUrl = $order->environment === 'sandbox' ? $order->user->sandbox_webhook_url : $order->user->webhook_url; @endphp
                @if ($webhookUrl)<span class="text-xs text-slate-500 font-mono truncate max-w-xs">{{ $webhookUrl }}</span>@endif
            </div>
            @if ($order->webhookLogs->isEmpty())
                <x-empty icon="link" :title="$webhookUrl ? 'No webhooks sent yet' : 'No webhook URL set'"
                    :text="$webhookUrl ? 'Events are sent when the order status changes.' : 'Add the matching environment webhook URL to receive events.'">
                    @unless ($webhookUrl)<a href="{{ route('settings') }}" class="btn btn-secondary">Set webhook URL</a>@endunless
                </x-empty>
            @else
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100">
                    <thead class="bg-slate-50/70"><tr><th class="th">Event</th><th class="th">Attempt</th><th class="th">Response</th><th class="th">Time</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    @foreach ($order->webhookLogs->sortByDesc('id') as $log)
                        <tr>
                            <td class="td font-mono text-xs">{{ $log->event }}</td>
                            <td class="td text-slate-600">#{{ $log->attempt }}</td>
                            <td class="td">
                                <span class="inline-flex items-center gap-1.5 text-xs font-medium {{ $log->success ? 'text-emerald-700' : 'text-red-700' }}">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $log->success ? 'bg-emerald-500' : 'bg-red-500' }}"></span>{{ $log->response_code ?? 'No response' }}
                                </span>
                                @if($log->error_label)<div class="text-xs text-slate-400 font-mono">{{ $log->error_label }}</div>@endif
                            </td>
                            <td class="td text-slate-500 whitespace-nowrap">{{ $log->created_at->format('d M, h:i:s A') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </div>
    </div>

    <div class="space-y-6">
        <div class="card">
            <div class="card-header"><div class="card-title">Timeline</div></div>
            <ol class="p-5 space-y-5">
                @foreach ($timeline as [$label, $time, $icon, $tone])
                    <li class="relative flex gap-3">
                        @unless ($loop->last)<span class="absolute left-4 top-8 -bottom-5 w-px bg-slate-200"></span>@endunless
                        <span class="grid place-items-center w-8 h-8 rounded-full {{ $tone }} shrink-0"><x-icon :name="$icon" class="w-4 h-4" /></span>
                        <div class="pt-1">
                            <div class="text-sm font-medium text-slate-900">{{ $label }}</div>
                            @if ($time)<div class="text-xs text-slate-500 mt-0.5">{{ $time->format('d M Y, h:i:s A') }}</div>@endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </div>

        @if (! $readOnly && $order->status === 'review')
            <div class="card ring-2 ring-sky-400">
                <div class="card-header bg-sky-50/60 rounded-t-xl">
                    <div class="card-title flex items-center gap-2"><x-icon name="shield" class="w-5 h-5 text-sky-600" /> Verify customer's payment</div>
                </div>
                <div class="p-5">
                    <div class="rounded-lg bg-slate-50 ring-1 ring-slate-200 p-4">
                        <div class="text-xs text-slate-500">Customer says they paid</div>
                        <div class="mt-1 text-2xl font-bold text-slate-900 tabular-nums">₹{{ number_format($order->amount, 2) }}</div>
                        <div class="mt-3 text-xs text-slate-500">with UTR ending in</div>
                        <div class="mt-1 font-mono text-lg font-semibold tracking-wider text-slate-900">••••••••{{ substr($order->customer_utr, -4) }}</div>
                        <div class="mt-3 text-xs text-slate-500">to <span class="font-mono">{{ $order->paymentAccount->upi_id }}</span> · {{ $order->utr_submitted_at?->diffForHumans() }}</div>
                    </div>

                    <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
                        <div class="flex items-start gap-2.5">
                            <x-icon name="alert" class="mt-0.5 w-5 h-5 shrink-0 text-amber-600" />
                            <div>
                                <span class="font-bold">FRAUD WARNING: Independent Bank Verification Required</span>
                                <p class="mt-1 text-xs text-amber-900 leading-relaxed">
                                    Never treat customer-submitted references as proof of payment. Fraudulent customers frequently submit fake, guessed, or duplicate UTRs. You MUST independently open your official bank or UPI app, verify that ₹{{ number_format($order->amount, 2) }} was genuinely credited, and enter the bank statement details below.
                                </p>
                            </div>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('orders.approve-utr', $order) }}" class="mt-5 space-y-4">
                        @csrf
                        <div>
                            <label for="bank_utr" class="block text-xs font-bold uppercase tracking-wider text-slate-700">Bank-Verified 12-Digit UTR</label>
                            <input type="text" id="bank_utr" name="bank_utr" maxlength="14" inputmode="numeric" required placeholder="Enter full 12-digit UTR from your bank statement" class="input mt-1 font-mono tracking-wider w-full" value="{{ old('bank_utr') }}">
                            @error('bank_utr')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="received_amount" class="block text-xs font-bold uppercase tracking-wider text-slate-700">Actual Amount Credited (₹)</label>
                            <input type="number" id="received_amount" name="received_amount" step="0.01" min="0.01" required placeholder="Exact amount from bank statement" class="input mt-1 tabular-nums w-full" value="{{ old('received_amount') }}">
                            @error('received_amount')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <button class="btn btn-primary w-full" data-confirm="Confirm that you independently verified this payment in your bank statement?">
                            <x-icon name="check" class="w-4 h-4" /> Verify from Bank & Approve Payment
                        </button>
                    </form>

                    <form method="POST" action="{{ route('orders.reject-utr', $order) }}" class="mt-5 pt-5 border-t border-slate-100">
                        @csrf
                        <div class="text-sm font-semibold text-slate-900">Payment not found in bank?</div>
                        <p class="text-xs text-slate-500 mt-1 mb-3">
                            @if ($order->utr_attempts < \App\Models\Order::MAX_UTR_ATTEMPTS)
                                The customer will be asked to check and resubmit ({{ \App\Models\Order::MAX_UTR_ATTEMPTS - $order->utr_attempts }} {{ Str::plural('attempt', \App\Models\Order::MAX_UTR_ATTEMPTS - $order->utr_attempts) }} left).
                            @else
                                This was the customer's last attempt; rejecting will expire the order.
                            @endif
                        </p>
                        <input name="reason" maxlength="150" placeholder="Reason shown to customer (optional)" class="input mb-3">
                        <button data-confirm="Reject this UTR?" class="btn btn-danger w-full"><x-icon name="x" class="w-4 h-4" /> Reject UTR</button>
                    </form>
                </div>
            </div>
        @elseif (! $readOnly && $order->status !== 'paid')
            <div class="card">
                <div class="card-header"><div class="card-title">Actions</div></div>
                <div class="p-5 space-y-5">
                    <form method="POST" action="{{ route('orders.recheck', $order) }}">
                        @csrf
                        <button class="btn btn-secondary w-full"><x-icon name="refresh" class="w-4 h-4" /> Re-check status with provider</button>
                    </form>

                    <div class="border-t border-slate-100 pt-5 text-sm text-slate-600">
                        Manual payment confirmation is disabled for merchants. Use the provider re-check above; administrator overrides require separate audited authorization.
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
