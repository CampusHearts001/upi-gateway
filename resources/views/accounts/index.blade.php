@extends('layouts.app')
@section('title', 'UPI Accounts')
@section('subtitle', 'Payments go directly to these accounts. We never hold your money.')
@section('actions')
    <a href="{{ route('accounts.create') }}" class="btn btn-primary"><x-icon name="plus" class="w-4 h-4" /> Add UPI account</a>
@endsection

@section('content')
@if ($accounts->isEmpty())
    <div class="card">
        <x-empty icon="qr" title="No payment accounts yet" text="Connect Paytm for Business, PhonePe PG or Paytm PG for automatic verification, or add any UPI ID.">
            <a href="{{ route('accounts.create') }}" class="btn btn-primary"><x-icon name="plus" class="w-4 h-4" /> Add UPI account</a>
        </x-empty>
    </div>
@else
@php
    $activeCount = $accounts->where('is_active', true)->count();
    $totalLeft = $accounts->where('is_active', true)->sum(fn ($a) => $a->remainingToday() ?? INF);
@endphp
<form method="POST" action="{{ route('settings.rotation') }}" class="card p-5 mb-6 flex flex-col lg:flex-row lg:items-center gap-5">
    @csrf
    <div class="flex items-start gap-4 flex-1">
        <div class="grid place-items-center w-11 h-11 rounded-xl bg-brand-50 text-brand-600 shrink-0"><x-icon name="refresh" class="w-6 h-6" /></div>
        <div>
            <div class="font-semibold text-slate-900">Account rotation</div>
            <p class="text-sm text-slate-500 mt-0.5">How new orders pick a UPI account when the API doesn't send <span class="kbd">payment_account_id</span>. Accounts that reached their daily limit are skipped automatically.</p>
            <p class="text-xs text-slate-500 mt-2">{{ $activeCount }} active {{ Str::plural('account', $activeCount) }} · capacity left today: <b class="text-slate-700">{{ is_infinite($totalLeft) ? 'Unlimited' : '₹'.number_format($totalLeft, 2) }}</b></p>
            <div class="mt-3 rounded-lg bg-slate-50 p-3 ring-1 ring-slate-200/80 text-xs text-slate-600 space-y-1">
                <span class="font-semibold text-slate-800">Rotation Acceptable-Use Advisory:</span>
                <p>
                    Account rotation is intended for legitimate operational load distribution and redundancy across your registered business payment accounts. It must not be used to bypass bank-imposed transaction limits, receiving-bank velocity limits, payment rejection, or operational account restrictions under individual bank terms of service. Each configured account must independently comply with its bank or provider conditions.
                </p>
            </div>
        </div>
    </div>
    <div class="flex gap-2 shrink-0">
        @foreach (\App\Models\User::ROTATION_MODES as $mode => $label)
            <label class="cursor-pointer">
                <input type="radio" name="rotation_mode" value="{{ $mode }}" class="peer sr-only" @checked($rotation === $mode) data-submit-on-change>
                <span class="btn btn-secondary peer-checked:bg-brand-600 peer-checked:text-white peer-checked:ring-brand-600">{{ $label }}</span>
            </label>
        @endforeach
    </div>
</form>

<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
    @foreach ($accounts as $account)
        @php
            $auto = $account->provider !== 'manual';
            $icon = ['paytm_business' => 'bolt', 'phonepe_pg' => 'shield', 'paytm_pg' => 'shield'][$account->provider] ?? 'qr';
        @endphp
        <div class="card flex flex-col {{ $account->is_active ? '' : 'opacity-70' }}">
            <div class="p-5 flex-1">
                <div class="flex items-start justify-between gap-3">
                    <div class="grid place-items-center w-11 h-11 rounded-xl {{ $auto ? 'bg-sky-50 text-sky-600' : 'bg-slate-100 text-slate-600' }}">
                        <x-icon :name="$icon" class="w-6 h-6" />
                    </div>
                    <div class="flex flex-wrap justify-end gap-1.5">
                        @if ($account->is_default)
                            <span class="inline-flex items-center gap-1 rounded-full bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-700 ring-1 ring-inset ring-brand-600/20"><x-icon name="star" class="w-3 h-3" /> Default</span>
                        @endif
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $account->is_active ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-slate-100 text-slate-600 ring-slate-500/20' }}">
                            <span class="w-1.5 h-1.5 rounded-full {{ $account->is_active ? 'bg-emerald-500' : 'bg-slate-400' }}"></span>{{ $account->is_active ? 'Active' : 'Disabled' }}
                        </span>
                    </div>
                </div>
                <div class="mt-4 flex items-center gap-2">
                    <span class="font-semibold text-slate-900">{{ $account->label }}</span>
                    <span class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-500" title="payment_account_id">ID {{ $account->id }}</span>
                </div>
                <div class="mt-1 flex items-center gap-2">
                    @if ($account->upi_id)
                        <span class="font-mono text-sm text-slate-700 truncate">{{ $account->upi_id }}</span>
                        <button type="button" data-copy="{{ $account->upi_id }}" class="text-slate-400 hover:text-slate-700" title="Copy UPI ID"><x-icon name="copy" class="w-4 h-4" /></button>
                    @else
                        <span class="text-sm text-slate-700">{{ $account->providerLabel() }}</span>
                        <span @class(['rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide', 'bg-emerald-50 text-emerald-700' => $account->environment() === 'production', 'bg-amber-50 text-amber-700' => $account->environment() === 'sandbox'])>{{ $account->environment() === 'production' ? 'Live' : 'Sandbox' }}</span>
                    @endif
                </div>
                <dl class="mt-4 grid grid-cols-2 gap-3 text-xs">
                    <div><dt class="text-slate-500">Payee name</dt><dd class="mt-0.5 font-medium text-slate-800 truncate">{{ $account->payee_name }}</dd></div>
                    <div><dt class="text-slate-500">Verification</dt><dd class="mt-0.5 font-medium {{ $auto ? 'text-sky-700' : 'text-slate-800' }}">{{ $account->isHosted() ? 'Official PG' : ($auto ? 'Automatic' : 'Manual (UTR)') }}</dd></div>
                    @if ($account->provider === 'phonepe_pg')
                        <div><dt class="text-slate-500">Client ID</dt><dd class="mt-0.5 font-mono text-slate-800 truncate">{{ Str::mask($account->credential('client_id') ?? '', '•', 4, -4) }}</dd></div>
                    @elseif ($account->credential('mid'))
                        <div><dt class="text-slate-500">Paytm MID</dt><dd class="mt-0.5 font-mono text-slate-800">{{ Str::mask($account->credential('mid') ?? '', '•', 4, -4) }}</dd></div>
                    @endif
                    <div><dt class="text-slate-500">Orders</dt><dd class="mt-0.5 font-medium text-slate-800">{{ number_format($account->orders_count) }}</dd></div>
                </dl>

                @php
                    $used = $account->usedToday();
                    $limit = $account->daily_limit === null ? null : (float) $account->daily_limit;
                    $pct = $limit ? min(100, $used / $limit * 100) : 0;
                    $bar = $pct >= 100 ? 'bg-red-500' : ($pct >= 80 ? 'bg-amber-500' : 'bg-emerald-500');
                @endphp
                <div class="mt-5 rounded-lg bg-slate-50 ring-1 ring-slate-200/70 p-3">
                    <div class="flex items-baseline justify-between text-xs">
                        <span class="text-slate-500">Used today</span>
                        <span class="font-medium text-slate-800 tabular-nums">₹{{ number_format($used, 2) }} <span class="text-slate-400">/ {{ $limit === null ? 'No limit' : '₹'.number_format($limit) }}</span></span>
                    </div>
                    @if ($limit !== null)
                        <div class="mt-2 h-1.5 rounded-full bg-slate-200 overflow-hidden"><div class="h-full rounded-full {{ $bar }}" style="width: {{ $pct }}%"></div></div>
                        @if ($pct >= 100)<div class="mt-1.5 text-[11px] font-medium text-red-600">Limit reached — skipped until tomorrow</div>@endif
                    @endif
                    <form method="POST" action="{{ route('accounts.limit', $account) }}" class="mt-3 flex gap-2">
                        @csrf
                        <div class="relative flex-1">
                            <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-xs text-slate-400">₹</span>
                            <input type="number" name="daily_limit" min="1" step="1" value="{{ $limit === null ? '' : (int) $limit }}" placeholder="No limit" class="input py-1.5 pl-6 text-xs" aria-label="Daily limit">
                        </div>
                        <button class="btn btn-secondary btn-sm">Set limit</button>
                    </form>
                </div>
            </div>
            <div class="flex items-center gap-2 border-t border-slate-100 px-5 py-3 bg-slate-50/60 rounded-b-xl">
                @unless ($account->is_default)
                    <form method="POST" action="{{ route('accounts.default', $account) }}">@csrf<button class="btn btn-ghost btn-sm">Make default</button></form>
                @endunless
                <form method="POST" action="{{ route('accounts.toggle', $account) }}">@csrf
                    <button class="btn btn-ghost btn-sm"><x-icon name="power" class="w-4 h-4" /> {{ $account->is_active ? 'Disable' : 'Enable' }}</button>
                </form>
                @if ($account->orders_count === 0)
                    <form method="POST" action="{{ route('accounts.destroy', $account) }}" class="ml-auto">@csrf @method('DELETE')
                        <button data-confirm="Delete this UPI account?" class="btn btn-ghost btn-sm text-red-600 hover:bg-red-50 hover:text-red-700" title="Delete"><x-icon name="trash" class="w-4 h-4" /></button>
                    </form>
                @endif
            </div>
        </div>
    @endforeach
</div>
@endif
@endsection
