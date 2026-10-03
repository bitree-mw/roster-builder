# Implementation status

## Included in the Laravel foundation
- Laravel 13, Sanctum, Boost, Tailwind 4/Vite and PHPUnit.
- Domain migrations, Eloquent relationships/casts and factories.
- Session login/logout, role gates, token scopes, API throttling and audit writes.
- API + Blade workflows for aircraft types, crew/documents/ratings, flight patterns/legs and duty rules.
- Flight enable/disable, airframe registry with an Available / Not available switch on the Fleet page (maintenance and AOG kept as API variants), maintenance records with date- and hour-based due alerts, crew document expiry states, and an overview endpoint for KPIs and navigation badges.
- Operations-console UI from uibuilder/ (navy/flight-strip design system, Malawi Airlines logo, redesigned sign-in, KPI strips, alert panels, responsive layouts).
- Rosters of 1 week, 2 weeks or a calendar month with a timeline, individual rosters per crew member, and day planning (leave, day off, standby, SIM over a date range) from empty calendar cells, a server-side roster generator (expansion of enabled patterns, eligibility, documents, leave, rest, seven-day duty, monthly block, consecutive days, monthly days off and crew weekly working hours; hardest seats first; fairness by share of working hours used), manual seat edits with recorded overrides, live conflict detection, publish/reopen, and an operations dashboard.
- Post-build rebalancing of working hours, planned standby for the maximum weekly days off, exclusions and one-step undo, day planning editor, standby cleared by flight assignment.
- CSV and iCalendar exports, print view, queued roster emails with delivery logs.
- Flight routes entered in GMT, legs chained from the previous destination, night stops (one or more nights, outstations only) asked for when adding a leg, rotations up to ROSTER_MAX_TRIP_DAYS days with the same crew until back at base, and layover days shown on the roster.
- Light and dark themes.
- Airport administration (route destinations and crew bases) for administrators; 24-hour HH:MM time entry everywhere; delete buttons shown in red.
- Accumulated block and duty hours for pilots and cabin crew; account administration (administrator, scheduler, crew control, pilot, cabin crew) and own password change.
- PDF files (dompdf): roster grid, per-crew pages and every report. Reports page with seven reports as table, CSV and PDF.
- CSV import of crew, flight patterns and day planning with preview and atomic commit; versioned JSON backup and restore; self-service password reset by email.

Not yet connected: aircraft availability is reported as a roster warning but does not stop trips being planned, alerts are shown in the app only (no email/notification delivery), airframe hours are entered manually (no flight-log integration), and maintenance check intervals are not configured per type — each record states its own next due point.
- Demo operational data and opt-in local credentials.
- Common/page-specific CSS and JavaScript, centralized theme tokens.
- Automated API/security/validation/time tests and build checks.

## Next implementation milestones
1. Planning engine hardening: boundary scenario tests for every rule, standby demand per base (today standby only fills individual workload gaps), and operational validation of every rule.
2. Calendar-month duty limits across roster boundaries are checked, but there is no dedicated multi-roster (e.g. quarterly) view; a night at the outstation is not yet treated differently from a day off at home for the monthly days-off rule.
3. Revision conflicts and immutable published snapshots (publish and reopen exist; a reopened roster is edited in place).
4. Confirm usage rights for the airline logo on distributed PDFs; scheduled (automatic) backups to off-site storage.
5. A compatibility importer for the prototype's crew-roster-v2 export, and import of airframes and maintenance history.
6. PWA read-cache and secure sync policy, then Capacitor iOS wrapper if required.
7. Account lockout policy, operational monitoring and deployment hardening.

The foundation is not a completed automatic roster planner and must not be represented as operationally approved aviation scheduling software. The source brief is a product specification, not a verified statement of aviation regulations.
