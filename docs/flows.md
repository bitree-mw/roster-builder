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

## Roster period
Select month → GET /api/v1/roster-periods?month=YYYY-MM. Staff may create a draft via POST. An empty draft remains empty until the future validated planning workflow is implemented. Crew see published own assignments only. Do not offer a build or publish control that has no implemented engine behind it.

## Future build / manual override
Validate period and effective rules → lock period → collect adjacent-month history → expand patterns → retain locks/exclusions → evaluate legality → assign/rebalance → legal standby → persist snapshots, rationale, flags and audit → Resource → UI. Manual override always requires a specific assignment, authorization and explanation. Publish only an assessed snapshot.

## Future exports / imports
Exports use the same authorized roster query and selected range. CSV/ICS escaping and timezone edge cases need tests.
Import upload → parse/normalize → row-level preview → explicit mode → revalidate preview version → atomic import and audit. Backups and restores must never carry credentials or Sanctum tokens.
