<!DOCTYPE html>
{{-- data-theme comes from the "theme" cookie (light or dark); without it the system preference applies. --}}
<html lang="en" @if(in_array(request()->cookie('theme'), ['light', 'dark'], true)) data-theme="{{ request()->cookie('theme') }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · Malawi Airlines Roster Builder</title>
    <link rel="icon" type="image/png" href="{{ asset('images/malawi-airlines-logo.png') }}">
    {{-- Signed-in layout. Loads the shared CSS/JS plus the page's own entry (resources/{css,js}/pages/{page}). --}}
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/css/pages/'.$page.'.css', 'resources/js/pages/'.$page.'.js'])
</head>
<body data-role="{{ auth()->user()?->role }}" data-page="{{ $page }}">
{{-- SVG symbols referenced by <x-icon> and icon() in JS. --}}
<x-icon-sprite />
<a class="skip-link" href="#main">Skip to content</a>
@php($staff = auth()->user()->isStaff())
{{-- Top bar: logo, header alert pills (filled by resources/js/common/shell.js for staff), user chip and sign-out. --}}
<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="{{ route('home') }}" aria-label="Malawi Airlines Roster Builder home">
            <span class="brand-logo"><img src="{{ asset('images/malawi-airlines-logo.png') }}" alt="Malawi Airlines"></span>
            <span class="brand-text">
                <span class="brand-title">Roster Builder</span>
                <span class="brand-subtitle">Crew control workstation · Operations directorate</span>
            </span>
        </a>
        <div class="topbar-actions">
            @if($staff)
            <a class="alert-pill" href="{{ route('crew') }}" data-alert-pill="documents" data-tone="warning" hidden><x-icon name="id-card" class="icon-sm" /><span data-alert-pill-text></span></a>
            @endif
            {{-- Light / dark theme switch (resources/js/common/theme.js). --}}
            <button id="theme-toggle" class="icon-button" type="button" aria-label="Switch to dark theme" title="Switch theme"><x-icon name="moon" data-theme-icon="moon" /><x-icon name="sun" data-theme-icon="sun" /></button>
            {{-- The user chip opens "My account" (change your own password); resources/js/common/account.js. --}}
            <button id="my-account" class="user-chip" type="button" aria-haspopup="dialog" title="My account">
                <span class="user-chip-text"><span class="user-chip-name">{{ auth()->user()->name }}</span><span class="user-chip-role">{{ auth()->user()->roleLabel() }}</span></span>
                <span class="avatar" aria-hidden="true">{{ auth()->user()->initials() }}</span>
            </button>
            <button id="logout" class="icon-button" type="button" aria-label="Sign out" title="Sign out"><x-icon name="logout" /></button>
        </div>
    </div>
    {{-- Main navigation: Dashboard, Roster, Flight routes, Fleet, Crew, Crew hours, Duty rules, Import, Accounts.
         Crew accounts only see their roster. Reports open from the Dashboard; the maintenance log from Fleet.
         data-tab-count badges are filled from /api/v1/overview. --}}
    <nav class="tabs" aria-label="Main navigation">
        @if($staff)
        <a class="tab" href="{{ route('dashboard') }}" @if($page === 'dashboard') aria-current="page" @endif><x-icon name="layout" class="icon-sm" />Dashboard</a>
        @endif
        <a class="tab" href="{{ route('roster') }}" @if($page === 'roster') aria-current="page" @endif><x-icon name="calendar" class="icon-sm" />{{ $staff ? 'Roster' : 'My roster' }}</a>
        @if($staff)
        @foreach([
            'flights' => ['Flight routes', 'route'],
            'aircraft' => ['Fleet', 'plane'],
            'crew' => ['Crew', 'users'],
            'hours' => ['Crew hours', 'gauge'],
            'rules' => ['Duty rules', 'sliders'],
            'data' => ['Import', 'copy'],
        ] as $key => [$label, $icon])
        <a class="tab" href="{{ route($key) }}" @if($page === $key) aria-current="page" @endif><x-icon :name="$icon" class="icon-sm" />{{ $label }}<span class="tab-count" data-tab-count="{{ $key }}" hidden></span></a>
        @endforeach
        @endif
        @if(auth()->user()->assignableRoles() !== [])
        <a class="tab" href="{{ route('accounts') }}" @if($page === 'accounts') aria-current="page" @endif><x-icon name="lock" class="icon-sm" />Accounts</a>
        @endif
    </nav>
</header>
{{-- Page content from @section('content'). --}}
<main id="main" class="workspace">
    @yield('content')
</main>
{{-- Status bar: makes the time convention and the non-approved status of this build explicit. --}}
<footer class="statusbar">
    <span>Malawi Airlines · Crew Control · Kamuzu International Airport (LLW)</span>
    <span><span class="statusbar-dot" aria-hidden="true"></span>Signed in as {{ auth()->user()->roleLabel() }}</span>
    <span>Pattern times: base local · Dated instants: UTC</span>
    <span>Development build · not an approved scheduling system</span>
</footer>
{{-- "My account": change your own password (every signed-in user). Wired by resources/js/common/account.js. --}}
<dialog id="account-dialog" class="dialog account-dialog" aria-labelledby="account-dialog-title"><form id="account-form" novalidate>
    <div class="dialog-heading"><div><h2 id="account-dialog-title">My account</h2><p>{{ auth()->user()->name }} · {{ auth()->user()->roleLabel() }}@if(auth()->user()->username) · {{ auth()->user()->username }}@endif</p></div><button type="button" class="icon-button" data-close aria-label="Close"><x-icon name="x" /></button></div>
    <div class="form-grid">
        <label class="span-2">Current password<input name="current_password" type="password" autocomplete="current-password" required></label>
        <label>New password<input name="password" type="password" autocomplete="new-password" minlength="12" required aria-describedby="new-password-help"><span id="new-password-help" class="field-hint">At least 12 characters.</span></label>
        <label>Repeat new password<input name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required></label>
    </div>
    <p class="field-hint">Changing your password signs you out on every other device.</p>
    <div class="status" data-form-status role="alert"></div>
    <div class="form-actions"><button class="button button-secondary" type="button" data-close-secondary>Cancel</button><button class="button" type="submit">Change password</button></div>
</form></dialog>
</body>
</html>
