<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Browser sign-in and sign-out for the Blade client (Sanctum stateful session cookies).
 */
class SessionService
{
    /**
     * Usernames cannot contain "@", so a login containing one is treated as an email address.
     * Usernames are stored lowercase and matched case-insensitively.
     *
     * @param  array{login: string, password: string}  $credentials
     */
    public function login(array $credentials, Session $session): User
    {
        $login = $credentials['login'];
        $identifier = str_contains($login, '@') ? ['email' => $login] : ['username' => mb_strtolower($login)];
        if (! Auth::guard('web')->attempt([...$identifier, 'password' => $credentials['password']])) {
            throw ValidationException::withMessages(['login' => 'The supplied credentials could not be verified.']);
        }
        $session->regenerate();

        return Auth::guard('web')->user();
    }

    public function logout(Session $session): void
    {
        Auth::guard('web')->logout();
        $session->invalidate();
        $session->regenerateToken();
    }
}
