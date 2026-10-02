# Architecture

## Required delivery sequence
Requirements/flow → Migration → Model → Form Request → Service → Controller → API Resource → Routes → Tests → Blade frontend.

Start each feature in docs/requirements.md and docs/flows.md. Evolve the schema with migrations, define relationships/casts, validate and authorize with Form Requests, implement business operations in services, coordinate HTTP in thin controllers, serialize explicit API Resources, register versioned routes, test the contract, then connect the Blade page.

## Technology and boundaries
Laravel 13, PHP 8.4.1+ (development: Herd PHP 8.4), Sanctum 4, PHPUnit 12, Blade, plain JavaScript modules, Tailwind 4 and Vite. SQLite is the local default; use a shared relational database for deployment. The browser fetches /api/v1; Blade only renders shells and navigation. Never query domain data or implement legality in Blade/JavaScript.

This server architecture supersedes the prototype's localStorage source of truth, framework-free constraint, static GitHub Pages hosting, and fully offline writes. No roster or credentials belong in localStorage. Offline sync, PWA and Capacitor are future clients requiring an explicit conflict/security design. Runtime assets are bundled locally; no external fonts or CDN scripts.

## Access
Sanctum stateful same-origin session cookies protect the Blade client. Login/logout are JSON endpoints in the web middleware stack, with session rotation, CSRF and throttling. API endpoints use auth:sanctum. Gates combine role authorization with token abilities (roster:read, roster:write). Scheduler and crew_control manage operational data. Only scheduler changes rule sets. Crew can read only their own profile and published personal roster. No public registration or client-controlled role changes.

## Storage
Airports include base eligibility. Aircraft types have a semantic palette key, never a color value. Crew ratings are a many-to-many pivot; all_aircraft is allowed only for cabin crew. Documents and activities have independent rows. Patterns have weekdays and ordered legs across up to four trip days. Trips preserve a schedule snapshot; assignments retain source, decision log and flags. Audit rows are written inside the same transaction as operational mutations.

Pattern times use base-local wall time with an explicit offset in minutes, matching the source brief. Dated trip snapshots use UTC ISO 8601 instants. Report/release calculations must use immutable dates and handle midnight. Offsets belong to rule snapshots; changing rules must not reinterpret existing trips. Days-off and monthly totals use base-local calendar dates. Never use browser timezone implicitly.

## Planner boundary
The initial setup includes roster periods and read contracts, not an approved automatic scheduling engine. Follow docs/roadmap.md before adding a build endpoint. Legality, fairness, manual locks, exclusions, weekly standby and cross-month history belong in dedicated services. A build must be atomic, serialized, deterministic and preserve manual locks. Publication must require a validated build; never label an unvalidated draft as legal.

## Frontend assets
resources/css/app.css imports Tailwind and common tokens/components.
resources/css/common/{tokens,components}.css own colors and reusable styles.
resources/css/pages/*.css and resources/js/pages/*.js are page-scoped Vite entries.
resources/js/common/{api,ui}.js own CSRF-aware fetch and safe DOM helpers.
No inline scripts/styles, hardcoded colors in templates/JS, third-party CDNs or frontend business rules. Use textContent for API text, accessible forms, keyboard focus and visible error/empty/loading states.

## Verification
Run php artisan test --compact, vendor/bin/pint --dirty --format agent, npm run build and php artisan route:list. Test unauthenticated access, denied roles, token scopes, crew ownership, validation, transactions, restricted deletion and time conversion. Run migration rollback on disposable test databases only.
