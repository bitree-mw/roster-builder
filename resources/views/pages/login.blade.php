{{-- Sign-in page. Behaviour: resources/js/pages/login.js; layout fits the viewport (pages/login.css). --}}
@extends('layouts.guest')
@section('content')
<div class="login-shell">
    {{-- Brand panel: what the system does. The route strip is decorative (aria-hidden). --}}
    <section class="login-hero" aria-labelledby="login-hero-title">
        <div class="login-hero-brand">
            <span class="login-logo"><img src="{{ asset('images/malawi-airlines-logo.png') }}" alt="Malawi Airlines"></span>
            <span class="login-hero-tag">Roster Builder<br><span>Crew control workstation</span></span>
        </div>
        <div class="login-hero-copy">
            <p class="login-hero-eyebrow">Flight operations directorate</p>
            <h1 id="login-hero-title">Crew rosters, flight patterns and fleet readiness in one console.</h1>
            <p>Plan monthly crew duties, keep flight patterns current, and see which aircraft are available, in maintenance or grounded before you schedule.</p>
        </div>
        <ul class="login-features" aria-label="What you can do">
            <li><x-icon name="route" /><span><strong>Flights &amp; routes</strong><span class="login-feature-text">Connected legs, night stops and enable or disable patterns.</span></span></li>
            <li><x-icon name="plane" /><span><strong>Fleet status</strong><span class="login-feature-text">Available, in maintenance, grounded (AOG) or unavailable.</span></span></li>
            <li><x-icon name="wrench" /><span><strong>Maintenance alerts</strong><span class="login-feature-text">Checks due by date or airframe hours, flagged early.</span></span></li>
        </ul>
        <div class="login-strip" aria-hidden="true">
            <span class="login-strip-code">LLW</span>
            <span class="login-strip-line"><x-icon name="plane" /></span>
            <span class="login-strip-code">BLZ</span>
        </div>
    </section>

    {{-- Sign-in card: one "email or username" field, password with show/hide, inline errors. --}}
    <section class="login-panel" aria-labelledby="login-title">
        <div class="login-card">
            <span class="login-card-logo"><img src="{{ asset('images/malawi-airlines-logo.png') }}" alt="Malawi Airlines"></span>
            <p class="eyebrow">Authorised staff only</p>
            <h2 id="login-title">Sign in to your workspace</h2>
            <p class="login-intro">Use the account issued by your system administrator.</p>
            <form id="login-form" class="login-form" novalidate>
                <label for="login-identifier">Email or username
                    <span class="input-with-icon"><x-icon name="mail" /><input id="login-identifier" name="login" type="text" required maxlength="254" autocomplete="username" autocapitalize="none" spellcheck="false" aria-describedby="login-status"></span>
                </label>
                <label for="login-password">Password
                    <span class="input-with-icon"><x-icon name="lock" />
                        <input id="login-password" name="password" type="password" required autocomplete="current-password" aria-describedby="caps-warning login-status">
                        <button id="toggle-password" class="input-action" type="button" aria-controls="login-password" aria-pressed="false" aria-label="Show password"><x-icon name="eye" data-eye /><x-icon name="eye-off" data-eye-off hidden /></button>
                    </span>
                </label>
                <p id="caps-warning" class="caps-warning" hidden><x-icon name="alert" class="icon-sm" />Caps Lock is on.</p>
                <div id="login-status" class="status" role="alert"></div>
                <button class="button button-block login-submit" type="submit"><x-icon name="lock" class="icon-sm" /><span data-submit-label>Sign in</span></button>
            </form>
            <p class="login-help"><x-icon name="shield" class="icon-sm" /><span>Sessions are protected and every operational change is audited. <a href="{{ route('password.request') }}">Forgot your password?</a></span></p>
        </div>
        <p class="login-footer">Malawi Airlines · Crew Control · Kamuzu International Airport (LLW)</p>
    </section>
</div>
@endsection
