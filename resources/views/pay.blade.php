@extends('layouts.base')
@section('title', 'Pay ₹'.number_format($order->amount, 2).' to '.$order->user->displayName())

@php
    $merchant = $order->user->displayName();
    $amount = number_format($order->amount, 2);
    $totalSeconds = max(1, $order->created_at->diffInSeconds($order->expires_at ?? now()->addMinutes(config('gateway.order_expiry_minutes', 15))));
    $apps = [
        ['GPay',    'tez://upi/pay',   'text-[#1a73e8]', 'images/upi/gpay.png'],
        ['PhonePe', 'phonepe://pay',   'text-[#5f259f]', 'images/upi/phonepe.png'],
        ['Paytm',   'paytmmp://pay',   'text-[#00baf2]', 'images/upi/paytm.png'],
        ['BHIM',    'upi://pay',       'text-[#f47920]', 'images/upi/bhim.png'],
    ];
@endphp

@push('head')
<meta name="robots" content="noindex">
<link rel="stylesheet" href="{{ asset('css/pay.css') }}">
@endpush

@section('body')
<div class="min-h-full bg-slate-100 flex flex-col">
    <div class="flex-1 flex items-center justify-center p-4 sm:p-8">
        <div class="w-full max-w-3xl bg-white rounded-2xl shadow-pop ring-1 ring-slate-200 overflow-hidden grid md:grid-cols-5">

            {{-- Summary --}}
            <div class="md:col-span-2 relative bg-slate-950 text-white p-6 sm:p-8 flex flex-col overflow-hidden">
                <div class="hero-gradient absolute inset-0 opacity-50"></div>
                <div class="relative flex items-center gap-3">
                    <x-avatar :name="$merchant" size="w-11 h-11 text-base" class="!bg-white/10 !text-white ring-1 ring-white/20" />
                    <div class="min-w-0">
                        <div class="text-xs text-slate-400">Paying to</div>
                        <div class="font-semibold truncate">{{ $merchant }}</div>
                    </div>
                </div>

                <div class="relative mt-8">
                    <div class="text-xs uppercase tracking-wider text-slate-400">Amount</div>
                    <div class="mt-1 text-4xl font-bold tracking-tight tabular-nums">₹{{ $amount }}</div>
                    @if ($order->note)<div class="mt-2 text-sm text-slate-300">{{ $order->note }}</div>@endif
                </div>

                <dl class="relative mt-8 space-y-3 text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-slate-400 whitespace-nowrap">Order ID</dt><dd class="font-mono text-xs text-slate-200 truncate">{{ $order->order_no }}</dd></div>
                    @if ($order->customer_name)
                        <div class="flex justify-between gap-4"><dt class="text-slate-400 whitespace-nowrap">Customer</dt><dd class="text-slate-200 truncate">{{ $order->customer_name }}</dd></div>
                    @endif
                    @if ($order->paymentAccount->upi_id)
                        <div class="flex justify-between gap-4"><dt class="text-slate-400 whitespace-nowrap">UPI ID</dt><dd class="font-mono text-xs text-slate-200 truncate">{{ $order->paymentAccount->upi_id }}</dd></div>
                    @endif
                </dl>

                @if ($order->isPending())
                    <div class="relative mt-8 md:mt-auto pt-8 flex items-center gap-4">
                        <div class="relative w-14 h-14">
                            <svg viewBox="0 0 36 36" class="w-14 h-14 -rotate-90">
                                <circle cx="18" cy="18" r="16" fill="none" stroke="rgb(255 255 255 / .12)" stroke-width="3" />
                                <circle id="ring" cx="18" cy="18" r="16" fill="none" stroke="#818cf8" stroke-width="3" stroke-linecap="round" stroke-dasharray="100.5" stroke-dashoffset="0" class="ring-transition" />
                            </svg>
                            <div id="timer" class="absolute inset-0 grid place-items-center font-mono text-xs font-medium">--:--</div>
                        </div>
                        <div class="text-sm">
                            <div class="font-medium">Complete payment in time</div>
                            <div class="text-xs text-slate-400">This QR code expires automatically.</div>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Action area --}}
            <div class="md:col-span-3 p-6 sm:p-8 flex flex-col">
                @if ($order->status === 'paid')
                    <div class="flex-1 flex flex-col items-center justify-center text-center py-8">
                        <div class="pop grid place-items-center w-20 h-20 rounded-full bg-emerald-500 shadow-lg shadow-emerald-500/30">
                            <svg viewBox="0 0 24 24" class="w-10 h-10" fill="none" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path class="draw" d="m5 12.5 4.5 4.5L19 7.5" /></svg>
                        </div>
                        <h1 class="mt-6 text-2xl font-bold tracking-tight text-slate-900">Payment successful</h1>
                        <p class="mt-2 text-slate-500">₹{{ $amount }} paid to {{ $merchant }}</p>
                        <dl class="mt-8 w-full max-w-xs rounded-xl bg-slate-50 ring-1 ring-slate-200 divide-y divide-slate-200 text-sm text-left">
                            @if ($order->utr)<div class="flex justify-between px-4 py-3"><dt class="text-slate-500">UTR</dt><dd class="font-mono font-medium text-slate-900">{{ $order->utr }}</dd></div>@endif
                            <div class="flex justify-between px-4 py-3"><dt class="text-slate-500">Paid at</dt><dd class="font-medium text-slate-900">{{ $order->paid_at?->format('d M Y, h:i A') }}</dd></div>
                        </dl>
                        <p class="mt-6 text-xs text-slate-400">You can safely close this page.</p>
                    </div>
                @elseif ($order->status === 'review')
                    <div class="flex-1 flex flex-col items-center justify-center text-center py-8">
                        <div class="relative grid place-items-center w-20 h-20 rounded-full bg-sky-50 text-sky-600">
                            <svg class="absolute inset-0 w-20 h-20 animate-spin [animation-duration:2.5s]" viewBox="0 0 80 80" fill="none"><circle cx="40" cy="40" r="38" stroke="currentColor" stroke-opacity=".15" stroke-width="3"/><path d="M78 40A38 38 0 0 0 40 2" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                            <x-icon name="shield" class="w-9 h-9" />
                        </div>
                        <h1 class="mt-6 text-2xl font-bold tracking-tight text-slate-900">Verifying your payment</h1>
                        <p class="mt-2 text-sm text-slate-500 max-w-sm">{{ $merchant }} is confirming your UTR with their bank. This page updates automatically once it's approved.</p>
                        <dl class="mt-8 w-full max-w-xs rounded-xl bg-slate-50 ring-1 ring-slate-200 divide-y divide-slate-200 text-sm text-left">
                            <div class="flex justify-between px-4 py-3"><dt class="text-slate-500">Your UTR</dt><dd class="font-mono font-medium text-slate-900">{{ $order->customer_utr }}</dd></div>
                            <div class="flex justify-between px-4 py-3"><dt class="text-slate-500">Submitted</dt><dd class="font-medium text-slate-900">{{ $order->utr_submitted_at?->format('d M, h:i A') }}</dd></div>
                        </dl>
                        <p class="mt-6 text-xs text-slate-400">Don't pay again. You can close this page; the merchant will be notified once it's confirmed.</p>
                    </div>
                @elseif ($order->status === 'expired')
                    <div class="flex-1 flex flex-col items-center justify-center text-center py-8">
                        <div class="grid place-items-center w-20 h-20 rounded-full bg-slate-100 text-slate-400"><x-icon name="clock" class="w-10 h-10" /></div>
                        <h1 class="mt-6 text-2xl font-bold tracking-tight text-slate-900">Payment link expired</h1>
                        @if ($order->canRecoverLatePayment())
                            <p class="mt-2 text-sm text-slate-500 max-w-sm">Already paid? Don't pay again. We can still check the provider until {{ $order->expires_at->copy()->addHours((int) config('gateway.late_payment_grace_hours', 24))->format('d M, h:i A') }}. This page updates when payment is confirmed.</p>
                        @endif
                        @if ($order->acceptsCustomerUtr())
                            <p class="mt-2 text-sm text-slate-500 max-w-sm">If you already paid, don't pay again — submit your UTR below so {{ $merchant }} can confirm it.</p>
                            @include('pay._utr-form', ['class' => 'mt-8 w-full max-w-sm'])
                        @else
                            <p class="mt-2 text-sm text-slate-500 max-w-xs">Please ask {{ $merchant }} for a new payment link.</p>
                            <div class="mt-8 flex items-start gap-3 rounded-xl bg-amber-50 ring-1 ring-amber-200 px-4 py-3 text-left text-sm text-amber-800 max-w-sm">
                                <x-icon name="alert" class="w-5 h-5 text-amber-600" />
                                <div>If money was debited from your account, don't pay again. Share your UTR with the merchant to confirm the payment.</div>
                            </div>
                        @endif
                    </div>
                @elseif ($order->paymentAccount->isHosted())
                    @php $pgName = $order->paymentAccount->provider === 'phonepe_pg' ? 'PhonePe' : 'Paytm'; @endphp
                    <div class="flex-1 flex flex-col items-center justify-center text-center py-6">
                        <div class="grid place-items-center w-20 h-20 rounded-2xl {{ $pgName === 'PhonePe' ? 'bg-[#5f259f]' : 'bg-[#00baf2]' }} text-white shadow-lg">
                            <x-icon name="lock" class="w-9 h-9" />
                        </div>
                        <h1 class="mt-6 text-xl font-bold tracking-tight text-slate-900">Pay securely with {{ $pgName }}</h1>
                        <p class="mt-2 text-sm text-slate-500 max-w-sm">You'll complete the payment on {{ $pgName }}'s secure checkout using UPI, card or netbanking, then come back here automatically.</p>

                        @error('checkout')
                            <div class="mt-6 flex items-start gap-2 rounded-lg bg-red-50 ring-1 ring-red-200 px-3 py-2 text-left text-sm text-red-700 max-w-sm">
                                <x-icon name="alert" class="w-5 h-5" /><div>{{ $message }}</div>
                            </div>
                        @enderror

                        <form id="pg-start" method="POST" action="{{ route('pay.start', $order) }}" class="mt-8 w-full max-w-sm">
                            @csrf
                            <button class="btn btn-primary btn-lg w-full"><x-icon name="lock" class="w-5 h-5" /> Pay ₹{{ $amount }} with {{ $pgName }}</button>
                        </form>
                        <div class="mt-4 flex flex-wrap justify-center gap-2">
                            @foreach ($apps as [$name, $scheme, $color, $logo])
                                <span class="rounded-full bg-slate-50 ring-1 ring-slate-200 px-3 py-1 text-xs font-bold {{ $color }} flex items-center gap-1.5">
                                    <img src="{{ asset($logo) }}" class="w-4 h-4 rounded-sm" alt="{{ $name }}">{{ $name }}
                                </span>
                            @endforeach
                            <span class="rounded-full bg-slate-50 ring-1 ring-slate-200 px-3 py-1 text-xs font-semibold text-slate-600">Cards</span>
                            <span class="rounded-full bg-slate-50 ring-1 ring-slate-200 px-3 py-1 text-xs font-semibold text-slate-600">Netbanking</span>
                        </div>
                        @if ($order->provider_checkout)
                            <p class="mt-6 text-xs text-slate-500">Already paid? Stay on this page — we're checking with {{ $pgName }} automatically.</p>
                        @endif
                        <div class="mt-6 flex items-center justify-center gap-3">
                            <div class="relative w-10 h-10">
                                <svg viewBox="0 0 36 36" class="w-10 h-10 -rotate-90">
                                    <circle cx="18" cy="18" r="16" fill="none" stroke="rgb(0 0 0 / .08)" stroke-width="3" />
                                    <circle id="ring-pg" cx="18" cy="18" r="16" fill="none" stroke="#818cf8" stroke-width="3" stroke-linecap="round" stroke-dasharray="100.5" stroke-dashoffset="0" class="ring-transition" />
                                </svg>
                                <div id="timer-pg" class="absolute inset-0 grid place-items-center font-mono text-[10px] font-medium text-slate-700">--:--</div>
                            </div>
                            <div class="text-sm text-slate-500">Complete payment before time runs out</div>
                        </div>
                    </div>
                    <div class="mt-auto">
                        <div class="flex items-center justify-center gap-2.5 rounded-xl bg-slate-50 ring-1 ring-slate-200 px-4 py-3 text-sm text-slate-600">
                            <svg class="w-4 h-4 animate-spin text-slate-400" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                            <span id="status-text">Waiting for payment…</span>
                        </div>
                    </div>
                @else
                    <div class="text-center">
                        <h1 class="text-lg font-semibold text-slate-900">Scan &amp; pay with any UPI app</h1>
                        <p class="text-sm text-slate-500 mt-1">Open your UPI app, scan the QR and approve the payment.</p>
                    </div>

                    <div class="relative mx-auto mt-6 p-3 rounded-2xl bg-white ring-1 ring-slate-200 shadow-sm">
                        <span class="absolute -top-px -left-px w-6 h-6 border-t-[3px] border-l-[3px] border-brand-600 rounded-tl-2xl"></span>
                        <span class="absolute -top-px -right-px w-6 h-6 border-t-[3px] border-r-[3px] border-brand-600 rounded-tr-2xl"></span>
                        <span class="absolute -bottom-px -left-px w-6 h-6 border-b-[3px] border-l-[3px] border-brand-600 rounded-bl-2xl"></span>
                        <span class="absolute -bottom-px -right-px w-6 h-6 border-b-[3px] border-r-[3px] border-brand-600 rounded-br-2xl"></span>
                        <img src="{{ route('pay.qr', $order) }}" alt="UPI QR code for ₹{{ $amount }}" class="w-52 h-52 sm:w-56 sm:h-56">
                    </div>

                    <div class="mt-5 flex flex-wrap justify-center gap-2">
                        @foreach ($apps as [$name, $scheme, $color, $logo])
                            <span class="rounded-full bg-slate-50 ring-1 ring-slate-200 px-3 py-1 text-xs font-bold {{ $color }} flex items-center gap-1.5">
                                <img src="{{ asset($logo) }}" class="w-4 h-4 rounded-sm" alt="{{ $name }}">{{ $name }}
                            </span>
                        @endforeach
                    </div>

                    {{-- On phones, open the UPI app directly --}}
                    <div class="md:hidden mt-6 space-y-3">
                        <div class="flex items-center gap-3 text-xs text-slate-400"><span class="h-px flex-1 bg-slate-200"></span>OR PAY ON THIS PHONE<span class="h-px flex-1 bg-slate-200"></span></div>
                        <a href="{{ $order->upiIntentUrl() }}" class="btn btn-primary btn-lg w-full"><x-icon name="phone" class="w-5 h-5" /> Pay ₹{{ $amount }} with UPI app</a>
                        <div class="grid grid-cols-3 gap-2">
                            @foreach (array_slice($apps, 0, 3) as [$name, $scheme, $color, $logo])
                                <a href="{{ $order->upiIntentUrl($scheme) }}" class="btn btn-secondary font-bold {{ $color }} flex items-center justify-center gap-1.5">
                                    <img src="{{ asset($logo) }}" class="w-5 h-5 rounded-sm" alt="{{ $name }}">{{ $name }}
                                </a>
                            @endforeach
                        </div>
                    </div>

                    <div class="mt-6 flex items-center justify-between gap-3 rounded-xl bg-slate-50 ring-1 ring-slate-200 px-4 py-3">
                        <div class="min-w-0">
                            <div class="text-xs text-slate-500">Or pay to UPI ID</div>
                            <div class="font-mono text-sm font-medium text-slate-900 truncate">{{ $order->paymentAccount->upi_id }}</div>
                        </div>
                        <x-copy :text="$order->paymentAccount->upi_id" />
                    </div>

                    @if ($order->acceptsCustomerUtr())
                        @include('pay._utr-form', ['class' => 'mt-4'])
                    @endif

                    <div class="mt-auto pt-6">
                        <div class="flex items-center justify-center gap-2.5 rounded-xl bg-amber-50 ring-1 ring-amber-200 px-4 py-3 text-sm text-amber-800">
                            <svg class="w-4 h-4 animate-spin text-amber-600" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                            <span id="status-text">{{ $order->acceptsCustomerUtr() ? 'After paying, submit your UTR above' : 'Waiting for payment…' }}</span>
                        </div>
                        <p class="mt-2 text-center text-xs text-slate-400">Please don't close or refresh this page after paying.</p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <footer class="pb-6 flex flex-col items-center gap-2 text-xs text-slate-400">
        <div class="flex items-center gap-1.5"><x-icon name="lock" class="w-3.5 h-3.5" /> Secured by <span class="font-semibold text-slate-500">{{ config('app.name') }}</span></div>
        <div>Payments go directly to the merchant's bank account via UPI.</div>
    </footer>
</div>
@endsection

@if (in_array($order->status, ['pending', 'review']) || $order->canRecoverLatePayment())
@push('scripts')
<script nonce="{{ $csp_nonce }}">
(() => {
    const initialStatus = @json($order->status);
    const recoveryEndsAt = {{ $order->expires_at?->copy()->addHours((int) config('gateway.late_payment_grace_hours', 24))->getTimestampMs() ?? 0 }};
    const pollDelay = initialStatus === 'expired' ? 30000 : 4000;
    const expiresAt = {{ ($order->expires_at ?? now()->addMinutes(config('gateway.order_expiry_minutes', 15)))->getTimestampMs() }};
    const total = {{ $totalSeconds }};
    const timer = document.getElementById('timer');
    const ring = document.getElementById('ring');
    const statusUrl = @json(route('pay.status', $order));

    const tick = () => {
        if (!timer) return;
        const left = Math.max(0, Math.floor((expiresAt - Date.now()) / 1000));
        const display = String(Math.floor(left / 60)).padStart(2, '0') + ':' + String(left % 60).padStart(2, '0');
        const offset = (100.5 * (1 - left / total)).toFixed(2);
        const stroke = left < 60 ? '#f87171' : '#818cf8';
        timer.textContent = display;
        ring.style.strokeDashoffset = offset;
        ring.style.stroke = stroke;
        // Hosted-PG secondary timer widget (different IDs to avoid duplicate-id)
        const timerPg = document.getElementById('timer-pg');
        const ringPg  = document.getElementById('ring-pg');
        if (timerPg) timerPg.textContent = display;
        if (ringPg)  { ringPg.style.strokeDashoffset = offset; ringPg.style.stroke = stroke; }
    };
    tick();
    setInterval(tick, 1000);

    const poll = async () => {
        if (initialStatus === 'expired' && Date.now() > recoveryEndsAt) return;
        try {
            const res = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
            if (res.ok) {
                const data = await res.json();
                if (data.status !== initialStatus) {
                    const text = document.getElementById('status-text');
                    if (text) text.textContent = data.status === 'paid' ? 'Payment received! Redirecting…' : 'Updating…';
                    window.location.href = data.redirect || window.location.href;
                    return;
                }
            }
        } catch (e) {}
        setTimeout(poll, pollDelay);
    };
    setTimeout(poll, pollDelay);
})();
</script>
@endpush
@endif
