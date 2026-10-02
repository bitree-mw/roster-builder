<?php

use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SessionController;
use App\Services\ExpiryService;
use Illuminate\Support\Facades\Route;

/*
 * Browser routes. Blade pages are shells that load their data from /api/v1; sign-in/out are JSON endpoints
 * in the web middleware stack (session + CSRF). Management pages are staff-only.
 */
Route::view('/login', 'pages.login', ['page' => 'login', 'title' => 'Sign in'])->middleware('guest')->name('login');
Route::post('/login', [SessionController::class, 'store'])->middleware(['guest', 'throttle:login'])->name('session.store');
Route::post('/logout', [SessionController::class, 'destroy'])->middleware('auth')->name('session.destroy');

// Self-service password reset (guests): request a link, then set a new password from the emailed link.
Route::middleware('guest')->group(function (): void {
    Route::view('/forgot-password', 'pages.forgot-password', ['page' => 'forgot-password', 'title' => 'Reset your password'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'send'])->middleware('throttle:password-reset')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:password-reset')->name('password.update');
});

// Signed-in pages. The roster is shared with crew; every other page requires a staff role.
Route::middleware('auth')->group(function (): void {
    // Landing page after sign-in: staff start on the dashboard, crew on their own roster.
    Route::get('/', fn () => redirect()->route(auth()->user()->isStaff() ? 'dashboard' : 'roster'))->name('home');
    // The week timeline starts from today's base-local date (never the browser's timezone).
    Route::get('/roster', fn (ExpiryService $expiry) => view('pages.roster', ['page' => 'roster', 'title' => 'Weekly roster', 'today' => $expiry->today()->format('Y-m-d')]))->name('roster');
    // Account administration: administrators (all accounts) and schedulers (pilot and cabin crew accounts).
    Route::get('/accounts', function () {
        abort_unless(auth()->user()->assignableRoles() !== [], 403);

        return view('pages.accounts', ['page' => 'accounts', 'title' => 'Accounts']);
    })->name('accounts');
    foreach (['dashboard' => 'Operations dashboard', 'hours' => 'Crew hours', 'reports' => 'Reports', 'data' => 'Import & backup', 'aircraft' => 'Fleet', 'maintenance' => 'Maintenance', 'crew' => 'Crew directory', 'flights' => 'Flights & routes', 'rules' => 'Duty rules'] as $page => $title) {
        Route::get('/'.$page, function () use ($page, $title) {
            abort_unless(auth()->user()->isStaff(), 403);

            return view('pages.'.$page, compact('page', 'title'));
        })->name($page);
    }
});
