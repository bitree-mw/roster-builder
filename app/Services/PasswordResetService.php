<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Self-service password reset by email (Laravel's password broker: hashed, single-use tokens that expire
 * after config('auth.passwords.users.expire') minutes).
 *
 * Asking for a link never reveals whether an account exists: the caller always gets the same answer.
 * Accounts without an email address cannot use this; an administrator or scheduler sets a new password
 * for them instead. A successful reset signs the person out of every session and revokes API tokens.
 */
class PasswordResetService
{
    public function __construct(private AuditService $audit) {}

    /**
     * Email a reset link to the account matching an email address or username, if there is one.
     */
    public function sendLink(string $login): void
    {
        $login = trim($login);
        $user = User::query()->where(str_contains($login, '@') ? 'email' : 'username', str_contains($login, '@') ? $login : mb_strtolower($login))->first();
        if ($user?->email) {
            // The broker also throttles repeated requests for the same account; the result is deliberately ignored.
            Password::broker()->sendResetLink(['email' => $user->email]);
        }
    }

    /**
     * Set a new password with a valid reset token.
     *
     * @param  array{token: string, email: string, password: string, password_confirmation: string}  $data
     *
     * @throws ValidationException when the link is invalid or has expired
     */
    public function reset(array $data): void
    {
        $status = Password::broker()->reset($data, function (User $user, string $password): void {
            DB::transaction(function () use ($user, $password): void {
                $user->password = $password;
                $user->setRememberToken(Str::random(60));
                $user->save();
                $user->tokens()->delete();
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
                $this->audit->record($user, 'password_reset', $user);
            });
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'This reset link is invalid or has expired. Ask for a new one.']);
        }
    }
}
