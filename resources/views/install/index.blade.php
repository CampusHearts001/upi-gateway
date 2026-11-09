@extends('install.layout')
@php $step = 1; @endphp

@section('content')
<h1 class="text-center text-2xl font-bold text-slate-900">Installation readiness</h1>
<p class="mt-1 text-center text-sm text-slate-500">This will only take a moment.</p>

@include('install.hosting-check', [
    'deploymentUrl' => $diagnostics['detected_app_url'],
    'serverReady' => $allPassed,
])

<div class="mt-8">
    <a hidden data-deployment-continue data-deployment-href="{{ route('install.database') }}" aria-disabled="true" class="btn btn-primary w-full justify-center py-2.5">
        Next <x-icon name="arrow-right" class="w-4 h-4" />
    </a>
</div>
@endsection
