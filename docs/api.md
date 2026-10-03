# API v1

Base path: /api/v1. JSON request/response, Accept: application/json. Authentication: Sanctum session cookie for Blade; scoped bearer tokens for controlled integrations. Laravel Resources wrap records in data; collections include links/meta pagination. Maximum page size is fixed at 100 for management data and 12 roster periods.

## Response envelope
All JSON responses (API and the JSON session endpoints) are built by app/Support/Api/ApiResponse and app/Support/Api/ApiExceptionRenderer. Wording, codes and envelope options are customised in config/api.php, not in controllers.

```json
{"success": true, "message": "Flight LB1 disabled — it stays on file but will not be planned.", "data": {...}}
{"success": false, "message": "The requested aircraft could not be found. It may have been removed.", "code": "not_found"}
{"success": false, "message": "Give a reason when an aircraft is not available.", "code": "validation_failed", "errors": {"reason": ["..."]}}
```

- Changes (POST/PUT/PATCH/DELETE) include a user-facing message; reads omit it. Deletes and logout return 200 with a message instead of 204.
- Error codes: validation_failed (422), unauthenticated (401), forbidden (403), not_found (404), method_not_allowed (405), conflict (409), session_expired (419), too_many_requests (429, with Retry-After), server_error (500), unavailable (503).
- 500 responses never include exception details unless both APP_DEBUG and API_EXPOSE_DEBUG are true, in which case a debug block is added.
- Browser page requests (non-JSON) keep Laravel's normal HTML error pages.

## Session
- GET /sanctum/csrf-cookie — establish CSRF cookie.
- POST /login — {"login":"admin or user@example.com","password":"…"}; login accepts an email address or a username (case-insensitive; usernames cannot contain @). Web middleware, throttled per login+IP, JSON UserResource. Validation errors are keyed "login".
- POST /logout — invalidate browser session; CSRF required. Returns 200 {"success": true, "message": "You have signed out."}.
- GET /api/v1/me — current account safe fields.
- GET /api/v1/my-profile — own linked crew profile, requires roster:read.

Send the decoded XSRF-TOKEN cookie as X-XSRF-TOKEN on state-changing requests. Use same-origin credentials. Errors: 401 unauthenticated, 403 unauthorized, 404 missing, 419 CSRF/session expired, 422 validation, 429 rate limit.

## Operational resources
Staff (scheduler or crew_control), roster:read for reads and roster:write for writes.
- GET /lookups — airports with base status and aircraft types.
- GET/POST /aircraft-types; GET/PUT/DELETE /aircraft-types/{id}.
- GET/POST /crew-members; GET/PUT/DELETE /crew-members/{id}. List filters rank=CPT|FO|CC, base=LLW.
- GET/POST /flights; GET/PUT/DELETE /flights/{id}.
- GET /rules; PUT /rules is scheduler-only.
- PATCH /flights/{id}/status — {"active": false}; enables or disables a pattern without resubmitting legs (audited as enabled/disabled).
- GET/POST /aircraft; GET/PUT/DELETE /aircraft/{id} — airframes. Payload {"registration":"7Q-TBA","aircraft_type_id":1,"airframe_hours":15234.5,"notes":null}. Responses include status, status_label, status_reason, status_changed_at (UTC) and maintenance_due items.
- GET /airports (staff) — every airport with crew_count and leg_count. POST /airports {"code":"EBB","name":"Entebbe","utc_offset_minutes":180,"is_base":false}, PUT /airports/{code} (name, utc_offset_minutes, is_base; the code cannot change), DELETE /airports/{code} — administrators only (Admin settings / Airports page); 422 when removing the base flag from an airport with crew or route starts, or deleting one in use.
- PATCH /aircraft/{id}/status — {"status":"unavailable","reason":"Bird strike inspection"}; status is available, maintenance, grounded or unavailable (the Fleet page uses available/unavailable); the reason is optional.
- GET/POST /maintenance-records (filter aircraft_id); GET/PUT/DELETE /maintenance-records/{id}. Payload {"aircraft_id":1,"kind":"a_check","title":"A-check 4A","performed_on":"2026-09-28","airframe_hours_at":15000,"next_due_on":"2027-01-28","next_due_hours":15600,"notes":null}. recorded_by is set from the caller.
- GET /maintenance-alerts — overdue then due-soon items: {state, days_remaining, hours_remaining, record}.
- GET /overview — counts for crew (incl. document expiry), flights, fleet status and maintenance alerts.
- Aircraft-type list and flight responses include aircraft_count and available_aircraft_count for the type.

PUT is a complete editable resource replacement. Do not omit child arrays: rating_ids/documents and weekdays/legs are explicitly synchronized. Laravel also registers PATCH for resource controllers, but it currently has the same complete-payload validation contract.

### Aircraft payload
```json
{"code":"Q400","cabin_crew_required":2,"palette":"forest"}
```
Palette: forest, gold, sky, plum, coral. Aircraft referenced by flights or ratings cannot be deleted.

### Crew payload
```json
{"name":"Example Pilot","email":"pilot@example.com","rank":"CPT","base_airport":"LLW","active":true,"all_aircraft":false,"weekly_hours":40,"rating_ids":[1],"documents":[{"kind":"licence","expires_on":"2027-03-31"}]}
```
Documents: licence, medical, recurrent; optional entries, one per kind. Missing dates are not implied valid. Only CC may use all_aircraft=true (with empty rating_ids). weekly_hours (1–168, optional) is the contracted duty time per week that the roster generator never exceeds. Crew linked to accounts or roster/email history cannot be deleted; mark inactive.

### Flight payload
```json
{"code":"LB1","aircraft_type_id":1,"active":true,"weekdays":[0,2,4],"legs":[{"trip_day":1,"from_airport":"LLW","to_airport":"BLZ","departs_local":"08:00","arrives_local":"09:00"},{"trip_day":1,"from_airport":"BLZ","to_airport":"LLW","departs_local":"10:00","arrives_local":"11:00"}]}
```
Weekday 0=Monday. Legs are ordered in the payload; sequence is server-assigned within each trip day. Leg times are base-local unless the payload sets "time_zone":"utc" (the Flight routes page does), in which case they are converted with the rules' UTC offset; responses give both departs_local/arrives_local and departs_utc/arrives_utc. trip_day goes up after a night stop (a gap of n days = n nights at the outstation), at most roster.max_trip_days; a night stop at base is refused. Overnight arrival rolls to the next date. Leg connections, base return, max daily duty and night-stop rest are enforced against the current rules. Flights with trip history cannot be deleted; mark inactive.

## Weekly rosters
Rosters are weekly: Monday to Sunday in base-local dates. Times inside a trip are UTC instants plus base-local clock strings from the week's frozen rules snapshot.

- GET /roster-periods?from=2026-09-01&to=2026-11-30 — rosters overlapping the range (at most 60 weeks), oldest first. Each item: id, starts_on, ends_on, length (week|fortnight|month), days, iso_week, label, status (draft|published), built_at, published_at, editable, ended. Staff also get trips_count, seats_count, open_seats_count. Crew only receive published rosters.
- POST /roster-periods — {"starts_on":"2026-10-05","length":"fortnight"} (week and fortnight start on a Monday, month on the 1st; about 26 weeks back to 104 ahead), staff with roster:write. Creates the draft roster with a rules snapshot; 201 when new, 200 when the same start and length exist, 422 when the dates overlap another roster.
- GET /roster-periods/{id} — the roster window: period fields plus summary, trips (schedule, duties with legs, seats with crew, source auto|manual, flag_reasons, decision_log), crew rows (weekly_hours, period_duty_minutes, period_block_minutes, activities in the roster) and live conflicts. Crew accounts receive a published week only (404 otherwise), reduced to their own seats and activities, without decision logs or conflicts.
- POST /roster-periods/{id}/build — run the roster generator on a draft week that has not ended (422 otherwise). Expands enabled patterns from today onwards, keeps manual (locked) seats and operated trips, fills seats hardest-first with the legal crew member who has the lowest fairness score, and leaves a seat open when nobody is legal. Returns the roster window; summary.build = {trips, seats, filled, open, kept, skipped, standby, rebalanced, planned_trips, reasons}. The message explains an empty build and names the most common reasons seats stayed open.
- POST /roster-periods/{id}/publish — release a built draft to crew. 422 while any rule conflict is neither fixed nor accepted with an override. Open seats do not block publishing.
- POST /roster-periods/{id}/reopen — published week back to draft (not for weeks that have ended).
- GET /assignments/{id}/candidates — active crew of the seat's position: legal, overridable, issues [{code, message}], score, week_minutes, capacity_minutes; legal first.
- PUT /assignments/{id} — {"crew_member_id":12,"override_reason":null}. Assigning locks the seat (source manual, kept on rebuild); null clears it back to the generator. When the choice breaks a rule the response is 422 listing the problems under crew_member_id plus an override_reason error; resend with a reason to accept them (stored as flag_reasons and audited as overridden). Inactive crew, the wrong position and a second seat on the same trip can never be overridden. Seats on published or ended weeks and on trips that have already operated cannot change.
- GET /dashboard — staff only: overview counts, last/this/next/following week status with seat and conflict summaries, conflicts for this and next week (from today, open seats excluded), today's trips, non-available airframes, maintenance alerts and crew document alerts.

Conflict codes: inactive, rank, base, rating, document_missing, document_expired, excluded, already_on_trip, unavailable (leave, day off, untimed SIM/standby), overlap, rest, duty_7d, block_month, consecutive_days, days_off_month, weekly_hours (severity danger); open_seat, flight_disabled, no_aircraft (severity warning). Conflicts are recalculated on every request, never stored.

- POST /assignments/{id}/exclude — never assign the current holder to this trip again (manually or by a rebuild); the seat opens. DELETE /exclusions/{id} lifts it.
- POST /assignments/{id}/undo — restore the seat as it was before its last manual change (one step). 422 when there is nothing to undo or the previous holder now has another seat on the trip.
- Assigning a flight to someone with standby on its days clears that standby in the same transaction (candidates report clears_standby).
- GET /roster-periods/{id}/export.csv — staff: every seat; crew: own seats of a published week (404 otherwise). Cells starting with = + - @ are prefixed with an apostrophe.
- GET /roster-periods/{id}/calendar.ics?crew_member_id= — one crew member's duties (UTC events, base-local times in the description) and activities. Crew always get their own; staff must name the crew member.
- POST /roster-periods/{id}/email — published rosters only: queues one email (day-by-day roster with the crew on each flight, planned days and night stops, plus an .ics file) per crew member with a seat, logged in email_logs (queued → sent | failed). Crew without an email address are counted as missing. A queue worker must run (php artisan queue:work).
- GET /roster-periods/{id}/email-logs — delivery status per crew member.

## Day planning
- POST /crew-activities — {"crew_member_id":5,"type":"leave|day_off|sim|standby","date_from":"2026-10-12","date_to":"2026-10-14","starts_local":"06:00","ends_local":"10:00","note":null}. One item per date (max 31 days); 422 listing dates already planned. Times only for SIM and standby, base local, stored as UTC with the rules' offset; an end at or before the start runs past midnight.
- DELETE /crew-activities/{id}.
- The roster generator also plans standby (crew_activities.roster_period_id set, "generated" in responses) when a crew member would exceed max_days_off_week; a rebuild replaces it.

## Hours
- GET /crew-hours?group=pilots|cabin — staff: per crew member and group totals for week, month, days_28, year and months_12: block_minutes and duty_minutes flown (released), scheduled_block_minutes and scheduled_duty_minutes still to come, trips. Only published weeks count; timed SIM/standby count as duty. Includes max_block_month_h and window dates.
- GET /my-hours — the same for the caller's own crew profile (pilot and cabin crew accounts).

## Accounts
Roles: admin, scheduler, crew_control, crew. Pilot and cabin crew accounts use role crew with crew_member_id (one account per crew member); "Pilot" or "Cabin crew" comes from the crew member's position.
- GET /accounts — administrators: every account; schedulers: pilot and cabin crew accounts only; others 403.
- POST /accounts — {"name","email","username","role","crew_member_id","password","password_confirmation"}. Administrators may give any role; schedulers only crew (422 otherwise). Passwords: at least 12 characters.
- PUT /accounts/{id} — same payload; blank password keeps the current one; a new password revokes that person's sessions and tokens. Schedulers get 403 for staff accounts. Administrators cannot remove their own admin role, and at least one administrator must remain.
- DELETE /accounts/{id} — administrators only; never your own account or the last administrator. Sessions and tokens are revoked; audit history keeps the record with the user cleared.
- PUT /me/password — {"current_password","password","password_confirmation"} for any signed-in user; signs out other sessions.

## PDF files
- GET /roster-periods/{id}/roster.pdf?layout=grid|crew&crew_member_ids[]= — staff: the whole week as a crew × day grid (landscape, default) or one page per crew member (all with duties or plans, or the selected crew); crew: their own page of a published week (404 otherwise). Rendered by dompdf with the airline logo (ROSTER_PDF_LOGO=false to omit), page numbers and repeated table headings.

## Reports
Staff with roster:read.
- GET /reports — {today, types: [{key, title}]}.
- GET /reports/{type}?from=YYYY-MM-DD&to=YYYY-MM-DD&group=pilots|cabin — types: coverage, hours, overrides, documents, maintenance, flights, audit. Period at most 366 days. Response: {type, title, description, period, columns [{key, label, align}], rows, summary {label: value}, notes}; rows may carry _tone {column: success|warning|danger}.
- GET /reports/{type}/export.csv|export.pdf — the same report as a formula-safe CSV or a PDF.

## CSV import
Staff with roster:write.
- GET /imports/templates/{crew|flights|activities} — template with headings and example rows.
- POST /imports/preview (multipart: kind, mode=merge|replace, times=local|utc for flights, file ≤ 2 MB, ≤ 5,000 rows) — {token, delimiter, rows [{line, action: create|update|unchanged|error|deactivate|disable|delete, label, messages}], summary}. Nothing is saved.
- POST /imports/commit {token} — applies the previewed rows in one transaction through the normal services (validated and audited). 422 when any row has an error, the preview expired (30 minutes) or the data changed since the preview.
Formats: comma, semicolon or tab; headings matched by alias (e.g. "Full name", "Home base"); dates as YYYY-MM-DD, DD/MM/YYYY, DD.MM.YYYY, 5 Oct 2026 or Excel serial numbers. Crew are matched by name (a name shared by two crew members is an error), flights by code. Flight days: "Mon Wed Fri", "1,3,5" (Monday = 1), "1.1.1.." or "daily"; legs: "LLW-BLZ 08:00-09:00; D2 BLZ-LLW 08:00-09:00". Replace mode deactivates crew / disables flights missing from the file, or replaces listed crew members' planning in the dates the file covers.

## Backup and restore
Administrators only.
- GET /backups/download — JSON {format: "malawi-roster-backup", version: 1, created_at, created_by, tables}. Tables: airports, aircraft_types, aircraft, maintenance_records, rule_sets, crew_members, crew_ratings, crew_documents, flights, flight_days, flight_legs, roster_periods, trips, assignments, exclusions, crew_activities, email_logs. Never users, passwords, sessions, tokens, reset tokens or the audit log.
- POST /backups/inspect (multipart: file ≤ 50 MB) — validates format, version, tables and columns; returns {token, created_at, created_by, tables [{table, backup, current}]}.
- POST /backups/restore {token, confirmation: "RESTORE"} — replaces all operational data in one transaction; accounts are kept and their crew links re-attached; references to missing users are cleared; audited.

## Password reset (web, guests)
- POST /forgot-password {login} — email or username; always the same answer. Accounts without an email cannot reset themselves.
- GET /reset-password/{token}?email= — page from the emailed link.
- POST /reset-password {token, email, password, password_confirmation} — single-use token, expires after config('auth.passwords.users.expire') minutes; signs the person out everywhere. Throttled (password-reset limiter).

Audit rows are transactional and are not exposed through a public API. API Resources exclude secrets and peer crew details from personal roster responses.
