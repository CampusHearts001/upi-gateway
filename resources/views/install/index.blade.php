@extends('install.layout')
@php $step = 1; @endphp

@section('content')
<h1 class="text-2xl font-bold text-slate-900">Server Requirements</h1>
<p class="mt-1 text-sm text-slate-500">Make sure your server meets all requirements before proceeding.</p>

<div class="mt-6 space-y-2">
    @foreach ($requirements as $req)
        <div class="flex items-center justify-between rounded-lg px-4 py-3 {{ $req['pass'] ? 'bg-emerald-50 ring-1 ring-emerald-200' : 'bg-red-50 ring-1 ring-red-200' }}">
            <span class="text-sm font-medium {{ $req['pass'] ? 'text-emerald-800' : 'text-red-800' }}">{{ $req['name'] }}</span>
            <span class="grid h-7 w-7 place-items-center rounded-full {{ $req['pass'] ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700' }}"><x-icon :name="$req['pass'] ? 'check' : 'x'" class="w-4 h-4" /></span>
        </div>
    @endforeach
</div>

@php $allPass = collect($requirements)->every(fn($r) => $r['pass'] || !($r['required'] ?? true)); @endphp

<div class="mt-8">
    @if ($allPass)
        <a href="{{ route('install.database') }}" class="btn btn-primary w-full justify-center py-2.5">
            Continue to database setup <x-icon name="arrow-right" class="w-4 h-4" />
        </a>
    @else
        <div class="flex items-start gap-2 rounded-lg bg-red-50 ring-1 ring-red-200 px-4 py-3 text-sm text-red-700">
            <x-icon name="alert" class="w-5 h-5 shrink-0" /> <span>Please fix the failing requirements and refresh this page.</span>
        </div>
        <button data-reload-page class="btn btn-secondary w-full justify-center py-2.5 mt-3">Recheck</button>
    @endif
</div>
@endsection
