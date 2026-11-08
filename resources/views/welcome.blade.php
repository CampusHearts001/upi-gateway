@extends('layouts.base')
@section('title', 'Accept UPI payments directly in your bank account')

@section('body')
<div class="bg-white">
    @include('layouts.public-nav')

    {{-- Hero --}}
    <section class="relative overflow-hidden bg-white">
        <div class="absolute inset-0 -z-0" style="background: radial-gradient(900px circle at 80% -10%, rgb(99 102 241 / .13), transparent 55%), radial-gradient(600px circle at 5% 100%, rgb(14 165 233 / .09), transparent 55%);"></div>
        <div class="absolute top-16 right-0 w-80 h-80 bg-brand-100/30 rounded-full blur-3xl -z-0 pointer-events-none"></div>
        <div class="absolute bottom-0 left-0 w-64 h-64 bg-sky-100/30 rounded-full blur-3xl -z-0 pointer-events-none"></div>
        <div class="relative mx-auto max-w-6xl px-4 sm:px-6 pt-16 pb-20 lg:pt-24 lg:pb-28 grid lg:grid-cols-2 gap-16 items-center">
            <div>
                @if (config('auth.allow_registration', false))
                <a href="{{ route('register') }}" class="inline-flex items-center gap-2 rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-700 ring-1 ring-inset ring-brand-600/20 hover:bg-brand-100 transition-colors">
                    <span class="flex h-2 w-2 rounded-full bg-brand-500 animate-pulse"></span>
                    Now live · 0% transaction fee
                    <x-icon name="arrow-right" class="w-3 h-3" />
                </a>
                @endif
                <h1 class="mt-6 text-4xl sm:text-5xl lg:text-[3.5rem] font-extrabold tracking-tight text-slate-900 leading-[1.08]">
                    Accept UPI payments
                    <span class="relative whitespace-nowrap">
                        <span class="bg-gradient-to-r from-brand-600 to-sky-500 bg-clip-text text-transparent">directly</span>
                        <svg class="absolute -bottom-1 left-0 w-full" viewBox="0 0 200 8" fill="none" preserveAspectRatio="none"><path d="M0 6 Q50 0 100 4 Q150 8 200 2" stroke="url(#ug)" stroke-width="2.5" stroke-linecap="round" fill="none"/><defs><linearGradient id="ug" x1="0" y1="0" x2="200" y2="0" gradientUnits="userSpaceOnUse"><stop stop-color="#4f46e5"/><stop offset="1" stop-color="#0ea5e9"/></linearGradient></defs></svg>
                    </span>
                    in your bank account
                </h1>
                <p class="mt-6 text-lg text-slate-600 max-w-xl leading-relaxed">Dynamic QR for every order, automatic payment confirmation and signed webhooks. No settlement delays — money lands straight in your own UPI account.</p>
                <div class="mt-10 flex flex-wrap gap-3">
                    @if (config('auth.allow_registration', false))
                    <a href="{{ route('register') }}" class="btn btn-primary btn-lg shadow-lg shadow-brand-600/30 hover:shadow-xl hover:shadow-brand-600/40 transition-all">Start for free <x-icon name="arrow-right" class="w-5 h-5" /></a>
                    @endif
                    <a href="{{ route('docs') }}" class="btn btn-secondary btn-lg"><x-icon name="code" class="w-5 h-5" /> View API docs</a>
                </div>
                <div class="mt-10 flex flex-wrap items-center gap-x-6 gap-y-3 text-sm text-slate-500">
                    @foreach (['No setup fee', 'Instant settlement', 'All UPI apps', 'Cancel anytime'] as $point)
                        <span class="flex items-center gap-1.5"><x-icon name="check-circle" class="w-4 h-4 text-emerald-500" /> {{ $point }}</span>
                    @endforeach
                </div>
            </div>

            {{-- Product mock --}}
            <div class="relative mx-auto w-full max-w-md">
                <div class="absolute -inset-4 bg-gradient-to-tr from-brand-200/50 to-sky-200/40 blur-2xl rounded-[2rem]"></div>
                <div class="relative bg-white rounded-2xl shadow-pop ring-1 ring-slate-200 overflow-hidden">
                    <div class="bg-slate-950 text-white px-6 py-5 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <x-avatar name="Sharma Electronics" class="!bg-white/10 !text-white" />
                            <div><div class="text-xs text-slate-400">Paying to</div><div class="font-semibold text-sm">Sharma Electronics</div></div>
                        </div>
                        <div class="text-right"><div class="text-xs text-slate-400">Amount</div><div class="font-bold">₹2,499.00</div></div>
                    </div>
                    <div class="p-6 flex flex-col items-center">
                        <div class="p-3 rounded-xl ring-1 ring-slate-200">
                            <svg viewBox="0 0 29 29" class="w-40 h-40" shape-rendering="crispEdges">
                                @php
                                    // Decorative QR-like pattern for the illustration.
                                    mt_srand(7);
                                    $finder = fn ($x, $y) => ($x < 7 && $y < 7) || ($x > 21 && $y < 7) || ($x < 7 && $y > 21);
                                @endphp
                                @foreach ([[0, 0], [22, 0], [0, 22]] as [$fx, $fy])
                                    <rect x="{{ $fx }}" y="{{ $fy }}" width="7" height="7" fill="#0f172a"/><rect x="{{ $fx + 1 }}" y="{{ $fy + 1 }}" width="5" height="5" fill="white"/><rect x="{{ $fx + 2 }}" y="{{ $fy + 2 }}" width="3" height="3" fill="#0f172a"/>
                                @endforeach
                                @for ($y = 0; $y < 29; $y++)
                                    @for ($x = 0; $x < 29; $x++)
                                        @if (! $finder($x, $y) && mt_rand(0, 100) < 48)<rect x="{{ $x }}" y="{{ $y }}" width="1" height="1" fill="#0f172a"/>@endif
                                    @endfor
                                @endfor
                            </svg>
                        </div>
                        <div class="mt-4 flex gap-2">
                            @foreach ([['GPay', 'text-[#1a73e8]'], ['PhonePe', 'text-[#5f259f]'], ['Paytm', 'text-[#00baf2]'], ['BHIM', 'text-[#f47920]']] as [$app, $c])
                                <span class="rounded-full bg-slate-50 ring-1 ring-slate-200 px-2.5 py-0.5 text-[11px] font-bold {{ $c }}">{{ $app }}</span>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="absolute -right-4 sm:-right-10 bottom-10 bg-white rounded-xl shadow-pop ring-1 ring-slate-200 px-4 py-3 flex items-center gap-3">
                    <span class="grid place-items-center w-9 h-9 rounded-full bg-emerald-500 text-white"><x-icon name="check" class="w-5 h-5" /></span>
                    <div><div class="text-sm font-semibold text-slate-900">Payment received</div><div class="text-xs text-slate-500">₹2,499.00 · just now</div></div>
                </div>
                <div class="absolute -left-4 sm:-left-10 top-24 bg-white rounded-xl shadow-pop ring-1 ring-slate-200 px-3 py-2 flex items-center gap-2">
                    <x-icon name="link" class="w-4 h-4 text-brand-600" /><span class="font-mono text-[11px] text-slate-700">order.paid → 200 OK</span>
                </div>
            </div>
        </div>
    </section>

    {{-- Stats --}}
    <section class="border-y border-slate-200/70 bg-slate-50 py-12">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-8 text-center">
                @foreach ([
                    ['₹0', 'Transaction fee', 'text-emerald-600'],
                    ['< 5s', 'Auto verification', 'text-brand-600'],
                    ['99.9%', 'Uptime SLA', 'text-sky-600'],
                    ['6 UPI apps', 'Supported', 'text-amber-600'],
                ] as [$val, $label, $color])
                    <div class="group">
                        <div class="text-3xl sm:text-4xl font-extrabold tracking-tight {{ $color }}">{{ $val }}</div>
                        <div class="mt-1.5 text-sm text-slate-500 font-medium">{{ $label }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Integrations strip --}}
    <section class="bg-white py-10 border-b border-slate-200/70">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 text-center">
            <p class="text-xs font-semibold uppercase tracking-widest text-slate-400 mb-6">Works with all major UPI apps</p>
            <div class="flex flex-wrap justify-center items-center gap-6">
                @foreach ([
                    ['GPay', '#1a73e8', 'G'],
                    ['PhonePe', '#5f259f', 'P'],
                    ['Paytm', '#00baf2', 'P'],
                    ['BHIM', '#f47920', 'B'],
                    ['Amazon Pay', '#ff9900', 'A'],
                    ['CRED', '#1c1c1c', 'C'],
                ] as [$name, $color, $letter])
                    <div class="flex items-center gap-2 rounded-full bg-slate-50 ring-1 ring-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:ring-slate-300 transition-all">
                        <span class="w-5 h-5 rounded-full flex items-center justify-center text-white text-[10px] font-bold" style="background:{{ $color }}">{{ $letter }}</span>
                        {{ $name }}
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Features --}}
    <section id="features" class="bg-slate-50 border-y border-slate-200/70 py-20 lg:py-28">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            <div class="max-w-2xl">
                <div class="text-sm font-semibold text-brand-600">Everything you need</div>
                <h2 class="mt-2 text-3xl sm:text-4xl font-bold tracking-tight text-slate-900">A complete UPI checkout, without a payment aggregator</h2>
                <p class="mt-4 text-lg text-slate-600">Keep your own UPI account and get the automation of a payment gateway.</p>
            </div>
            <div class="mt-14 grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
                @foreach ([
                    ['qr', 'Dynamic QR per order', 'Every order gets a QR with the exact amount and a unique reference, so payments are matched automatically.', 'bg-violet-600'],
                    ['bolt', 'Automatic verification', 'Connect Paytm for Business and payments are confirmed within seconds, no screenshots needed.', 'bg-amber-500'],
                    ['banknotes', 'Direct settlement', 'Money goes straight from your customer to your bank account. We never touch your funds.', 'bg-emerald-600'],
                    ['link', 'Signed webhooks', 'Get notified on your server the moment an order is paid, with HMAC signatures and automatic retries.', 'bg-sky-600'],
                    ['code', 'Developer-friendly API', 'Create orders and check status with a simple REST API. Integrate in under an hour.', 'bg-brand-600'],
                    ['users', 'Multi-merchant ready', 'Multiple UPI accounts per business, a clean dashboard, and payment links you can share anywhere.', 'bg-rose-500'],
                ] as [$icon, $title, $text, $bg])
                    <div class="card p-6 hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 group">
                        <div class="grid place-items-center w-11 h-11 rounded-xl {{ $bg }} text-white shadow-sm">
                            <x-icon :name="$icon" class="w-5 h-5" />
                        </div>
                        <h3 class="mt-5 font-semibold text-slate-900 group-hover:text-brand-700 transition-colors">{{ $title }}</h3>
                        <p class="mt-2 text-sm text-slate-600 leading-relaxed">{{ $text }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Comparison --}}
    <section class="py-20 lg:py-24 bg-white border-b border-slate-200/70">
        <div class="mx-auto max-w-4xl px-4 sm:px-6">
            <div class="text-center mb-12">
                <div class="text-sm font-semibold text-brand-600">Why choose us</div>
                <h2 class="mt-2 text-3xl font-bold tracking-tight text-slate-900">vs traditional payment gateways</h2>
            </div>
            <div class="card overflow-hidden">
                <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="th text-left w-1/2">Feature</th>
                            <th class="th text-center text-brand-700">{{ config('app.name') }}</th>
                            <th class="th text-center text-slate-500">Traditional PG</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ([
                            ['Transaction fee', '0%', '1.5–2.5%'],
                            ['Settlement time', 'Instant', '1–3 business days'],
                            ['Holds your funds', 'Never', 'Yes'],
                            ['RBI registration needed', 'No', 'Yes'],
                            ['Setup time', '< 1 hour', 'Days to weeks'],
                            ['Dynamic QR per order', true, false],
                            ['Webhook with HMAC', true, true],
                        ] as [$feature, $ours, $theirs])
                            <tr class="hover:bg-slate-50/50 transition-colors">
                                <td class="td font-medium text-slate-700">{{ $feature }}</td>
                                <td class="td text-center">
                                    @if ($ours === true) <x-icon name="check" class="w-5 h-5 text-emerald-500 mx-auto" />
                                    @elseif ($ours === false) <x-icon name="x-mark" class="w-5 h-5 text-slate-300 mx-auto" />
                                    @else <span class="font-semibold text-emerald-600">{{ $ours }}</span>
                                    @endif
                                </td>
                                <td class="td text-center">
                                    @if ($theirs === true) <x-icon name="check" class="w-5 h-5 text-slate-400 mx-auto" />
                                    @elseif ($theirs === false) <x-icon name="x-mark" class="w-5 h-5 text-slate-300 mx-auto" />
                                    @else <span class="text-slate-500">{{ $theirs }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </section>
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            <div class="text-center mb-12">
                <div class="text-sm font-semibold text-brand-600">Trusted by merchants</div>
                <h2 class="mt-2 text-3xl font-bold tracking-tight text-slate-900">What our users say</h2>
            </div>
            <div class="grid sm:grid-cols-3 gap-6">
                @foreach ([
                    ['Rahul Verma', 'E-commerce Store Owner', 'RV', 'Integration took less than an hour. Payments hit my account instantly — no waiting for settlements.'],
                    ['Priya Nair', 'Freelance Designer', 'PN', 'Finally a payment tool that doesn\'t take a cut. The QR checkout looks professional and my clients love it.'],
                    ['Amit Joshi', 'SaaS Founder', 'AJ', 'Webhooks are rock solid. We\'ve processed thousands of orders without a single missed notification.'],
                ] as [$name, $role, $initials, $quote])
                    <div class="card p-6 flex flex-col gap-4">
                        <div class="flex gap-1">
                            @for ($i = 0; $i < 5; $i++)
                                <svg class="w-4 h-4 text-amber-400 fill-current" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                            @endfor
                        </div>
                        <p class="text-slate-600 text-sm leading-relaxed flex-1">"{{ $quote }}"</p>
                        <div class="flex items-center gap-3 pt-2 border-t border-slate-100">
                            <div class="grid place-items-center w-9 h-9 rounded-full bg-brand-600 text-white text-xs font-bold">{{ $initials }}</div>
                            <div><div class="text-sm font-semibold text-slate-900">{{ $name }}</div><div class="text-xs text-slate-500">{{ $role }}</div></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- How it works --}}
    <section id="how" class="py-20 lg:py-28">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 grid lg:grid-cols-2 gap-16 items-center">
            <div>
                <div class="text-sm font-semibold text-brand-600">How it works</div>
                <h2 class="mt-2 text-3xl sm:text-4xl font-bold tracking-tight text-slate-900">Live in four simple steps</h2>
                <ol class="mt-10 space-y-8">
                    @foreach ([
                        ['Connect your UPI', 'Add your Paytm for Business or any UPI ID in the dashboard.'],
                        ['Create an order', 'Call our API from your website, or create a payment link from the dashboard.'],
                        ['Customer pays', 'They scan the dynamic QR or tap their favourite UPI app on mobile.'],
                        ['You get notified', 'We verify the payment and send a signed webhook to your server.'],
                    ] as [$title, $text])
                        <li class="flex gap-5">
                            <span class="grid place-items-center w-10 h-10 rounded-full bg-brand-50 text-brand-700 font-bold ring-1 ring-brand-200 shrink-0">{{ $loop->iteration }}</span>
                            <div><h3 class="font-semibold text-slate-900">{{ $title }}</h3><p class="mt-1 text-slate-600">{{ $text }}</p></div>
                        </li>
                    @endforeach
                </ol>
            </div>
            <div class="rounded-2xl bg-slate-950 shadow-pop ring-1 ring-slate-800 overflow-hidden">
                <div class="flex items-center gap-2 px-4 py-3 border-b border-slate-800">
                    <span class="w-3 h-3 rounded-full bg-slate-700"></span><span class="w-3 h-3 rounded-full bg-slate-700"></span><span class="w-3 h-3 rounded-full bg-slate-700"></span>
                    <span class="ml-3 text-xs text-slate-400 font-mono">create-order.sh</span>
                </div>
<pre class="p-5 text-[13px] leading-relaxed font-mono text-slate-300 overflow-x-auto"><span class="text-slate-500"># Create a UPI order</span>
<span class="text-sky-400">curl</span> -X POST {{ url('/api/v1/orders') }} \
  -H <span class="text-emerald-400">"X-Api-Key: key_live_..."</span> \
  -H <span class="text-emerald-400">"X-Api-Secret: sec_live_..."</span> \
  -d <span class="text-amber-300">'{"amount": 499, "client_order_id": "INV1001"}'</span>

<span class="text-slate-500"># Response</span>
{
  <span class="text-sky-300">"order_id"</span>: <span class="text-emerald-400">"ORD7K2M9XQ4B8N1LZ"</span>,
  <span class="text-sky-300">"status"</span>: <span class="text-emerald-400">"pending"</span>,
  <span class="text-sky-300">"payment_url"</span>: <span class="text-emerald-400">"{{ url('/pay') }}/ORD7K2..."</span>
}</pre>
            </div>
        </div>
    </section>

    {{-- Pricing --}}
    <section id="pricing" class="bg-slate-50 border-y border-slate-200/70 py-20 lg:py-28">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 text-center">
            <div class="text-sm font-semibold text-brand-600">Pricing</div>
            <h2 class="mt-2 text-3xl sm:text-4xl font-bold tracking-tight text-slate-900">Simple and transparent</h2>
            <p class="mt-4 text-lg text-slate-600">No per-transaction cut. Keep 100% of every payment.</p>
            <div class="mt-12 grid sm:grid-cols-3 gap-6 items-stretch">

                {{-- Starter --}}
                <div class="card p-8 text-left flex flex-col">
                    <div class="font-semibold text-slate-900 text-lg">Starter</div>
                    <p class="mt-1 text-sm text-slate-500">Perfect for individuals & small stores</p>
                    <div class="mt-6 flex items-baseline gap-1">
                        <span class="text-5xl font-extrabold tracking-tight text-slate-900">Free</span>
                    </div>
                    <div class="mt-1 text-sm text-slate-500">0% transaction fee · forever</div>
                    <ul class="mt-8 space-y-3 text-sm text-slate-700 flex-1">
                        @foreach ([
                            '1 UPI account',
                            'Up to ₹50,000/day',
                            'Payment links & API orders',
                            'Manual UTR verification',
                            'Basic dashboard',
                        ] as $f)
                            <li class="flex items-center gap-3"><x-icon name="check" class="w-5 h-5 text-emerald-500 shrink-0" /> {{ $f }}</li>
                        @endforeach
                    </ul>
                    @if (config('auth.allow_registration', false))
                    <a href="{{ route('register') }}" class="btn btn-secondary w-full mt-8 py-3">Get started free</a>
                    @endif
                </div>

                {{-- Pro --}}
                <div class="card p-8 text-left flex flex-col ring-2 ring-brand-600 relative">
                    <span class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-brand-600 px-3 py-0.5 text-xs font-semibold text-white">Most popular</span>
                    <div class="font-semibold text-slate-900 text-lg">Pro</div>
                    <p class="mt-1 text-sm text-slate-500">For growing businesses</p>
                    <div class="mt-6 flex items-baseline gap-1">
                        <span class="text-5xl font-extrabold tracking-tight text-slate-900">₹999</span>
                        <span class="text-slate-500">/month</span>
                    </div>
                    <div class="mt-1 text-sm text-slate-500">0% transaction fee</div>
                    <ul class="mt-8 space-y-3 text-sm text-slate-700 flex-1">
                        @foreach ([
                            'Up to 5 UPI accounts',
                            'Up to ₹5,00,000/day',
                            'Auto Paytm for Business verification',
                            'Signed webhooks with retries',
                            'Multiple merchants support',
                            'Dashboard & reports',
                            'Priority email support',
                        ] as $f)
                            <li class="flex items-center gap-3"><x-icon name="check" class="w-5 h-5 text-brand-600 shrink-0" /> {{ $f }}</li>
                        @endforeach
                    </ul>
                    @if (config('auth.allow_registration', false))
                    <a href="{{ route('register') }}" class="btn btn-primary w-full mt-8 py-3">Start free trial</a>
                    @endif
                </div>

                {{-- Business --}}
                <div class="card p-8 text-left flex flex-col">
                    <div class="font-semibold text-slate-900 text-lg">Business</div>
                    <p class="mt-1 text-sm text-slate-500">For high-volume operations</p>
                    <div class="mt-6 flex items-baseline gap-1">
                        <span class="text-5xl font-extrabold tracking-tight text-slate-900">₹2,999</span>
                        <span class="text-slate-500">/month</span>
                    </div>
                    <div class="mt-1 text-sm text-slate-500">0% transaction fee</div>
                    <ul class="mt-8 space-y-3 text-sm text-slate-700 flex-1">
                        @foreach ([
                            'Unlimited UPI accounts',
                            'Unlimited daily volume',
                            'Auto verification (all providers)',
                            'Signed webhooks with retries',
                            'White-label checkout page',
                            'Advanced analytics & exports',
                            'Dedicated account manager',
                            'SLA-backed support',
                        ] as $f)
                            <li class="flex items-center gap-3"><x-icon name="check" class="w-5 h-5 text-emerald-500 shrink-0" /> {{ $f }}</li>
                        @endforeach
                    </ul>
                    @if (config('auth.allow_registration', false))
                    <a href="{{ route('register') }}" class="btn btn-secondary w-full mt-8 py-3">Contact sales</a>
                    @endif
                </div>

            </div>
            <p class="mt-8 text-sm text-slate-500">All plans include 0% transaction fee. No hidden charges. Cancel anytime.</p>
        </div>
    </section>

    {{-- FAQ --}}
    <section class="py-20 lg:py-28 bg-slate-50 border-t border-slate-200/70">
        <div class="mx-auto max-w-3xl px-4 sm:px-6">
            <div class="text-center mb-12">
                <div class="text-sm font-semibold text-brand-600">FAQ</div>
                <h2 class="mt-2 text-3xl font-bold tracking-tight text-slate-900">Frequently asked questions</h2>
            </div>
            <div class="space-y-3">
                @foreach ([
                    ['Do you hold my money?', 'No. Customers pay directly into your own UPI account. We only generate the QR code and verify whether the payment was made. We are not a payment aggregator.'],
                    ['Which UPI apps are supported?', 'All of them — Google Pay, PhonePe, Paytm, BHIM, Amazon Pay, CRED, and any bank app that supports UPI.'],
                    ['How is a payment confirmed?', 'With a Paytm for Business account, we check the payment status automatically within seconds. For other UPI IDs, you or your customer can confirm with the UTR number from the dashboard.'],
                    ['How long does integration take?', 'Most developers integrate in under an hour using our REST API and webhooks. You can also create payment links from the dashboard without writing any code.'],
                    ['Is this legal without RBI registration?', 'Yes. Since payments go directly to your own UPI account and we never hold or settle funds, we are a software tool — not a payment aggregator. Always consult a legal advisor for your specific use case.'],
                    ['Can I use multiple UPI accounts?', 'Yes. Pro and Business plans support multiple UPI accounts with automatic rotation and per-account daily limits.'],
                ] as [$q, $a])
                    <details class="group card px-6 py-5 open:shadow-md transition-shadow">
                        <summary class="flex cursor-pointer list-none items-center justify-between font-semibold text-slate-900 gap-4">
                            <span>{{ $q }}</span>
                            <span class="grid place-items-center w-6 h-6 rounded-full bg-slate-100 group-open:bg-brand-100 shrink-0 transition-colors">
                                <x-icon name="chevron-down" class="w-4 h-4 text-slate-500 group-open:text-brand-600 transition group-open:rotate-180" />
                            </span>
                        </summary>
                        <p class="mt-3 text-slate-600 leading-relaxed">{{ $a }}</p>
                    </details>
                @endforeach
            </div>
        </div>
    </section>

    {{-- CTA --}}
    <section class="px-4 sm:px-6 py-20">
        <div class="relative mx-auto max-w-6xl overflow-hidden rounded-3xl bg-slate-950 px-6 py-16 sm:px-16 text-center">
            <div class="absolute inset-0" style="background: radial-gradient(700px circle at 50% -20%, rgb(79 70 229 / .7), transparent 55%), radial-gradient(400px circle at 80% 120%, rgb(14 165 233 / .4), transparent 55%);"></div>
            <div class="absolute inset-0 bg-[url('data:image/svg+xml,%3Csvg width=\'60\' height=\'60\' viewBox=\'0 0 60 60\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cg fill=\'none\' fill-rule=\'evenodd\'%3E%3Cg fill=\'%23ffffff\' fill-opacity=\'0.03\'%3E%3Cpath d=\'M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z\'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E')] opacity-40"></div>
            <div class="relative">
                <span class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-white/80 ring-1 ring-white/20 mb-6">
                    <span class="flex h-2 w-2 rounded-full bg-emerald-400 animate-pulse"></span> Free forever · No credit card needed
                </span>
                <h2 class="text-3xl sm:text-4xl font-bold tracking-tight text-white">Start accepting UPI payments today</h2>
                <p class="mt-4 text-lg text-slate-300 max-w-xl mx-auto">Set up in minutes. No paperwork, no setup fee. Money goes directly to your bank account.</p>
                <div class="mt-8 flex flex-wrap justify-center gap-3">
                    @if (config('auth.allow_registration', false))
                    <a href="{{ route('register') }}" class="btn btn-lg bg-white text-slate-900 hover:bg-slate-100 shadow-xl">Create free account <x-icon name="arrow-right" class="w-5 h-5" /></a>
                    @endif
                    <a href="{{ route('docs') }}" class="btn btn-lg bg-white/10 text-white hover:bg-white/20 ring-1 ring-white/20"><x-icon name="code" class="w-5 h-5" /> View API docs</a>
                </div>
            </div>
        </div>
    </section>

    {{-- CTA bottom strip --}}
    <div class="bg-brand-600 py-3.5">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 flex flex-col sm:flex-row items-center justify-between gap-3">
            <p class="flex items-center gap-2 text-white text-sm font-medium"><x-icon name="bolt" class="w-4 h-4" /> Join hundreds of merchants already using {{ config('app.name') }}</p>
            @if (config('auth.allow_registration', false))
            <a href="{{ route('register') }}" class="btn bg-white text-brand-700 hover:bg-brand-50 shrink-0 text-sm">Get started free <x-icon name="arrow-right" class="w-4 h-4" /></a>
            @endif
        </div>
    </div>

    <footer class="bg-slate-950 text-slate-400">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 py-14 grid sm:grid-cols-2 lg:grid-cols-4 gap-10">
            <div class="lg:col-span-2">
                <x-logo dark />
                <p class="mt-4 text-sm leading-relaxed max-w-sm">{{ config('app.name') }} is a dynamic QR and payment verification service. Not a payment aggregator — payments go directly to your own UPI account.</p>
                <div class="mt-6 flex gap-3">
                    @if (config('auth.allow_registration', false))
                    <a href="{{ route('register') }}" class="btn btn-primary btn-sm">Get started</a>
                    @endif
                    <a href="{{ route('docs') }}" class="btn bg-white/10 text-white hover:bg-white/20 btn-sm">API Docs</a>
                </div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase tracking-widest text-slate-500 mb-4">Product</div>
                <ul class="space-y-3 text-sm">
                    <li><a href="{{ route('home') }}#features" class="hover:text-white transition-colors">Features</a></li>
                    <li><a href="{{ route('home') }}#pricing" class="hover:text-white transition-colors">Pricing</a></li>
                    <li><a href="{{ route('home') }}#how" class="hover:text-white transition-colors">How it works</a></li>
                    <li><a href="{{ route('docs') }}" class="hover:text-white transition-colors">API Docs</a></li>
                </ul>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase tracking-widest text-slate-500 mb-4">Account</div>
                <ul class="space-y-3 text-sm">
                    <li><a href="{{ route('login') }}" class="hover:text-white transition-colors">Log in</a></li>
                    @if (config('auth.allow_registration', false))
                    <li><a href="{{ route('register') }}" class="hover:text-white transition-colors">Sign up free</a></li>
                    @endif
                    <li><a href="{{ route('dashboard') }}" class="hover:text-white transition-colors">Dashboard</a></li>
                </ul>
            </div>
        </div>
        <div class="border-t border-slate-800 py-5">
            <div class="mx-auto max-w-6xl px-4 sm:px-6 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-slate-500">
                <span>© {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</span>
                <span>Built with <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="inline w-4 h-4 text-red-500 align-text-bottom" aria-hidden="true"><path d="M11.645 20.91l-.007-.003-.022-.012a15.247 15.247 0 0 1-.383-.218 25.18 25.18 0 0 1-4.244-3.17C4.688 15.36 2.25 12.174 2.25 8.25 2.25 5.322 4.714 3 7.688 3A5.5 5.5 0 0 1 12 5.052 5.5 5.5 0 0 1 16.313 3c2.973 0 5.437 2.322 5.437 5.25 0 3.925-2.438 7.111-4.739 9.256a25.175 25.175 0 0 1-4.244 3.17 15.247 15.247 0 0 1-.383.219l-.022.012-.007.004-.003.001a.752.752 0 0 1-.704 0l-.003-.001Z"/></svg> for Indian merchants by <a href="https://github.com/mynkdev-21/" target="_blank" rel="noopener" class="text-slate-400 hover:text-white transition-colors font-medium">Mayank Singh</a></span>
            </div>
        </div>
    </footer>
</div>
@endsection
