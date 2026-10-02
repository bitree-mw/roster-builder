# Implementation status

## Included in the Laravel foundation
- Laravel 13, Sanctum, Boost, Tailwind 4/Vite and PHPUnit.
- Domain migrations, Eloquent relationships/casts and factories.
- Session login/logout, role gates, token scopes, API throttling and audit writes.
- API + Blade workflows for aircraft types, crew/documents/ratings, flight patterns/legs and duty rules.
- Flight enable/disable, airframe registry with available / in maintenance / grounded (AOG) / unavailable status, maintenance records with date- and hour-based due alerts, crew document expiry states, and an overview endpoint for KPIs and navigation badges.
- Operations-console UI from uibuilder/ (navy/flight-strip design system, Malawi Airlines logo, redesigned sign-in, KPI strips, alert panels, responsive layouts).

Not yet connected: aircraft availability does not yet constrain planning (there is no planner), alerts are shown in the app only (no email/notification delivery), airframe hours are entered manually (no flight-log integration), and maintenance check intervals are not configured per type — each record states its own next due point.
- Draft roster periods and authorized roster read API.
- Demo operational data and opt-in local credentials.
- Common/page-specific CSS and JavaScript, centralized theme tokens.
- Automated API/security/validation/time tests and build checks.

## Next implementation milestones
1. Planning engine: trip expansion, full legality evaluator, boundary history, candidate ranking, fair rebalancing, workload standby, explanation logs. Use fixed-time deterministic scenario tests for all rule boundaries.
2. Crew activities editor, assignment lock/exclusion/undo API and UI; legality flags, expiry alerts, complete roster grid and individual view.
3. Publication workflow, revision conflicts and immutable published snapshots.
4. Shared range-aware HTML/CSV/ICS/PDF rendering, authorized artwork, queued attachment emails and delivery logs.
5. CSV templates, preview/merge/replace, versioned backup and restore, compatibility importer.
6. PWA read-cache and secure sync policy, then Capacitor iOS wrapper if required.
7. Password recovery, account administration, operational monitoring and deployment hardening.

The foundation is not a completed automatic roster planner and must not be represented as operationally approved aviation scheduling software. The source brief is a product specification, not a verified statement of aviation regulations.
