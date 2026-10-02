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

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ExpiryService::class);
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        Gate::define('read-operations', fn (User $user): bool => $user->isStaff() && $user->tokenCan('roster:read'));
        Gate::define('manage-operations', fn (User $user): bool => $user->isStaff() && $user->tokenCan('roster:write'));
        Gate::define('manage-rules', fn (User $user): bool => $user->role === 'scheduler' && $user->tokenCan('roster:write'));
        Gate::define('read-rosters', fn (User $user): bool => ($user->isStaff() || ($user->role === 'crew' && $user->crew_member_id !== null)) && $user->tokenCan('roster:read'));
        RateLimiter::for('login', fn (Request $request): array => [
            Limit::perMinute(5)->by(mb_strtolower(trim((string) $request->input('login'))).'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)->by($request->user()?->id ?? $request->ip()));
    }
}
