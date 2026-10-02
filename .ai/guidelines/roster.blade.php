# Malawi Airlines project conventions

Read docs/architecture.md, docs/requirements.md, docs/flows.md, docs/security.md and docs/roadmap.md before changing a feature.

Follow Requirements/flow → Migration → Model → Form Request → Service → Controller → API Resource → Routes → Tests → Blade frontend.
Keep business decisions in app/Services, JSON serialization in explicit API Resources, validation in Form Requests, and Blade as a presentation shell using /api/v1.
Sanctum identifies the caller; role gates and token scopes authorize access. Crew must never see peer records or unpublished roster data.
Use transactions for aggregate edits and corresponding audit rows. Do not mass-assign roles, publication state or ownership from request payloads.
Use App/Casts/LocalDate for calendar-only columns. Pattern times are base-local; dated instants are UTC. Never interpret wall times using browser timezone.
All colors belong in resources/css/common/tokens.css. Keep common CSS/JS and page-specific CSS/JS separate. No inline scripts/styles or runtime CDN dependencies.
Use textContent for API data. Provide accessible loading, empty and error states. Avoid fake build/publish/export buttons for unimplemented features.
No production demo accounts or committed secrets. No sample-data reset without explicit confirmation.
Comment all code you write or change so it can be tracked easily: a PHPDoc block on every class and method (purpose, business rule, parameters/return shapes), a header comment on every JavaScript module and function, section comments in CSS and Blade, and short inline comments only for non-obvious logic. Explain why, not what the syntax does. Replace make:* stub boilerplate with real descriptions.
Run PHP tests, Pint and npm build. Record remaining functionality honestly in docs/roadmap.md; do not claim the planner is finished or legally certified.
