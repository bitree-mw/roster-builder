{{-- "Forgot your password?" page (guests). Behaviour: resources/js/pages/forgot-password.js (POST /forgot-password). --}}
@extends('layouts.guest')
@section('content')
<div class="auth-shell">
    <section class="login-card" aria-labelledby="forgot-title">
        <span class="login-card-logo"><img src="{{ asset('images/malawi-airlines-logo.png') }}" alt="Malawi Airlines"></span>
        <p class="eyebrow">Account help</p>
        <h2 id="forgot-title">Reset your password</h2>
        <p class="login-intro">Enter your email address or username. If your account has an email address, we send it a link to choose a new password.</p>
        <form id="forgot-form" class="login-form" novalidate>
            <label for="forgot-login">Email or username
                <span class="input-with-icon"><x-icon name="mail" /><input id="forgot-login" name="login" type="text" required maxlength="254" autocomplete="username" autocapitalize="none" spellcheck="false" aria-describedby="forgot-status"></span>
            </label>
            <div id="forgot-status" class="status" role="status"></div>
            <button class="button button-block login-submit" type="submit"><x-icon name="mail" class="icon-sm" /><span data-submit-label>Send reset link</span></button>
        </form>
        <p class="login-help"><x-icon name="shield" class="icon-sm" />No email on your account? Ask an administrator or scheduler to set a new password for you.</p>
        <a class="auth-back" href="{{ route('login') }}"><x-icon name="chevron-left" class="icon-sm" />Back to sign in</a>
    </section>
</div>
@endsection
