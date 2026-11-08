@extends('layouts.base')
@section('title', 'API Documentation')

@php
    $base = url('/api/v1');
    $exampleBase = 'https://gateway.example.com/api/v1';
@endphp

@push('head')
<style>
    html { scroll-behavior: smooth; scroll-padding-top: 5rem; }
    .nav-link.active { color: #4f46e5; border-left-color: #4f46e5; font-weight: 600; }
    .section-anchor { scroll-margin-top: 5rem; }
</style>
@endpush

@section('body')
<div class="bg-white min-h-full">
    @include('layouts.public-nav')

    {{-- Hero --}}
    <div class="border-b border-slate-200 bg-gradient-to-br from-slate-950 via-brand-950 to-slate-900">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 py-14">
            <div class="flex items-center gap-2 mb-4">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-500/20 px-3 py-1 text-xs font-semibold text-brand-300 ring-1 ring-brand-500/30">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    v1 · Stable
                </span>
            </div>
            <h1 class="text-4xl sm:text-5xl font-bold tracking-tight text-white">API Reference</h1>
            <p class="mt-4 text-lg text-slate-400 max-w-2xl">Integrate UPI payments into your app in minutes. Create orders, redirect customers, and receive real-time webhooks.</p>
            <div class="mt-6 inline-flex items-center gap-3 rounded-xl bg-white/5 ring-1 ring-white/10 px-4 py-3">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Base URL</span>
                <code class="font-mono text-sm text-slate-200">{{ $base }}</code>
                <x-copy :text="$base" />
            </div>
        </div>
    </div>

    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-10 lg:py-14 grid lg:grid-cols-[240px_1fr] gap-12">

        {{-- Sidebar --}}
        <aside class="hidden lg:block">
            <nav class="sticky top-24 space-y-6 text-sm" id="docs-nav">
                @foreach ([
                    'Getting Started' => ['intro' => 'Introduction', 'auth' => 'Live authentication', 'sandbox' => 'Sandbox authentication'],
                    'Orders' => ['create' => 'Create payment order', 'status' => 'Check order status', 'object' => 'Response fields'],
                    'UPI Accounts' => ['accounts' => 'List accounts', 'rotation' => 'Rotation & limits'],
                    'Webhooks' => ['webhooks' => 'Events', 'verify' => 'Verify signature'],
                    'Reference' => ['errors' => 'Error codes', 'sdks' => 'Code examples'],
                ] as $group => $links)
                    <div>
                        <div class="text-[11px] font-bold uppercase tracking-widest text-slate-400 mb-2 px-3">{{ $group }}</div>
                        <ul class="space-y-0.5">
                            @foreach ($links as $id => $label)
                                <li>
                                    <a href="#{{ $id }}" class="nav-link -ml-px flex items-center gap-2 border-l-2 border-transparent pl-3 py-1.5 text-slate-500 hover:border-slate-300 hover:text-slate-900 transition-colors rounded-r-md hover:bg-slate-50">
                                        {{ $label }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </nav>
        </aside>

        {{-- Content --}}
        <article class="min-w-0 max-w-3xl space-y-20 text-[15px] leading-relaxed text-slate-700">

            <nav class="lg:hidden -mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3" aria-label="Documentation sections">
                <label for="docs-mobile-nav" class="block text-xs font-bold uppercase tracking-wider text-slate-500">On this page</label>
                <select id="docs-mobile-nav" class="mt-2 w-full rounded-lg ring-1 ring-inset ring-slate-300 bg-white text-sm px-3 py-2">
                    @foreach (['intro' => 'Introduction', 'auth' => 'Live API authentication', 'sandbox' => 'Sandbox API authentication', 'create' => 'Create payment order', 'status' => 'Check order status', 'object' => 'API response fields', 'webhooks' => 'Webhook integration', 'verify' => 'Webhook signature verification', 'errors' => 'Error codes', 'sdks' => 'Code examples'] as $id => $label)
                        <option value="#{{ $id }}">{{ $label }}</option>
                    @endforeach
                </select>
            </nav>

            {{-- Introduction --}}
            <section id="intro" class="section-anchor">
                <div class="flex items-center gap-2 mb-3">
                    <span class="text-xs font-bold uppercase tracking-widest text-brand-600">Getting Started</span>
                </div>
                <h2 class="text-3xl font-bold tracking-tight text-slate-900">Introduction</h2>
                <p class="mt-4 text-slate-600">The {{ config('app.name') }} API lets you create UPI payment orders, redirect customers to a hosted checkout, and receive signed webhooks when payments complete. All API calls are server-to-server — never expose your secret in a browser or mobile app.</p>

                <div class="mt-6 grid sm:grid-cols-3 gap-4">
                    @foreach ([
                        ['lock', 'Secure', 'HMAC-SHA256 signed webhooks. Credentials encrypted at rest.'],
                        ['bolt', 'Real-time', 'Instant payment confirmation via polling or webhooks.'],
                        ['refresh', 'Auto-rotation', 'Distribute load across multiple UPI accounts automatically.'],
                    ] as [$icon, $title, $desc])
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div class="mb-3 grid h-9 w-9 place-items-center rounded-lg bg-brand-50 text-brand-600"><x-icon :name="$icon" class="w-5 h-5" /></div>
                            <div class="font-semibold text-slate-900 text-sm">{{ $title }}</div>
                            <p class="mt-1 text-xs text-slate-500 leading-relaxed">{{ $desc }}</p>
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- Authentication --}}
            <section id="auth" class="section-anchor">
                <h2 class="text-2xl font-bold tracking-tight text-slate-900">Live API authentication</h2>
                <p class="mt-3 text-slate-600">Every Live request must include your Live API key and secret as headers. Find them in <span class="font-semibold text-slate-800">Dashboard → API &amp; Webhooks</span>. Credentials select the environment; do not send an environment field.</p>

                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 flex gap-3">
                    <x-icon name="alert" class="mt-0.5 w-5 h-5 shrink-0 text-amber-600" />
                    <p class="text-sm text-amber-800">Never expose your <code class="kbd">X-Api-Secret</code> in client-side code, mobile apps, or public repositories.</p>
                </div>

                @include('docs._code', ['title' => 'Live request headers', 'lang' => 'http', 'code' => "X-Api-Key: key_live_placeholder
X-Api-Secret: sec_live_placeholder
Accept: application/json
Content-Type: application/json"])
            </section>

            {{-- Sandbox --}}
            <section id="sandbox" class="section-anchor">
                <div class="inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-xs font-bold tracking-wide text-amber-800">SANDBOX · TEST ONLY</div>
                <h2 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">Sandbox API authentication</h2>
                <p class="mt-3 text-slate-600">Generate separate Sandbox credentials under <span class="font-semibold">Dashboard → API &amp; Webhooks</span>. Send the same API requests using the <code class="kbd">test_key_…</code> and <code class="kbd">test_sec_…</code> values. The credential determines the environment; request parameters cannot switch it.</p>
                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    Sandbox orders use an internal TEST checkout and never contact a payment provider. Their payment URLs begin with <code class="kbd">/sandbox/pay/</code>, and webhook payloads include <code class="kbd">"environment": "sandbox"</code> and <code class="kbd">"test": true</code>.
                </div>
                @include('docs._code', ['title' => 'Sandbox request headers', 'lang' => 'http', 'code' => "X-Api-Key: test_key_placeholder
X-Api-Secret: test_sec_placeholder
Accept: application/json
Content-Type: application/json"])
                <h3 class="mt-6 text-lg font-semibold text-slate-900">Testing guide</h3>
                <ol class="mt-3 list-decimal space-y-2 pl-5 text-slate-600">
                    <li>Generate Sandbox credentials and configure a separate Sandbox webhook URL in Settings.</li>
                    <li>Create an order with the Sandbox headers. Do not send <code class="kbd">payment_account_id</code>.</li>
                    <li>Open the returned TEST <code class="kbd">payment_url</code> and choose success, failure, pending, expired, timeout, or invalid reference.</li>
                    <li>Check the order with the same Sandbox credentials and verify the Sandbox webhook with the Sandbox secret.</li>
                </ol>
                <p class="mt-3 text-sm text-slate-500">Each TEST order can be simulated once. Timeout returns HTTP 504; invalid reference and repeat attempts return HTTP 422. Pending remains pending but is still consumed as that order's one simulation.</p>
            </section>

            {{-- Create Order --}}
            <section id="create" class="section-anchor">
                <div class="flex items-center gap-3 mb-1">
                    <span class="rounded-md bg-emerald-100 px-2.5 py-1 font-mono text-xs font-bold text-emerald-700 tracking-wide">POST</span>
                    <code class="text-sm text-slate-500 font-mono">/api/v1/orders</code>
                </div>
                <h2 class="text-2xl font-bold tracking-tight text-slate-900">Create payment order</h2>
                <p class="mt-3 text-slate-600">Creates a pending order and returns a hosted <code class="kbd">payment_url</code>. Live credentials use Live payment processing; Sandbox credentials return the internal TEST checkout. The server ignores client attempts to select an environment.</p>

                <div class="mt-6 rounded-xl border border-slate-200 overflow-hidden">
                    <div class="bg-slate-50 px-5 py-3 border-b border-slate-200">
                        <span class="text-xs font-bold uppercase tracking-widest text-slate-500">Request body</span>
                    </div>
                    <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100 text-sm">
                        <thead class="bg-white"><tr>
                            <th class="th w-44">Parameter</th>
                            <th class="th w-20">Type</th>
                            <th class="th">Description</th>
                        </tr></thead>
                        <tbody class="divide-y divide-slate-50">
                            @foreach ([
                                ['amount', 'number', true, 'Amount in INR (₹1 – ₹1,00,000). Up to 2 decimal places.'],
                                ['client_order_id', 'string', true, 'Your unique order ID. Allowed: A–Z, 0–9, _ and -. Max 64 chars.'],
                                ['redirect_url', 'HTTPS URL', false, 'Max 500 chars. Its host must be in your Settings allowlist.'],
                                ['customer_name', 'string', false, 'Max 100 chars.'],
                                ['customer_email', 'email', false, 'Valid email, max 255 chars.'],
                                ['customer_mobile', 'string', false, 'Max 20 chars.'],
                                ['note', 'string', false, 'Shown in the customer\'s UPI app. Max 50 chars.'],
                                ['payment_account_id', 'integer', false, 'Live only. Pin to one of your accounts; prohibited in Sandbox.'],
                            ] as [$name, $type, $required, $desc])
                                <tr class="hover:bg-slate-50/50">
                                    <td class="td align-top">
                                        <code class="font-mono text-[13px] font-semibold text-slate-900">{{ $name }}</code>
                                        @if ($required)
                                            <span class="ml-1.5 text-[10px] font-bold uppercase tracking-wide text-red-500">required</span>
                                        @else
                                            <span class="ml-1.5 text-[10px] font-medium uppercase tracking-wide text-slate-400">optional</span>
                                        @endif
                                    </td>
                                    <td class="td align-top font-mono text-xs text-slate-400">{{ $type }}</td>
                                    <td class="td align-top text-slate-600 text-[13px]">{{ $desc }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                </div>

                @include('docs._code', ['title' => 'cURL', 'lang' => 'bash', 'code' => "curl -X POST {$exampleBase}/orders \\
  -H \"X-Api-Key: key_live_placeholder\" \\
  -H \"X-Api-Secret: sec_live_placeholder\" \\
  -H \"Accept: application/json\" \\
  -H \"Content-Type: application/json\" \\
  -d '{\"amount\": 199, \"client_order_id\": \"INV1001\", \"redirect_url\": \"https://yoursite.com/thanks\"}'"])

                @include('docs._code', ['title' => 'Response · 201 Created', 'lang' => 'json', 'code' => '{
  "success": true,
  "data": {
    "order_id": "ORDAB12CD34EF56GH78",
    "client_order_id": "INV1001",
    "amount": "199.00",
    "currency": "INR",
    "status": "pending",
    "environment": "live",
    "test": false,
    "utr": null,
    "submitted_utr": null,
    "payment_url": "https://gateway.example.com/pay/ORDAB12CD34EF56GH78",
    "upi_intent": "upi://pay?pa=merchant@upi&am=199.00&tr=ORDAB12CD34EF56GH78",
    "customer": {"name": null, "email": null, "mobile": null},
    "paid_at": null,
    "expires_at": "2026-11-07T12:15:00+05:30",
    "created_at": "2026-11-07T12:00:00+05:30"
  }
}'])
            </section>

            {{-- Get Status --}}
            <section id="status" class="section-anchor">
                <div class="flex items-center gap-3 mb-1">
                    <span class="rounded-md bg-sky-100 px-2.5 py-1 font-mono text-xs font-bold text-sky-700 tracking-wide">GET</span>
                    <code class="text-sm text-slate-500 font-mono">/api/v1/orders/{order_id | client_order_id}</code>
                </div>
                <h2 class="text-2xl font-bold tracking-tight text-slate-900">Check order status</h2>
                <p class="mt-3 text-slate-600">Accepts either <code class="kbd">order_id</code> or <code class="kbd">client_order_id</code>. Live credentials may check a pending provider payment; Sandbox credentials only read simulated state and never contact a provider. Orders are visible only with credentials for the same merchant and environment. <span class="font-semibold text-slate-800">Fulfil only after this endpoint or a verified webhook reports <code class="kbd">paid</code></span>.</p>

                @include('docs._code', ['title' => 'cURL', 'lang' => 'bash', 'code' => "curl {$exampleBase}/orders/INV1001 \\
  -H \"X-Api-Key: key_live_placeholder\" \\
  -H \"X-Api-Secret: sec_live_placeholder\" \\
  -H \"Accept: application/json\""])

                @include('docs._code', ['title' => 'Response · 200 OK', 'lang' => 'json', 'code' => '{
  "success": true,
  "data": {
    "order_id": "ORDAB12CD34EF56GH78",
    "client_order_id": "INV1001",
    "amount": "199.00",
    "currency": "INR",
    "status": "paid",
    "environment": "live",
    "test": false,
    "utr": "427123456789",
    "submitted_utr": null,
    "payment_url": "https://gateway.example.com/pay/ORDAB12CD34EF56GH78",
    "upi_intent": "upi://pay?pa=merchant@upi&am=199.00&tr=ORDAB12CD34EF56GH78",
    "customer": {"name": null, "email": null, "mobile": null},
    "paid_at": "2026-11-07T12:10:00+05:30",
    "expires_at": "2026-11-07T12:15:00+05:30",
    "created_at": "2026-11-07T12:00:00+05:30"
  }
}'])
            </section>

            {{-- Order Object --}}
            <section id="object" class="section-anchor">
                <h2 class="text-2xl font-bold tracking-tight text-slate-900">API response fields</h2>
                <p class="mt-3 text-slate-600">An order moves through these statuses:</p>
                <div class="mt-4 grid sm:grid-cols-2 gap-3">
                    @foreach ([
                        'pending' => ['Waiting for the customer to pay until expires_at.', 'bg-amber-50 border-amber-200'],
                        'paid'    => ['Payment confirmed. The utr field contains the bank reference.', 'bg-emerald-50 border-emerald-200'],
                        'review'  => ['A customer-submitted UTR is awaiting merchant review (Live only).', 'bg-blue-50 border-blue-200'],
                        'failed'  => ['The TEST payment was simulated as failed (Sandbox only).', 'bg-red-50 border-red-200'],
                        'expired' => ['Order was not paid before expires_at.', 'bg-slate-50 border-slate-200'],
                    ] as $s => [$d, $cls])
                        <div class="rounded-xl border {{ $cls }} p-4">
                            <x-status :status="$s" />
                            <p class="mt-2 text-xs text-slate-600 leading-relaxed">{{ $d }}</p>
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- List Accounts --}}
            <section id="accounts" class="section-anchor">
                <div class="flex items-center gap-3 mb-1">
                    <span class="rounded-md bg-sky-100 px-2.5 py-1 font-mono text-xs font-bold text-sky-700 tracking-wide">GET</span>
                    <code class="text-sm text-slate-500 font-mono">/api/v1/accounts</code>
                </div>
                <h2 class="text-2xl font-bold tracking-tight text-slate-900">List UPI accounts</h2>
                <p class="mt-3 text-slate-600">Returns all of your Live UPI accounts and their capacity. Sandbox credentials return <code class="kbd">environment: sandbox</code>, a null rotation mode, and an empty data array so Live account details never enter the TEST path.</p>

                @include('docs._code', ['title' => 'Response · 200 OK', 'lang' => 'json', 'code' => '{
  "success": true,
  "environment": "live",
  "rotation_mode": "random",
  "data": [
    {
      "id": 3,
      "label": "Shop 1",
      "upi_id": "merchant@paytm",
      "provider": "paytm_business",
      "is_active": true,
      "is_default": true,
      "daily_limit": "100000.00",
      "used_today": "42500.00",
      "remaining_today": "57500.00"
    }
  ]
}'])
            </section>

            {{-- Rotation --}}
            <section id="rotation" class="section-anchor">
                <h2 class="text-2xl font-bold tracking-tight text-slate-900">Rotation &amp; daily limits</h2>
                <p class="mt-3 text-slate-600">When you omit <code class="kbd">payment_account_id</code>, the gateway automatically picks an account based on your rotation mode:</p>
                <div class="mt-4 space-y-3">
                    <div class="flex gap-3 rounded-xl border border-slate-200 p-4">
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-brand-50 text-brand-600 shrink-0"><x-icon name="refresh" class="w-4 h-4" /></span>
                        <div><div class="font-semibold text-slate-900 text-sm">Random rotation <span class="ml-1 text-xs font-normal text-slate-400">(default)</span></div><p class="mt-0.5 text-sm text-slate-600">A random account among those with remaining daily capacity.</p></div>
                    </div>
                    <div class="flex gap-3 rounded-xl border border-slate-200 p-4">
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-amber-50 text-amber-600 shrink-0"><x-icon name="star" class="w-4 h-4" /></span>
                        <div><div class="font-semibold text-slate-900 text-sm">Default account first</div><p class="mt-0.5 text-sm text-slate-600">Your default account is used until it hits its daily limit, then falls back to others.</p></div>
                    </div>
                </div>
                <p class="mt-4 text-slate-600">Each account has a daily limit (₹1,00,000 by default). Usage includes paid orders from today plus open pending orders. If no account has capacity, the API returns <code class="kbd">422</code> with an <code class="kbd">amount</code> error.</p>
            </section>

            {{-- Webhooks --}}
            <section id="webhooks" class="section-anchor">
                <h2 class="text-2xl font-bold tracking-tight text-slate-900">Webhook events</h2>
                <p class="mt-3 text-slate-600">Configure separate Live and Sandbox HTTPS endpoints in <span class="font-semibold text-slate-800">Dashboard → API &amp; Webhooks</span>. We send JSON with <code class="kbd">X-Signature</code> and <code class="kbd">X-Event</code> headers. Respond with any <code class="kbd">2xx</code> status; failed deliveries are retried up to 6 attempts with backoff.</p>

                <div class="mt-4 rounded-xl border border-slate-200 overflow-hidden">
                    <div class="bg-slate-50 px-5 py-3 border-b border-slate-200">
                        <span class="text-xs font-bold uppercase tracking-widest text-slate-500">Events</span>
                    </div>
                    <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100 text-sm">
                        <tbody class="divide-y divide-slate-50">
                            @foreach ([
                                ['order.paid', 'emerald', 'Payment confirmed. Safe to fulfil the order.'],
                                ['order.expired', 'slate', 'Order was not paid before it expired.'],
                                ['order.under_review', 'blue', 'A Live UTR submission needs merchant review.'],
                                ['order.failed', 'red', 'Sandbox failure simulation.'],
                                ['order.pending', 'amber', 'Sandbox pending simulation.'],
                                ['order.timeout', 'orange', 'Sandbox provider-timeout simulation.'],
                            ] as [$event, $color, $desc])
                                <tr class="hover:bg-slate-50/50">
                                    <td class="td w-44"><code class="font-mono text-[13px] font-semibold text-slate-900">{{ $event }}</code></td>
                                    <td class="td text-slate-600 text-[13px]">{{ $desc }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                </div>

                <div class="mt-4 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 flex gap-3">
                    <x-icon name="alert" class="mt-0.5 w-5 h-5 shrink-0 text-blue-600" />
                    <p class="text-sm text-blue-800">Delivery is at least once and may be out of order. After signature verification, deduplicate the signed <code class="kbd">event_id</code> (also sent as <code class="kbd">X-Event-Id</code>) transactionally. Use <code class="kbd">event_sequence</code> per order to prevent older events from reverting newer state, fulfill once, and confirm status, environment, amount and client_order_id. Queue insertion is not merchant receipt.</p>
                </div>

                @include('docs._code', ['title' => 'Payload · order.paid', 'lang' => 'json', 'code' => '{
  "event": "order.paid",
  "event_id": "00000000-0000-4000-8000-000000000001",
  "event_sequence": 2,
  "occurred_at": "2026-11-07T12:10:00+05:30",
  "data": {
    "order_id": "ORDAB12CD34EF56GH78",
    "client_order_id": "INV1001",
    "amount": "199.00",
    "currency": "INR",
    "status": "paid",
    "environment": "live",
    "test": false,
    "utr": "4271234567890",
    "submitted_utr": null,
    "payment_url": "https://gateway.example.com/pay/ORDAB12CD34EF56GH78",
    "upi_intent": "upi://pay?pa=merchant@upi&am=199.00&tr=ORDAB12CD34EF56GH78",
    "customer": {"name": null, "email": null, "mobile": null},
    "paid_at": "2026-11-07T12:10:00+05:30",
    "expires_at": "2026-11-07T12:15:00+05:30",
    "created_at": "2026-11-07T12:00:00+05:30"
  }
}'])

                <p class="mt-4 text-sm text-slate-500">Every event uses the complete order object shown above. Sandbox payloads carry <code class="kbd">environment: sandbox</code> and <code class="kbd">test: true</code> and are sent only to the Sandbox URL.</p>
            </section>

            {{-- Verify Signature --}}
            <section id="verify" class="section-anchor">
                <h2 class="text-2xl font-bold tracking-tight text-slate-900">Verify signature &amp; replay protection</h2>
                <p class="mt-3 text-slate-600">Every webhook request includes an <code class="kbd">X-Signature</code> header in the format <code class="kbd">t={timestamp},v1={hmac}</code>, signed with your dedicated Webhook Signing Secret (available in <span class="font-semibold text-slate-800">Dashboard → API &amp; Webhooks</span>). The signature is computed as <code class="kbd">HMAC-SHA256(timestamp + "." + raw_body, secret)</code>.</p>
                <div class="mt-3 text-sm text-slate-600 space-y-1">
                    <p><strong>1. Five-minute timestamp tolerance:</strong> Reject any webhook where <code class="kbd">|current_time - timestamp| &gt; 300</code> to prevent replay attacks.</p>
                    <p><strong>2. Transactional event deduplication:</strong> Check that the signed <code class="kbd">event_id</code> (also passed in <code class="kbd">X-Event-Id</code>) has not been processed previously before fulfilling orders.</p>
                    <p><strong>3. Secret fallback &amp; merchant migration deadline:</strong> If you have not yet generated a dedicated webhook secret, your API secret is used as a fallback (always signed with timestamp replay defense). Legacy body-only signatures (<code class="kbd">X-Signature-Legacy</code>) are now completely retired and are neither sent nor accepted. March 31, 2027 is the merchant migration deadline for all endpoints to verify timestamped <code class="kbd">X-Signature</code>.</p>
                </div>

                @php
                $phpVerifyCode = implode("\n", [
                    '<?php',
                    '$body      = file_get_contents("php://input");',
                    '$secret    = getenv("PAYMENT_WEBHOOK_SECRET");',
                    '$header    = $_SERVER["HTTP_X_SIGNATURE"] ?? "";',
                    '',
                    '// Parse t=timestamp,v1=signature',
                    '$params = [];',
                    'foreach (explode(",", $header) as $pair) {',
                    '    $p = explode("=", trim($pair), 2);',
                    '    if (count($p) === 2) { $params[trim($p[0])] = trim($p[1]); }',
                    '}',
                    '',
                    '$timestamp = isset($params["t"]) ? (int) $params["t"] : 0;',
                    '$signature = $params["v1"] ?? "";',
                    '',
                    '// 1. Replay defense: 5-minute tolerance (300 seconds)',
                    'if (abs(time() - $timestamp) > 300) {',
                    '    http_response_code(401);',
                    '    exit("Webhook timestamp expired or invalid");',
                    '}',
                    '',
                    '// 2. Verify signature: HMAC-SHA256 of timestamp + "." + body',
                    '$expected = hash_hmac("sha256", "{$timestamp}.{$body}", $secret);',
                    'if (!hash_equals($expected, $signature)) {',
                    '    http_response_code(401);',
                    '    exit("Invalid signature");',
                    '}',
                    '',
                    '// 3. Deduplicate event transactionally and process',
                    '$event = json_decode($body, true);',
                    '// Check your database: if event_id already processed, return 200 OK without double-fulfilling.',
                    'if ($event["event"] === "order.paid") {',
                    '    // Fulfil $event["data"]["client_order_id"] idempotently',
                    '}',
                    'http_response_code(200);',
                ]);
                @endphp
                @include('docs._code', ['title' => 'PHP', 'lang' => 'php', 'code' => $phpVerifyCode])

                @php
                $nodeCode = implode("\n", [
                    'const crypto = require("crypto");',
                    '',
                    'app.post("/webhook", express.raw({ type: "application/json" }), (req, res) => {',
                    '  const header = req.get("X-Signature") || "";',
                    '  const secret = process.env.PAYMENT_WEBHOOK_SECRET;',
                    '',
                    '  // Parse t=timestamp,v1=signature',
                    '  const params = Object.fromEntries(header.split(",").map(p => p.split("=")));',
                    '  const timestamp = parseInt(params.t, 10);',
                    '  const signature = params.v1 || "";',
                    '',
                    '  // 1. Replay defense: 5-minute tolerance (300 seconds)',
                    '  const now = Math.floor(Date.now() / 1000);',
                    '  if (isNaN(timestamp) || Math.abs(now - timestamp) > 300) {',
                    '    return res.status(401).send("Webhook timestamp expired or invalid");',
                    '  }',
                    '',
                    '  // 2. Verify signature',
                    '  const expected = crypto',
                    '    .createHmac("sha256", secret)',
                    '    .update(`${timestamp}.${req.body}`)',
                    '    .digest("hex");',
                    '',
                    '  if (signature.length !== expected.length || !crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(signature))) {',
                    '    return res.status(401).send("Invalid signature");',
                    '  }',
                    '',
                    '  // 3. Deduplicate event_id and process',
                    '  const event = JSON.parse(req.body);',
                    '  if (event.event === "order.paid") {',
                    '    // Fulfil event.data.client_order_id (idempotently)',
                    '  }',
                    '  res.sendStatus(200);',
                    '});',
                ]);
                @endphp
                @include('docs._code', ['title' => 'Node.js', 'lang' => 'javascript', 'code' => $nodeCode])
            </section>

            {{-- Errors --}}
            <section id="errors" class="section-anchor">
                <h2 class="text-2xl font-bold tracking-tight text-slate-900">Error codes</h2>
                <p class="mt-3 text-slate-600">All errors return a JSON body with a <code class="kbd">message</code> field. Validation errors (<code class="kbd">422</code>) also include an <code class="kbd">errors</code> object keyed by field name.</p>
                @include('docs._code', ['title' => 'Validation error · 422', 'lang' => 'json', 'code' => '{
  "message": "The amount field must be at least 1.",
  "errors": {
    "amount": ["The amount field must be at least 1."]
  }
}'])
                <div class="mt-4 rounded-xl border border-slate-200 overflow-hidden">
                    <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100 text-sm">
                        <thead class="bg-slate-50"><tr>
                            <th class="th w-24">Status</th>
                            <th class="th">Meaning</th>
                        </tr></thead>
                        <tbody class="divide-y divide-slate-50">
                            @foreach ([
                                ['401', 'red',    'Missing or invalid API key / secret.'],
                                ['403', 'orange', 'Merchant account is disabled.'],
                                ['404', 'slate',  'Order not found.'],
                                ['422', 'amber',  'Validation failed — see the errors object for field-level details.'],
                                ['429', 'purple', 'Rate limit exceeded (120 requests / minute).'],
                                ['504', 'orange', 'Sandbox checkout simulated a provider timeout.'],
                                ['500', 'red',    'Unexpected server error. Contact support if this persists.'],
                            ] as [$code, $color, $meaning])
                                <tr class="hover:bg-slate-50/50">
                                    <td class="td"><code class="font-mono font-bold text-slate-900">{{ $code }}</code></td>
                                    <td class="td text-slate-600 text-[13px]">{{ $meaning }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                </div>
            </section>

            {{-- SDKs / Code Examples --}}
            <section id="sdks" class="section-anchor">
                <h2 class="text-2xl font-bold tracking-tight text-slate-900">Code examples</h2>
                <p class="mt-3 text-slate-600">Quick-start snippets for common server-side languages.</p>

                @php
                $nodeCreateCode = implode("\n", [
                    'const response = await fetch("'.$exampleBase.'/orders", {',
                    '  method: "POST",',
                    '  headers: {',
                    '    "X-Api-Key": process.env.PAYMENT_API_KEY,',
                    '    "X-Api-Secret": process.env.PAYMENT_API_SECRET,',
                    '    "Accept": "application/json",',
                    '    "Content-Type": "application/json",',
                    '  },',
                    '  body: JSON.stringify({ amount: 199, client_order_id: "INV1001" }),',
                    '});',
                    'if (!response.ok) throw new Error(`API error ${response.status}`);',
                    'const { data } = await response.json();',
                    'console.log(data.payment_url);',
                ]);
                @endphp
                @include('docs._code', ['title' => 'Node.js (create order)', 'lang' => 'javascript', 'code' => $nodeCreateCode])

                @php
                $guzzleCode = implode("\n", [
                    '<?php',
                    '$client = new GuzzleHttp\\Client(["base_uri" => "' . $exampleBase . '"]);',
                    '',
                    '$res = $client->post("/orders", [',
                    '    "headers" => [',
                    '        "X-Api-Key"    => getenv("PAYMENT_API_KEY"),',
                    '        "X-Api-Secret" => getenv("PAYMENT_API_SECRET"),',
                    '        "Accept"       => "application/json",',
                    '    ],',
                    '    "json" => ["amount" => 199, "client_order_id" => "INV1001"],',
                    ']);',
                    '',
                    '$data = json_decode($res->getBody(), true);',
                    '$paymentUrl = $data["data"]["payment_url"];',
                ]);
                @endphp
                @include('docs._code', ['title' => 'PHP (Guzzle)', 'lang' => 'php', 'code' => $guzzleCode])
            </section>

        </article>
    </div>
</div>

@push('scripts')
<script nonce="{{ $csp_nonce }}">
    document.getElementById('docs-mobile-nav')?.addEventListener('change', event => {
        window.location.hash = event.target.value;
    });

    // Active nav link on scroll
    const sections = document.querySelectorAll('.section-anchor');
    const links = document.querySelectorAll('#docs-nav .nav-link');
    const observer = new IntersectionObserver(entries => {
        entries.forEach(e => {
            if (e.isIntersecting) {
                links.forEach(l => l.classList.remove('active'));
                const active = document.querySelector(`#docs-nav a[href="#${e.target.id}"]`);
                if (active) active.classList.add('active');
            }
        });
    }, { rootMargin: '-20% 0px -70% 0px' });
    sections.forEach(s => observer.observe(s));
</script>
@endpush
@endsection
