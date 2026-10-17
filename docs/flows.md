# Feature flows

## Sign in
Blade login (email or username) → CSRF cookie → POST /login → LoginRequest → SessionService (email when the value contains @, otherwise lowercase username) → session regeneration → UserResource → roster page. Invalid credentials use a generic error. Logout invalidates the session and regenerates CSRF.

## Manage aircraft (reference vertical slice)
Requirements: unique aircraft code, 0–20 cabin seats, prohibit deletion while used.
Migration: aircraft_types → AircraftType → AircraftTypeRequest → AircraftTypeService → Api/V1/AircraftTypeController → AircraftTypeResource → /api/v1/aircraft-types → FoundationTest → pages/aircraft + page CSS/JS.
Create/update/delete and the audit entry commit together. Foreign keys remain the final integrity boundary.

## Airports (administrators)
Admin settings → Airports page (/airports, administrators) → POST/PUT/DELETE /airports → AirportRequest (manage-airports gate: administrators) → AirportService (transaction, base and in-use guards, "airports" audit event) → AirportResource. Flight routes read airports from GET /lookups.

## Fleet status and maintenance
Fleet page → GET /aircraft (+ due items) → status dialog → PATCH /aircraft/{id}/status → AircraftStatusRequest → AircraftService::changeStatus (lock, update, audit in one transaction) → AircraftResource → cards and nav counts refresh.
Maintenance page → POST /maintenance-records → MaintenanceRecordRequest → MaintenanceService::save (hours ordering check, recorded_by, audit) → GET /maintenance-alerts → MaintenanceService evaluates the latest record per airframe and check type against ExpiryService's base-local today and current airframe hours.
Planes: Fleet page aircraft type form (full name) → AircraftTypeRequest; Flights page form → planes checklist (ticked in preference order) → FlightRequest aircraft_ids → FlightService::save (flight_aircraft sync, audited). Build → RosterScheduleService::plan → assignAircraft (manual plane kept; else the trip's previous plane if still free, else the first available listed plane not flying at that time; else keep and warn) → snapshot aircraft fields from the plane's type → seats. Inspector "Change plane" → GET /trips/{id}/aircraft-options → PUT /trips/{id}/aircraft → TripAircraftRequest → TripAircraftService::change (roster lock, editable, not operated, snapshot aircraft fields, RosterBuilderService::syncSeats, audit "aircraft_changed").
Flights page switch → PATCH /flights/{id}/status → FlightService::setActive (audited) → RosterScheduleService::periodsAffectedBy (built rosters that have not ended and now differ for this flight; also after PUT /flights/{id}) → FlightResource with a message naming those rosters.

## Crew and flight patterns
Page loads API list and lookup data. Form sends explicit fields; Form Request rejects malformed input. Services synchronize child records in a transaction and record audit changes. API Resource returns a deliberate response. UI rerenders using text nodes and displays validation messages.
FlightTimelineService checks connectivity, return to base, day boundaries and configured duty/rest limits; UTC snapshots are calculated on the server.

## Roster (1 week, 2 weeks or a month)

Exchange: candidates response includes legal exchange choices per crew member → select the other flight seat → confirm both crew moves → PUT /assignments/{id} {crew_member_id, exchange_assignment_id, expected_crew_member_id} → AssignmentRequest → AssignmentService::exchange under the roster lock → recheck both resulting schedules, assign both holders, lock both seats and audit in one transaction. Invalid or stale exchanges leave both seats unchanged. The editor footer remains visible while its body scrolls.

Same-person date drop: matching open seat on another dated occurrence of the same flight → confirm → PUT /assignments/{source} {crew_member_id, target_assignment_id} → AssignmentRequest → AssignmentService::moveDate under the roster lock → clear source and assign destination with normal legality checks and audit rows in one transaction. Failure rolls back both changes. Trip dates and flight patterns stay intact.
Roster window → GET /roster-periods?from&to (timeline of rosters overlapping the range; gaps are shown as "No roster") → GET /roster-periods/{id} → RosterLegalityService::context (rules snapshot, crew with ratings/documents/activities, the roster's trips and seats, seats in neighbouring rosters reaching back up to roster.max_trip_days for long rotations, fleet availability) → RosterConflictService → RosterWeekResource (filtered to own seats for crew).
Create ("New roster" dialog or the "No roster" view, then build straight away): POST /roster-periods {starts_on, length} → RosterPeriodRequest (Monday for week/fortnight, the 1st for a month, planning window) → RosterPeriodService::create (overlap check, rules snapshot, audit).
Build: POST /roster-periods/{id}/build {route_changes?} → RosterBuildRequest → lock the roster row → RosterPeriodService::assertEditable → RosterScheduleService::plan (enabled patterns from today, FlightTimelineService::validate against the snapshot rules → UTC schedule snapshot) → RosterScheduleService::changes; when a built roster has pending changes the request must carry their fingerprint (422 route_changes otherwise) → create, refresh or remove trips to match the plan (removed trips take their seats, locked ones included) and ensure seats → release automatic seats (manual seats and operated trips kept) → hardest seats first → RosterLegalityService::issues + score for every active crew member of the position → assign the lowest legal score or leave open, writing decision_log → built_at/built_by and audit, all in one transaction. Per-build caches in PlanningContext (trip snapshot facts, crew fields, documents, ratings) and Duty (dates) keep a monthly build to a few seconds; the build request raises PHP's time limit to roster.build_time_limit where the host allows it.
Drag and drop: crew grid duty or open seat (draggable while the trip can change) → crew row of the same position → PUT /assignments/{id} {crew_member_id} (422 with override_reason → seat editor with that crew member chosen) | Open time row → confirm → PUT {crew_member_id: null}.
Published edits: AssignmentService and TripAircraftService use RosterPeriodService::assertChangeable (not ended) → RosterPeriodService::pendingChanges (duty lines vs published_duties) → roster notice "Email changed crew" → POST /roster-periods/{id}/email {only: changed} → RosterEmailService (changes listed, then markNotified for sent or queued crew).
Edit: seat editor → GET /assignments/{id}/candidates → PUT /assignments/{id} → AssignmentRequest → AssignmentService::assign (lock week, editable and not operated, hard problems refused, reason required for other problems, flag_reasons, decision_log, audit).
Route changes: GET /roster-periods (staff: route_changes_count per roster) and GET /roster-periods/{id} (staff: route_changes) → RosterScheduleService::changes → "Routes changed" timeline chip, roster notice and the review dialog → confirm → POST build {route_changes: fingerprint} → audit "built" with the applied list. Crew never receive route changes.
Publish: POST /roster-periods/{id}/publish → built and no blocking conflict → status published (audited). Each crew member's duty lines are recorded (published_duties); publishing again compares them and stores changed_crew (removed and added duties), and the roster window offers POST /roster-periods/{id}/email {only: changed} → RosterEmailService (just those crew, "Roster changed" subject and a changes box).
Captain sections: inspector → POST /assignments/{id}/split {after_leg} → AssignmentSplitRequest → AssignmentService::split (week lock, captain seats only, stop inside the section, later sections renumbered, new open section seat, audit) or POST /assignments/{id}/join → AssignmentService::join (joined seat open, holders released, audit). PlanningContext::seatDuty and Section::restrict give every check, total, export and email the captain's own section. Reopen: POST /roster-periods/{id}/reopen → draft (audited).
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
