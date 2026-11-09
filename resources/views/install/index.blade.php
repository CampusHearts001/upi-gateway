@extends('install.layout')
@php $step = 1; @endphp

@section('content')
<div class="text-center">
    <p class="installer-kicker">Step 1 · Readiness</p>
    <h1 class="mt-2 text-2xl font-bold text-slate-950 sm:text-3xl">Let’s get you set up</h1>
    <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">We’ll quickly verify that your server is ready for a secure installation.</p>
</div>

@include('install.hosting-check', [
    'deploymentUrl' => $diagnostics['detected_app_url'],
    'serverReady' => $allPassed,
])

<div class="mt-6 border-t border-slate-100 pt-6">
    <a hidden data-deployment-continue data-deployment-href="{{ route('install.database') }}" aria-disabled="true" class="btn btn-primary w-full justify-center py-2.5">
        Next <x-icon name="arrow-right" class="w-4 h-4" />
    </a>
</div>
@endsection
