@extends('install.layout')
@php $step = 3; @endphp

@section('content')
<p class="installer-kicker">Step 3 · Set up</p>
<h1 class="mt-2 text-2xl font-bold text-slate-950 sm:text-3xl">Prepare the application</h1>
<p class="mt-2 text-sm leading-6 text-slate-500">We’ll create the required database tables. This usually takes only a few seconds.</p>

<div id="output" class="mt-6 min-h-36 whitespace-pre-wrap rounded-2xl border border-slate-800 bg-slate-950 p-4 font-mono text-xs leading-6 text-slate-300 shadow-inner sm:p-5">
    Waiting to start...
</div>

<div id="error" class="hidden mt-4 rounded-xl bg-red-50 ring-1 ring-red-200 px-4 py-3.5 text-sm leading-6 text-red-700"></div>

<div class="mt-6 space-y-3">
    <button id="run-btn" class="btn btn-primary w-full justify-center py-2.5">
        Run Migrations
    </button>
    <a id="next-btn" href="{{ route('install.admin') }}" class="btn btn-primary w-full justify-center py-2.5 hidden">
        Continue to administrator <x-icon name="arrow-right" class="w-4 h-4" />
    </a>
</div>
@endsection

@push('scripts')
<script nonce="{{ $csp_nonce }}">
async function runMigrations() {
    const btn = document.getElementById('run-btn');
    const out = document.getElementById('output');
    const err = document.getElementById('error');
    btn.disabled = true;
    btn.textContent = 'Running...';
    out.textContent = 'Running migrations...\n';

    try {
        const res = await fetch('{{ route('install.migrate.run') }}', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
        });
        const data = await res.json();
        if (data.success) {
            out.textContent += data.output + '\nMigrations completed successfully.';
            btn.classList.add('hidden');
            document.getElementById('next-btn').classList.remove('hidden');
        } else {
            out.textContent += 'Migration failed.';
            err.textContent = data.message;
            err.classList.remove('hidden');
            btn.disabled = false;
            btn.textContent = 'Retry';
        }
    } catch (e) {
        err.textContent = 'Request failed: ' + e.message;
        err.classList.remove('hidden');
        btn.disabled = false;
        btn.textContent = 'Retry';
    }
}
document.getElementById('run-btn').addEventListener('click', runMigrations);
</script>
@endpush
