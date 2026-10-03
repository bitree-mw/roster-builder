# Feature flows

## Sign in
Blade login (email or username) → CSRF cookie → POST /login → LoginRequest → SessionService (email when the value contains @, otherwise lowercase username) → session regeneration → UserResource → roster page. Invalid credentials use a generic error. Logout invalidates the session and regenerates CSRF.

## Manage aircraft (reference vertical slice)
Requirements: unique aircraft code, 0–20 cabin seats, prohibit deletion while used.
Migration: aircraft_types → AircraftType → AircraftTypeRequest → AircraftTypeService → Api/V1/AircraftTypeController → AircraftTypeResource → /api/v1/aircraft-types → FoundationTest → pages/aircraft + page CSS/JS.
Create/update/delete and the audit entry commit together. Foreign keys remain the final integrity boundary.

## Fleet status and maintenance
Fleet page → GET /aircraft (+ due items) → status dialog → PATCH /aircraft/{id}/status → AircraftStatusRequest → AircraftService::changeStatus (lock, update, audit in one transaction) → AircraftResource → cards and nav counts refresh.
Maintenance page → POST /maintenance-records → MaintenanceRecordRequest → MaintenanceService::save (hours ordering check, recorded_by, audit) → GET /maintenance-alerts → MaintenanceService evaluates the latest record per airframe and check type against ExpiryService's base-local today and current airframe hours.
Flights page switch → PATCH /flights/{id}/status → FlightService::setActive (audited) → FlightResource.

## Crew and flight patterns
Page loads API list and lookup data. Form sends explicit fields; Form Request rejects malformed input. Services synchronize child records in a transaction and record audit changes. API Resource returns a deliberate response. UI rerenders using text nodes and displays validation messages.
FlightTimelineService checks connectivity, return to base, day boundaries and configured duty/rest limits; UTC snapshots are calculated on the server.

## Roster (1 week, 2 weeks or a month)
Roster window → GET /roster-periods?from&to (timeline of rosters overlapping the range; gaps are shown as "No roster") → GET /roster-periods/{id} → RosterLegalityService::context (rules snapshot, crew with ratings/documents/activities, the roster's trips and seats, seats in neighbouring rosters reaching back up to roster.max_trip_days for long rotations, fleet availability) → RosterConflictService → RosterWeekResource (filtered to own seats for crew).
Create: POST /roster-periods {starts_on, length} → RosterPeriodRequest (Monday for week/fortnight, the 1st for a month, planning window) → RosterPeriodService::create (overlap check, rules snapshot, audit).
Build: POST /roster-periods/{id}/build → lock the roster row → RosterPeriodService::assertEditable → expand enabled patterns from today (FlightTimelineService::validate against the snapshot rules → UTC schedule snapshot) and ensure seats → release automatic seats (manual seats and operated trips kept) → hardest seats first → RosterLegalityService::issues + score for every active crew member of the position → assign the lowest legal score or leave open, writing decision_log → built_at/built_by and audit, all in one transaction.
Edit: seat editor → GET /assignments/{id}/candidates → PUT /assignments/{id} → AssignmentRequest → AssignmentService::assign (lock week, editable and not operated, hard problems refused, reason required for other problems, flag_reasons, decision_log, audit).
Publish: POST /roster-periods/{id}/publish → built and no blocking conflict → status published (audited). Reopen: POST /roster-periods/{id}/reopen → draft (audited).
Crew see only published rosters and their own seats; drafts return 404. The individual view (grid crew name, or ?crew=) shows one person's days, including layover days of a multi-day rotation.

## Accounts
Accounts page → GET /accounts (filtered to roles the caller may assign) → AccountRequest (authorize: manage-accounts and, on edit, the target's role; rules: role in assignableRoles, crew link for pilot/cabin) → AccountService::save (lock, last-admin guard, explicit role/username/crew link, revoke sessions and tokens on a password set by someone else, audit) → AccountResource. DELETE → delete-accounts gate → AccountService::delete. "My account" → PUT /me/password → AccountService::changeOwnPassword.

## Hours
Hours page → GET /crew-hours?group → CrewHoursService::summaries (published weeks' assignments + timed SIM/standby, per window, flown vs scheduled by release time). Crew roster page → GET /my-hours.

## Day planning, exclusions and undo
Roster grid crew name → day planning dialog → POST /crew-activities → CrewActivityRequest → CrewActivityService::create (one per day, local times → UTC, audit). Inspector → POST /assignments/{id}/exclude | undo, DELETE /exclusions/{id} → AssignmentService (week lock, editable, audit).

## Exports and email
Roster window → GET export.csv / calendar.ics → RosterExportService (same planning context as the window). Email crew → POST /roster-periods/{id}/email → RosterEmailService (published only, email_logs queued) → SendRosterEmail job → RosterMail (+ .ics) → log sent/failed.

## Reports and PDF files
Reports page → GET /reports/{type}?from&to → ReportRequest → ReportService::build → JSON, or export.csv (App\Support\Csv) / export.pdf (PdfService → resources/views/pdf/report). Roster window PDF → GET /roster-periods/{id}/roster.pdf → RosterExportService::pdf → PdfService (dompdf; resources/css/pdf/document.css with token values from tokens.css).

## CSV import
Import & backup page → POST /imports/preview → ImportPreviewRequest → ImportService::preview (Csv::read, aliases, row checks against current data, cached 30 minutes with a data fingerprint) → POST /imports/commit → ImportService::commit (no errors, fingerprint unchanged) → CrewMemberService / FlightService / CrewActivityService in one transaction → audit "imported".

## Backup and restore
GET /backups/download → BackupService::export (audited). POST /backups/inspect → BackupService::inspect (validate, store privately, counts) → POST /backups/restore {token, RESTORE} → BackupService::restore (detach crew links, delete in reverse FK order, insert, clear missing user references, re-link accounts, audit) in one transaction.

## Password reset
/forgot-password → POST /forgot-password → PasswordResetService::sendLink (email or username; broker token; branded ResetPassword mail) → emailed link → /reset-password/{token} → POST /reset-password → PasswordResetService::reset (password, remember token, revoke tokens and sessions, audit).

## Dashboard
Dashboard page → GET /dashboard → DashboardService (OverviewService counts, the last, current and next two rosters around today with RosterConflictService summaries, conflicts for this and next week, today's trips, fleet issues, MaintenanceService alerts, crew documents within the warning window) → DashboardResource.

## Future exports / imports
Exports use the same authorized roster query and selected range. CSV/ICS escaping and timezone edge cases need tests.
Import upload → parse/normalize → row-level preview → explicit mode → revalidate preview version → atomic import and audit. Backups and restores must never carry credentials or Sanctum tokens.
