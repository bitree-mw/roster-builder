# API v1

Base path: /api/v1. JSON request/response, Accept: application/json. Authentication: Sanctum session cookie for Blade; scoped bearer tokens for controlled integrations. Laravel Resources wrap records in data; collections include links/meta pagination. Maximum page size is fixed at 100 for management data and 12 roster periods.

## Session
- GET /sanctum/csrf-cookie — establish CSRF cookie.
- POST /login — {"login":"admin or user@example.com","password":"…"}; login accepts an email address or a username (case-insensitive; usernames cannot contain @). Web middleware, throttled per login+IP, JSON UserResource. Validation errors are keyed "login".
- POST /logout — invalidate browser session; CSRF required.
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
- PATCH /aircraft/{id}/status — {"status":"grounded","reason":"Bird strike inspection"}; status is available, maintenance, grounded or unavailable; reason required unless available.
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
{"name":"Example Pilot","email":"pilot@example.com","rank":"CPT","base_airport":"LLW","active":true,"all_aircraft":false,"rating_ids":[1],"documents":[{"kind":"licence","expires_on":"2027-03-31"}]}
```
Documents: licence, medical, recurrent; optional entries, one per kind. Missing dates are not implied valid. Only CC may use all_aircraft=true (with empty rating_ids). Crew linked to accounts or roster/email history cannot be deleted; mark inactive.

### Flight payload
```json
{"code":"LB1","aircraft_type_id":1,"active":true,"weekdays":[0,2,4],"legs":[{"trip_day":1,"from_airport":"LLW","to_airport":"BLZ","departs_local":"08:00","arrives_local":"09:00"},{"trip_day":1,"from_airport":"BLZ","to_airport":"LLW","departs_local":"10:00","arrives_local":"11:00"}]}
```
Weekday 0=Monday. Legs are ordered in the payload; sequence is server-assigned within each trip day. Base-local time input is explicit in this initial editor; GMT-entry switching is a future frontend feature. Overnight arrival rolls to the next date. Leg connections, base return, max daily duty and night-stop rest are enforced against the current rules. Flights with trip history cannot be deleted; mark inactive.

## Roster periods
- GET /roster-periods?month=2026-10 — staff sees drafts/published; crew sees published periods and only own trips/assignments. Requires roster:read and an allowed role (crew must have a linked profile).
- POST /roster-periods — {"month":"2026-10"}, staff with roster:write. Creates an empty draft with a rule snapshot, idempotent by month. Allowed window: six months back to 24 ahead.
- No build, publish, override, export or import endpoint is advertised until implemented and tested.

Audit rows are transactional and are not exposed through a public API. API Resources exclude secrets and peer crew details from personal roster responses.
