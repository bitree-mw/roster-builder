<?php

use App\Http\Controllers\Auth\SessionController;
use Illuminate\Support\Facades\Route;

/*
 * Browser routes. Blade pages are shells that load their data from /api/v1; sign-in/out are JSON endpoints
 * in the web middleware stack (session + CSRF). Management pages are staff-only.
 */
Route::redirect('/', '/roster');
Route::view('/login', 'pages.login', ['page' => 'login', 'title' => 'Sign in'])->middleware('guest')->name('login');
Route::post('/login', [SessionController::class, 'store'])->middleware(['guest', 'throttle:login'])->name('session.store');
Route::post('/logout', [SessionController::class, 'destroy'])->middleware('auth')->name('session.destroy');

// Signed-in pages. The roster is shared with crew; every other page requires a staff role.
Route::middleware('auth')->group(function (): void {
    Route::view('/roster', 'pages.roster', ['page' => 'roster', 'title' => 'Roster workspace'])->name('roster');
    foreach (['aircraft' => 'Fleet', 'maintenance' => 'Maintenance', 'crew' => 'Crew directory', 'flights' => 'Flights & routes', 'rules' => 'Duty rules'] as $page => $title) {
        Route::get('/'.$page, function () use ($page, $title) {
            abort_unless(auth()->user()->isStaff(), 403);

            return view('pages.'.$page, compact('page', 'title'));
        })->name($page);
    }
});
