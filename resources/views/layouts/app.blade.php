<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · Malawi Airlines Roster Builder</title>
    <link rel="icon" type="image/png" href="{{ asset('images/malawi-airlines-logo.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/css/pages/'.$page.'.css', 'resources/js/pages/'.$page.'.js'])
</head>
<body data-role="{{ auth()->user()?->role }}" data-page="{{ $page }}">
<x-icon-sprite />
<a class="skip-link" href="#main">Skip to content</a>
@php($staff = auth()->user()->isStaff())
<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="{{ route('roster') }}" aria-label="Malawi Airlines Roster Builder home">
            <span class="brand-logo"><img src="{{ asset('images/malawi-airlines-logo.png') }}" alt="Malawi Airlines"></span>
            <span class="brand-text">
                <span class="brand-title">Roster Builder</span>
                <span class="brand-subtitle">Crew control workstation · Operations directorate</span>
            </span>
        </a>
        <div class="topbar-actions">
            @if($staff)
            <a class="alert-pill" href="{{ route('maintenance') }}" data-alert-pill="maintenance" hidden><x-icon name="wrench" class="icon-sm" /><span data-alert-pill-text></span></a>
            <a class="alert-pill" href="{{ route('crew') }}" data-alert-pill="documents" data-tone="warning" hidden><x-icon name="id-card" class="icon-sm" /><span data-alert-pill-text></span></a>
            @endif
            <div class="user-chip">
                <span class="user-chip-text"><span class="user-chip-name">{{ auth()->user()->name }}</span><span class="user-chip-role">{{ auth()->user()->roleLabel() }}</span></span>
                <span class="avatar" aria-hidden="true">{{ auth()->user()->initials() }}</span>
            </div>
            <button id="logout" class="icon-button" type="button" aria-label="Sign out" title="Sign out"><x-icon name="logout" /></button>
        </div>
    </div>
    <nav class="tabs" aria-label="Main navigation">
        <a class="tab" href="{{ route('roster') }}" @if($page === 'roster') aria-current="page" @endif><x-icon name="calendar" class="icon-sm" />Roster</a>
        @if($staff)
        @foreach([
            'flights' => ['Flights & routes', 'route'],
            'aircraft' => ['Fleet', 'plane'],
            'maintenance' => ['Maintenance', 'wrench'],
            'crew' => ['Crew', 'users'],
            'rules' => ['Duty rules', 'sliders'],
        ] as $key => [$label, $icon])
        <a class="tab" href="{{ route($key) }}" @if($page === $key) aria-current="page" @endif><x-icon :name="$icon" class="icon-sm" />{{ $label }}<span class="tab-count" data-tab-count="{{ $key }}" hidden></span></a>
        @endforeach
        @endif
    </nav>
</header>
<main id="main" class="workspace">
    @yield('content')
</main>
<footer class="statusbar">
    <span>Malawi Airlines · Crew Control · Kamuzu International Airport (LLW)</span>
    <span><span class="statusbar-dot" aria-hidden="true"></span>Signed in as {{ auth()->user()->roleLabel() }}</span>
    <span>Pattern times: base local · Dated instants: UTC</span>
    <span>Development build · not an approved scheduling system</span>
</footer>
</body>
</html>
