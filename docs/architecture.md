# Architecture

## Required delivery sequence
Requirements/flow → Migration → Model → Form Request → Service → Controller → API Resource → Routes → Tests → Blade frontend.

Start each feature in docs/requirements.md and docs/flows.md. Evolve the schema with migrations, define relationships/casts, validate and authorize with Form Requests, implement business operations in services, coordinate HTTP in thin controllers, serialize explicit API Resources, register versioned routes, test the contract, then connect the Blade page.

## Technology and boundaries
Laravel 13, PHP 8.4.1+ (development: Herd PHP 8.4), Sanctum 4, dompdf 3 (PDF files), PHPUnit 12, Blade, plain JavaScript modules, Tailwind 4 and Vite. SQLite is the local default; use a shared relational database for deployment. The browser fetches /api/v1; Blade only renders shells and navigation. Never query domain data or implement legality in Blade/JavaScript.

This server architecture supersedes the prototype's localStorage source of truth, framework-free constraint, static GitHub Pages hosting, and fully offline writes. No roster or credentials belong in localStorage. Offline sync, PWA and Capacitor are future clients requiring an explicit conflict/security design. Runtime assets are bundled locally; no external fonts or CDN scripts.

## Access
Sanctum stateful same-origin session cookies protect the Blade client. Login/logout are JSON endpoints in the web middleware stack, with session rotation, CSRF and throttling. API endpoints use auth:sanctum. Gates combine role authorization with token abilities (roster:read, roster:write). Scheduler and crew_control manage operational data. Only scheduler changes rule sets. Crew can read only their own profile and published personal roster. No public registration or client-controlled role changes.

## Storage
Airports include base eligibility. Aircraft types have a semantic palette key, never a color value. Crew ratings are a many-to-many pivot; all_aircraft is allowed only for cabin crew. Documents and activities have independent rows. Patterns have weekdays and ordered legs across trip days (night stops at outstations, up to roster.max_trip_days); one trip keeps the same crew until it is back at base. Trips preserve a schedule snapshot; assignments retain source, decision log and flags. Audit rows are written inside the same transaction as operational mutations.

Pattern times use base-local wall time with an explicit offset in minutes, matching the source brief. Dated trip snapshots use UTC ISO 8601 instants plus base-local clock times and dates computed on the server; roster periods (a week, a fortnight from a Monday or a calendar month) use base-local dates. Flight times are entered in GMT and converted with the rules' offset. Report/release calculations must use immutable dates and handle midnight. Offsets belong to rule snapshots; changing rules must not reinterpret existing trips. Days-off and monthly totals use base-local calendar dates. Never use browser timezone implicitly.

## Planner boundary
Roster planning lives in dedicated services: RosterLegalityService (one place for every eligibility and duty-limit check, plus the fairness score), RosterBuilderService (the generator), RosterConflictService (live conflicts), AssignmentService (manual seat edits) and RosterPeriodService (create, publish, reopen, edit guards). app/Support/Roster holds their value objects (Duty, CrewSchedule, PlanningContext). CrewActivityService (day planning), CrewHoursService (accumulated hours), RosterExportService (CSV, iCalendar) and RosterEmailService with the SendRosterEmail job (queued roster emails) build on the same planning context. AccountService owns account administration. ReportService builds every report (one shape for JSON, CSV and PDF); PdfService renders resources/views/pdf with dompdf (the PDF stylesheet resources/css/pdf/document.css takes its colours from tokens.css at render time); App\Support\Csv reads and writes CSV; ImportService previews and commits CSV imports; BackupService exports and restores operational data; PasswordResetService handles self-service resets. A build is atomic, serialized by a row lock on the roster period, deterministic, and preserves manual seats and trips that have already operated. Publication requires a build and no unaccepted rule conflict. The generator is a planning aid built on configurable defaults; it is not operationally approved and its rules need operational validation.

## Frontend assets
resources/css/app.css imports Tailwind and common tokens/components.
resources/css/common/{tokens,components}.css own colors and reusable styles.
resources/css/pages/*.css and resources/js/pages/*.js are page-scoped Vite entries.
resources/js/common/{api,ui}.js own CSRF-aware fetch and safe DOM helpers; toast.js shows pop-up notifications (api() raises them automatically from the server message on every change and on errors) confirm.js replaces window.confirm, and roster.js holds timezone-free week/date helpers shared by the dashboard and roster window. theme.js toggles light/dark: tokens.css defines every colour as light-dark() pairs, the choice is stored in an unencrypted "theme" cookie so Blade sets data-theme before first paint, and PDFs always use the light values.
app/Support/Api owns the JSON response envelope and API exception rendering; wording and codes live in config/api.php.
No inline scripts/styles, hardcoded colors in templates/JS, third-party CDNs or frontend business rules. Use textContent for API text, accessible forms, keyboard focus and visible error/empty/loading states.

## Verification
Run php artisan test --compact, vendor/bin/pint --dirty --format agent, npm run build and php artisan route:list. Test unauthenticated access, denied roles, token scopes, crew ownership, validation, transactions, restricted deletion and time conversion. Run migration rollback on disposable test databases only.
