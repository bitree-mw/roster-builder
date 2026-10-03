<?php

namespace App\Providers;

use App\Models\User;
use App\Services\ExpiryService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Application-wide bindings, authorization gates and rate limits.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * One ExpiryService per request so "today" is computed once and stays consistent within a response.
     */
    public function register(): void
    {
        $this->app->scoped(ExpiryService::class);
    }

    /**
     * Gates combine the user's role with the Sanctum token ability: browser sessions get every ability, while
     * integration tokens need roster:read for reads and roster:write for writes.
     */
    public function boot(): void
    {
        // Fail fast on N+1 queries outside production.
        Model::preventLazyLoading(! $this->app->isProduction());
        // Behind an HTTPS proxy, trust its forwarded headers so URLs, assets and cookies use https.
        $proxies = config('roster.trusted_proxies');
        if (is_string($proxies) && trim($proxies) !== '') {
            TrustProxies::at(trim($proxies) === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
        Gate::define('read-operations', fn (User $user): bool => $user->isStaff() && $user->tokenCan('roster:read'));
        Gate::define('manage-operations', fn (User $user): bool => $user->isStaff() && $user->tokenCan('roster:write'));
        Gate::define('manage-rules', fn (User $user): bool => in_array($user->role, ['admin', 'scheduler'], true) && $user->tokenCan('roster:write'));
        // Accounts: administrators manage everyone; schedulers create and edit pilot and cabin crew accounts only.
        Gate::define('manage-accounts', fn (User $user): bool => $user->assignableRoles() !== [] && $user->tokenCan('roster:write'));
        // Airports (route destinations and crew bases) are administrator settings.
        Gate::define('manage-airports', fn (User $user): bool => $user->isAdmin() && $user->tokenCan('roster:write'));
        // Backups hold every operational record and a restore replaces them all: administrators only.
        Gate::define('manage-backups', fn (User $user): bool => $user->isAdmin() && $user->tokenCan('roster:write'));
        Gate::define('delete-accounts', fn (User $user): bool => $user->isAdmin() && $user->tokenCan('roster:write'));
        Gate::define('read-rosters', fn (User $user): bool => ($user->isStaff() || ($user->role === 'crew' && $user->crew_member_id !== null)) && $user->tokenCan('roster:read'));
        // Sign-in: 5 attempts a minute per login name and IP, and 30 a minute per IP overall.
        RateLimiter::for('login', fn (Request $request): array => [
            Limit::perMinute(5)->by(mb_strtolower(trim((string) $request->input('login'))).'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);
        // Password reset: 5 requests a minute per login (or email) and IP, 20 a minute per IP overall.
        RateLimiter::for('password-reset', fn (Request $request): array => [
            Limit::perMinute(5)->by(mb_strtolower(trim((string) ($request->input('login') ?? $request->input('email')))).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);
        // The reset email: plain wording, the link's lifetime and the crew control signature.
        ResetPassword::toMailUsing(fn (User $user, string $token): MailMessage => (new MailMessage)
            ->subject('Reset your Roster Builder password')
            ->greeting('Hello '.$user->name.',')
            ->line('Someone asked to reset the password for your Malawi Airlines Roster Builder account.')
            ->action('Choose a new password', url(route('password.reset', ['token' => $token, 'email' => $user->email], false)))
            ->line('This link works once and expires in '.config('auth.passwords.users.expire').' minutes. If you did not ask for it, ignore this email; your password stays the same.')
            ->salutation((string) config('roster.mail_signature')));
        // API: 120 requests a minute per user (or per IP before authentication).
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)->by($request->user()?->id ?? $request->ip()));
    }
}
