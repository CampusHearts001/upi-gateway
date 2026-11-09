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
</head>
<body class="h-full bg-slate-50 text-slate-800 antialiased font-sans">

<div class="min-h-full flex flex-col items-center justify-center px-4 py-8 sm:py-12">

    {{-- Logo --}}
    <div class="mb-8 flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-brand-600 flex items-center justify-center">
            <svg viewBox="0 0 32 32" class="w-6 h-6"><path d="M18 5 8 18h7l-1 9 10-13h-7z" fill="white"/></svg>
        </div>
        <span class="text-xl font-bold text-slate-900">{{ config('app.name', 'UPI Gateway') }}</span>
    </div>

    {{-- Steps --}}
    <div class="mb-8 flex w-full max-w-lg items-start justify-between gap-1 overflow-x-auto pb-1">
        @foreach ([1 => 'Ready', 2 => 'Database', 3 => 'Set up', 4 => 'Account'] as $n => $label)
            <div class="flex items-center gap-2">
                <div @class([
                    'w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold',
                    'bg-brand-600 text-white' => $step === $n,
                    'bg-emerald-500 text-white' => $step > $n,
                    'bg-slate-200 text-slate-500' => $step < $n,
                ])>
                    @if($step > $n)<x-icon name="check" class="w-4 h-4" />@else {{ $n }} @endif
                </div>
                <span @class(['hidden text-xs font-medium sm:inline', 'text-brand-600' => $step === $n, 'text-slate-400' => $step !== $n])>{{ $label }}</span>
            </div>
            @if($n < 4)<div class="mt-3.5 h-px min-w-4 flex-1 bg-slate-300 sm:min-w-8"></div>@endif
        @endforeach
    </div>

    {{-- Card --}}
    <div class="w-full max-w-lg rounded-2xl bg-white p-5 shadow-card ring-1 ring-slate-200 sm:p-8">
        @yield('content')
    </div>

</div>

@stack('scripts')
</body>
</html>
