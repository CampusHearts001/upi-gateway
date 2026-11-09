@extends('install.layout')
@php $step = 5; @endphp

@section('content')
@include('install.hosting-check', ['deploymentUrl' => config('app.url'), 'finalCheck' => true, 'serverReady' => $serverReady])
<div class="text-center py-4">
    <div class="mx-auto w-20 h-20 rounded-full bg-emerald-500 flex items-center justify-center shadow-lg shadow-emerald-500/30">
        <svg viewBox="0 0 24 24" class="w-10 h-10" fill="none" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
            <path d="m5 12.5 4.5 4.5L19 7.5"/>
        </svg>
    </div>

    <h1 class="mt-6 text-2xl font-bold text-slate-900">Installation Complete</h1>
    <p class="mt-2 text-slate-500">Your account and database are ready.</p>

    <div class="mt-8 rounded-xl bg-slate-50 ring-1 ring-slate-200 divide-y divide-slate-200 text-sm text-left">
        <div class="flex items-center gap-3 px-4 py-3">
            <x-icon name="check-circle" class="w-5 h-5 text-emerald-600" />
            <span class="text-slate-700">Database configured & migrated</span>
        </div>
        <div class="flex items-center gap-3 px-4 py-3">
            <x-icon name="check-circle" class="w-5 h-5 text-emerald-600" />
            <span class="text-slate-700">Admin account created</span>
        </div>
        <div class="flex items-center gap-3 px-4 py-3">
            <x-icon name="check-circle" class="w-5 h-5 text-emerald-600" />
            <span class="text-slate-700">Application ready</span>
        </div>
    </div>

    <div class="mt-8 p-4 rounded-xl bg-emerald-50 ring-1 ring-emerald-200 text-sm text-emerald-800 text-left">
        Installer access is now disabled. Sign in with the administrator account you just created.
    </div>

    <a hidden data-deployment-continue data-deployment-href="{{ route('login') }}" aria-disabled="true" class="btn btn-primary w-full justify-center py-2.5 mt-6 text-base">
        Go to Login <x-icon name="arrow-right" class="w-4 h-4" />
    </a>
</div>
@endsection
