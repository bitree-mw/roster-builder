<?php

namespace App\Providers;

use App\Models\User;
use App\Services\ExpiryService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
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
        Gate::define('read-operations', fn (User $user): bool => $user->isStaff() && $user->tokenCan('roster:read'));
        Gate::define('manage-operations', fn (User $user): bool => $user->isStaff() && $user->tokenCan('roster:write'));
        Gate::define('manage-rules', fn (User $user): bool => $user->role === 'scheduler' && $user->tokenCan('roster:write'));
        Gate::define('read-rosters', fn (User $user): bool => ($user->isStaff() || ($user->role === 'crew' && $user->crew_member_id !== null)) && $user->tokenCan('roster:read'));
        // Sign-in: 5 attempts a minute per login name and IP, and 30 a minute per IP overall.
        RateLimiter::for('login', fn (Request $request): array => [
            Limit::perMinute(5)->by(mb_strtolower(trim((string) $request->input('login'))).'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);
        // API: 120 requests a minute per user (or per IP before authentication).
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)->by($request->user()?->id ?? $request->ip()));
    }
}
