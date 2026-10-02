{{--
    "Choose a new password" page opened from the emailed reset link (guests).
    Behaviour: resources/js/pages/reset-password.js (POST /reset-password). The token and email come from the link.
--}}
@extends('layouts.guest')
@section('content')
<div class="auth-shell">
    <section class="login-card" aria-labelledby="reset-title">
        <span class="login-card-logo"><img src="{{ asset('images/malawi-airlines-logo.png') }}" alt="Malawi Airlines"></span>
        <p class="eyebrow">Account help</p>
        <h2 id="reset-title">Choose a new password</h2>
        <p class="login-intro">For {{ $email !== '' ? $email : 'your account' }}. Use at least 12 characters; you will be signed out on every device.</p>
        <form id="reset-form" class="login-form" novalidate>
            <input type="hidden" name="token" value="{{ $token }}">
            <input type="hidden" name="email" value="{{ $email }}">
            <label for="reset-password">New password
                <span class="input-with-icon"><x-icon name="lock" /><input id="reset-password" name="password" type="password" required minlength="12" autocomplete="new-password" aria-describedby="reset-status"></span>
            </label>
            <label for="reset-confirmation">Repeat new password
                <span class="input-with-icon"><x-icon name="lock" /><input id="reset-confirmation" name="password_confirmation" type="password" required minlength="12" autocomplete="new-password"></span>
            </label>
            <div id="reset-status" class="status" role="alert"></div>
            <button class="button button-block login-submit" type="submit"><x-icon name="check" class="icon-sm" /><span data-submit-label>Set new password</span></button>
        </form>
        <a class="auth-back" href="{{ route('password.request') }}"><x-icon name="chevron-left" class="icon-sm" />Ask for a new link</a>
    </section>
</div>
@endsection
