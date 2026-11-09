<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install · {{ config('app.name', 'UPI Gateway') }}</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%234f46e5'/><path d='M18 5 8 18h7l-1 9 10-13h-7z' fill='white'/></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style nonce="{{ $csp_nonce ?? '' }}">
        :root { color-scheme: light; }
        .installer-page {
            position: relative;
            isolation: isolate;
            min-height: 100%;
            overflow-x: hidden;
            background:
                radial-gradient(circle at 50% -12rem, rgba(99, 102, 241, .11), transparent 32rem),
                #f6f7fb;
        }
        .installer-page::before {
            content: '';
            position: fixed;
            inset: 0 0 auto;
            z-index: -1;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(99,102,241,.28), transparent);
        }
        .installer-shell { width: 100%; max-width: 40rem; }
        .installer-brand-mark {
            box-shadow: 0 10px 25px rgba(79, 70, 229, .24), inset 0 1px rgba(255,255,255,.25);
        }
        .installer-progress {
            border: 1px solid #e2e8f0;
            background: rgba(255,255,255,.82);
            box-shadow: 0 4px 16px rgba(15, 23, 42, .035);
        }
        .installer-step-dot { transition: transform .2s ease, box-shadow .2s ease; }
        .installer-step-dot.is-current { box-shadow: 0 0 0 4px rgba(99,102,241,.14); transform: scale(1.04); }
        .installer-card {
            border: 1px solid #e2e8f0;
            background: #fff;
            box-shadow: 0 22px 55px rgba(15, 23, 42, .08), 0 2px 6px rgba(15, 23, 42, .035);
        }
        .installer-card h1 { letter-spacing: -.035em; }
        .installer-card .label { margin-bottom: .45rem; color: #334155; font-size: .8125rem; font-weight: 600; letter-spacing: -.005em; }
        .installer-card .input {
            min-height: 2.875rem;
            border-radius: .75rem;
            border-color: #d5dce6;
            background: #fdfefe;
            box-shadow: 0 1px 2px rgba(15,23,42,.03);
            transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
        }
        .installer-card .input:hover { border-color: #a8b3c3; }
        .installer-card .input:focus { border-color: #6366f1; background: #fff; box-shadow: 0 0 0 4px rgba(99,102,241,.1); }
        .installer-card .input::placeholder { color: #94a3b8; }
        .installer-card .btn { min-height: 2.875rem; border-radius: .75rem; font-weight: 600; transition: transform .15s ease, box-shadow .15s ease, background-color .15s ease, border-color .15s ease, opacity .15s ease; }
        .installer-card .btn-primary { box-shadow: 0 4px 12px rgba(79,70,229,.16); }
        .installer-card .btn-primary:not(:disabled):hover { transform: translateY(-1px); box-shadow: 0 8px 20px rgba(79,70,229,.22); }
        .installer-card .btn-secondary:not(:disabled):hover { border-color: #a8b3c3; background: #f8fafc; }
        .installer-card .btn:focus-visible { outline: 3px solid rgba(99,102,241,.2); outline-offset: 2px; }
        .installer-card .btn:disabled { cursor: not-allowed; opacity: .62; }
        .installer-kicker { color: #4f46e5; font-size: .7rem; font-weight: 700; letter-spacing: .11em; text-transform: uppercase; }
        .installer-note { border: 1px solid #e2e8f0; background: #f8fafc; border-radius: .875rem; }
        .installer-card [class*="bg-red-50"] { box-shadow: inset 3px 0 #ef4444; }
        .installer-card [class*="bg-emerald-50"] { box-shadow: inset 3px 0 #10b981; }
        .installer-card [class*="bg-amber-50"] { box-shadow: inset 3px 0 #f59e0b; }
        @media (max-width: 639px) {
            .installer-progress { margin-inline: -.25rem; }
            .installer-card { border-radius: 1.25rem; }
            .installer-step-label { display: none; }
            .installer-page { justify-content: flex-start; }
        }
        @media (prefers-reduced-motion: reduce) {
            .installer-card .btn, .installer-step-dot, .installer-readiness__spinner { transition: none !important; animation-duration: 1.5s !important; }
        }
    </style>
</head>
<body class="h-full text-slate-800 antialiased font-sans">

<div class="installer-page flex min-h-full flex-col items-center justify-center px-4 py-6 sm:px-6 sm:py-10 lg:py-12">
  <div class="installer-shell">

    {{-- Logo --}}
    <div class="mb-6 flex items-center justify-center gap-3 sm:mb-7">
        <div class="installer-brand-mark flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-500 to-brand-700">
            <svg viewBox="0 0 32 32" class="w-6 h-6"><path d="M18 5 8 18h7l-1 9 10-13h-7z" fill="white"/></svg>
        </div>
        <div>
            <span class="block text-lg font-bold tracking-tight text-slate-950 sm:text-xl">{{ config('app.name', 'UPI Gateway') }}</span>
            <span class="block text-xs font-medium text-slate-500">Secure installation wizard</span>
        </div>
    </div>

    {{-- Steps --}}
    <div class="installer-progress mb-5 flex w-full items-start justify-between gap-1 rounded-2xl px-3 py-3.5 sm:mb-6 sm:px-5 sm:py-4" aria-label="Installation progress">
        @foreach ([1 => 'Ready', 2 => 'Database', 3 => 'Set up', 4 => 'Account'] as $n => $label)
            <div class="flex shrink-0 items-center gap-2">
                <div @class([
                    'installer-step-dot w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold',
                    'is-current' => $step === $n,
                    'bg-brand-600 text-white' => $step === $n,
                    'bg-emerald-500 text-white' => $step > $n,
                    'bg-slate-100 text-slate-500 ring-1 ring-slate-200' => $step < $n,
                ]) @if($step === $n) aria-current="step" @endif>
                    @if($step > $n)<x-icon name="check" class="w-4 h-4" />@else {{ $n }} @endif
                </div>
                <span @class(['installer-step-label text-xs font-semibold', 'text-brand-700' => $step === $n, 'text-emerald-700' => $step > $n, 'text-slate-400' => $step < $n])>{{ $label }}</span>
            </div>
            @if($n < 4)<div @class(['mt-4 h-px min-w-3 flex-1 sm:min-w-7', 'bg-emerald-400' => $step > $n, 'bg-slate-200' => $step <= $n])></div>@endif
        @endforeach
    </div>

    {{-- Card --}}
    <div class="installer-card w-full rounded-3xl p-5 sm:p-8">
        @yield('content')
    </div>

    <p class="mt-4 text-center text-xs leading-5 text-slate-500 sm:mt-5">Your credentials remain on this server and are never sent to us.</p>

  </div>
</div>

@stack('scripts')
</body>
</html>
