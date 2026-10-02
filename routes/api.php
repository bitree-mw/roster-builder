<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AircraftController;
use App\Http\Controllers\Api\V1\AircraftTypeController;
use App\Http\Controllers\Api\V1\AssignmentController;
use App\Http\Controllers\Api\V1\BackupController;
use App\Http\Controllers\Api\V1\CrewActivityController;
use App\Http\Controllers\Api\V1\CrewHoursController;
use App\Http\Controllers\Api\V1\CrewMemberController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\FlightController;
use App\Http\Controllers\Api\V1\ImportController;
use App\Http\Controllers\Api\V1\LookupController;
use App\Http\Controllers\Api\V1\MaintenanceRecordController;
use App\Http\Controllers\Api\V1\OverviewController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RosterExportController;
use App\Http\Controllers\Api\V1\RosterPeriodController;
use App\Http\Controllers\Api\V1\RuleSetController;
use App\Services\ImportService;
use App\Services\ReportService;
use Illuminate\Support\Facades\Route;

/*
 * Versioned JSON API used by the Blade client (Sanctum session cookies) and scoped integration tokens.
 * Authorization happens in Form Requests and controllers via gates (see AppServiceProvider).
 */
Route::prefix('v1')->name('api.v1.')->middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::get('me', [ProfileController::class, 'show'])->name('me');
    Route::get('my-profile', [ProfileController::class, 'crew'])->name('my-profile');
    Route::get('lookups', LookupController::class)->name('lookups');
    Route::get('overview', OverviewController::class)->name('overview');
    Route::get('dashboard', DashboardController::class)->name('dashboard');
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
    Route::get('roster-periods/{rosterPeriod}', [RosterPeriodController::class, 'show'])->name('roster-periods.show');
    // Weekly roster lifecycle: run the generator, release to crew, or take back to draft for changes.
    Route::post('roster-periods/{rosterPeriod}/build', [RosterPeriodController::class, 'build'])->name('roster-periods.build');
    Route::post('roster-periods/{rosterPeriod}/publish', [RosterPeriodController::class, 'publish'])->name('roster-periods.publish');
    Route::post('roster-periods/{rosterPeriod}/reopen', [RosterPeriodController::class, 'reopen'])->name('roster-periods.reopen');
    // Week outputs: CSV and calendar downloads, roster emails to crew and their delivery log.
    Route::get('roster-periods/{rosterPeriod}/export.csv', [RosterExportController::class, 'csv'])->name('roster-periods.csv');
    Route::get('roster-periods/{rosterPeriod}/calendar.ics', [RosterExportController::class, 'calendar'])->name('roster-periods.calendar');
    Route::post('roster-periods/{rosterPeriod}/email', [RosterExportController::class, 'email'])->name('roster-periods.email');
    Route::get('roster-periods/{rosterPeriod}/email-logs', [RosterExportController::class, 'emailLogs'])->name('roster-periods.email-logs');
    // Manual seat edits: candidates for a seat, then assign (locks the seat) or clear it, exclude, undo.
    Route::get('assignments/{assignment}/candidates', [AssignmentController::class, 'candidates'])->name('assignments.candidates');
    Route::put('assignments/{assignment}', [AssignmentController::class, 'update'])->name('assignments.update');
    Route::post('assignments/{assignment}/exclude', [AssignmentController::class, 'exclude'])->name('assignments.exclude');
    Route::post('assignments/{assignment}/undo', [AssignmentController::class, 'undo'])->name('assignments.undo');
    Route::delete('exclusions/{exclusion}', [AssignmentController::class, 'include'])->name('exclusions.destroy');
    // Day planning: leave, day off, SIM and standby.
    Route::post('crew-activities', [CrewActivityController::class, 'store'])->name('crew-activities.store');
    Route::delete('crew-activities/{crewActivity}', [CrewActivityController::class, 'destroy'])->name('crew-activities.destroy');
    // Accumulated hours: staff report per group, and a crew member's own hours.
    Route::get('crew-hours', [CrewHoursController::class, 'index'])->name('crew-hours.index');
    Route::get('my-hours', [CrewHoursController::class, 'mine'])->name('my-hours');
    // Accounts (administrators: all; schedulers: pilot and cabin crew accounts) and your own password.
    Route::apiResource('accounts', AccountController::class)->except('show');
    Route::put('me/password', [ProfileController::class, 'password'])->name('me.password');
    Route::get('roster-periods/{rosterPeriod}/roster.pdf', [RosterExportController::class, 'pdf'])->name('roster-periods.pdf');
    // Reports: JSON for the Reports page, or CSV / PDF downloads of the same report.
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/{type}', [ReportController::class, 'show'])->whereIn('type', array_keys(ReportService::TYPES))->name('reports.show');
    Route::get('reports/{type}/export.{format}', [ReportController::class, 'export'])->whereIn('type', array_keys(ReportService::TYPES))->whereIn('format', ['csv', 'pdf'])->name('reports.export');
    // CSV import: templates, row-by-row preview, then commit.
    Route::get('imports/templates/{kind}', [ImportController::class, 'template'])->whereIn('kind', array_keys(ImportService::KINDS))->name('imports.template');
    Route::post('imports/preview', [ImportController::class, 'preview'])->name('imports.preview');
    Route::post('imports/commit', [ImportController::class, 'commit'])->name('imports.commit');
    // Backup and restore of all operational data (administrators).
    Route::get('backups/download', [BackupController::class, 'download'])->name('backups.download');
    Route::post('backups/inspect', [BackupController::class, 'inspect'])->name('backups.inspect');
    Route::post('backups/restore', [BackupController::class, 'restore'])->name('backups.restore');
});
