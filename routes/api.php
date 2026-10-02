<?php

use App\Http\Controllers\Api\V1\AircraftController;
use App\Http\Controllers\Api\V1\AircraftTypeController;
use App\Http\Controllers\Api\V1\CrewMemberController;
use App\Http\Controllers\Api\V1\FlightController;
use App\Http\Controllers\Api\V1\LookupController;
use App\Http\Controllers\Api\V1\MaintenanceRecordController;
use App\Http\Controllers\Api\V1\OverviewController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\RosterPeriodController;
use App\Http\Controllers\Api\V1\RuleSetController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::get('me', [ProfileController::class, 'show'])->name('me');
    Route::get('my-profile', [ProfileController::class, 'crew'])->name('my-profile');
    Route::get('lookups', LookupController::class)->name('lookups');
    Route::get('overview', OverviewController::class)->name('overview');
    Route::apiResource('aircraft-types', AircraftTypeController::class);
    Route::apiResource('aircraft', AircraftController::class);
    Route::patch('aircraft/{aircraft}/status', [AircraftController::class, 'status'])->name('aircraft.status');
    Route::get('maintenance-alerts', [MaintenanceRecordController::class, 'alerts'])->name('maintenance-alerts');
    Route::apiResource('maintenance-records', MaintenanceRecordController::class);
    Route::apiResource('crew-members', CrewMemberController::class);
    Route::apiResource('flights', FlightController::class);
    Route::patch('flights/{flight}/status', [FlightController::class, 'status'])->name('flights.status');
    Route::get('rules', [RuleSetController::class, 'show'])->name('rules.show');
    Route::put('rules', [RuleSetController::class, 'update'])->name('rules.update');
    Route::get('roster-periods', [RosterPeriodController::class, 'index'])->name('roster-periods.index');
    Route::post('roster-periods', [RosterPeriodController::class, 'store'])->name('roster-periods.store');
});
