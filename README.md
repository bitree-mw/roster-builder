# Malawi Airlines Roster Builder

Laravel API and Blade frontend foundation for Malawi Airlines crew scheduling.

**Implemented:** sign-in, role/scoped API access, aircraft type CRUD, airframe status (available/maintenance/grounded/unavailable), maintenance records with due alerts, flight enable/disable, crew/ratings/documents CRUD, connected flight-pattern CRUD with duty/rest checks, editable duty rules, audited changes, and draft roster periods. **Not yet implemented:** automatic assignment engine, manual roster editor, publication, imports, exports/PDF/email, and offline clients. See [implementation status](docs/roadmap.md).

## Local setup

Requires PHP 8.4.1+, Composer 2, Node 22.12+ (or supported newer Node), and SQLite support.

On this Windows machine, Herd PHP is installed but was missing from PATH. In PowerShell:

```powershell
$env:Path = "C:\Users\ronal\.config\herd\bin\php84;" + $env:Path
php -v
composer -V
composer install
Copy-Item .env.example .env
php artisan key:generate
New-Item database/database.sqlite -ItemType File -ErrorAction SilentlyContinue
php artisan migrate --seed
npm.cmd ci
npm.cmd run build
php artisan roster:create-user scheduler@example.com --name="Crew Scheduler" --username=scheduler --role=scheduler
php artisan serve
```

The account command securely prompts for a password; no default credentials are shipped. Only copy .env on a fresh installation; do not overwrite an existing key or configuration. This working directory already has dependencies, a local key, migrated SQLite, and demo operational data after setup verification. Add Herd's PHP directory to your user PATH if you want regular php/composer commands in future terminals.

For frontend development run `npm.cmd run dev` in a second terminal. On macOS/Linux use `npm` rather than `npm.cmd`. Herd can serve the project directly when linked/parked; its document root must be public/.

Set APP_URL and SANCTUM_STATEFUL_DOMAINS to the hosts/ports you actually use. The example supports roster-builder.test and the local Artisan server. Browser authentication uses cookies and CSRF, following [Sanctum's stateful authentication guidance](https://laravel.com/framework/docs/13.x/sanctum#spa-authentication). No access tokens are stored in browser storage.

## Sample operational data

```sh
php artisan db:seed --class=DemoSeeder
```

Seeds Q400/B737, nine fictional operating patterns, 48 crew with example.com addresses, document dates and sample activities. It is repeatable and preserves edits to existing demo records. It refuses production. To optionally provision a demo scheduler, set DEMO_EMAIL and DEMO_PASSWORD in your local .env first; neither is required and both default to empty. Use the account command for normal account provisioning.

## Architecture and agent guidance

Follow **Requirements/flow → Migration → Model → Form Request → Service → Controller → API Resource → Routes → Tests → Blade frontend**.

- [Architecture](docs/architecture.md) — layers, time/storage decisions and asset conventions.
- [Requirements](docs/requirements.md) and [flows](docs/flows.md) — product specification and feature contracts.
- [API](docs/api.md) — endpoints, payloads and authorization.
- [Security](docs/security.md) — roles, sessions, scopes and deployment considerations.
- [Roadmap](docs/roadmap.md) — implemented foundation and remaining product work.
- [AGENTS.md](AGENTS.md) and [CLAUDE.md](CLAUDE.md) — Boost guidance plus project instructions.

Laravel Boost is installed as a development dependency. Its MCP entry is in .mcp.json; Codex's local configuration is generated in .codex/config.toml. Restart/reload your agent connection if the server is not yet available. Keep `php` available on the agent process PATH. Custom project guidance is maintained in .ai/guidelines/roster.blade.php so future Boost updates preserve it.

## Frontend

Blade renders page shells. Plain JavaScript calls /api/v1; services own data and business logic. Tailwind 4 builds locally through Vite. Colors exist only in resources/css/common/tokens.css. Common CSS/JS and individual page CSS/JS are separate. No inline JavaScript/styles, runtime CDN assets, or client-side roster persistence.

The header and sign-in screen use the supplied Malawi Airlines logo (public/images/malawi-airlines-logo.png). Frontend conventions are captured in the project skill .claude/skills/malawi-ops-frontend/SKILL.md. The original localStorage/static-page/offline-only approach is replaced by a shared Laravel backend; GitHub Pages alone cannot host it.

## Checks

```sh
php artisan test --compact
php vendor/bin/pint --dirty --format agent
npm run build
php artisan route:list --path=api
```

Tests use in-memory SQLite. Never run migrate:fresh or destructive reset commands against a shared database.

## Deployment

Serve public/ with PHP 8.4.1+, install locked dependencies, build assets, configure a shared database, APP_KEY, HTTPS, secure session cookies and APP_DEBUG=false, then migrate and seed reference data. Keep .env, storage, backups and vendor inaccessible from the web root. Do not use DemoSeeder in production. Operational planner validation and the remaining milestones are required before this becomes a live crew scheduling system.
