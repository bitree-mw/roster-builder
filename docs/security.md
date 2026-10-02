# Security model

Use HTTPS in production; APP_DEBUG=false; SESSION_SECURE_COOKIE=true; same-origin frontend and API. Restrict SANCTUM_STATEFUL_DOMAINS to actual application hosts (including local ports). Do not use wildcard CORS with credentials.

Roles: admin (everything, including all accounts), scheduler (operations, rules, pilot and cabin crew accounts; never deletes accounts), crew_control (operations, no accounts), crew (own published roster, calendar and hours only). Account changes check the target's role before validation, keep at least one administrator, never let administrators demote or delete themselves, and revoke sessions and tokens when someone else sets a password or deletes the account.

Role and token scope are both required. Browser sessions receive Sanctum's transient token; role gates still apply. Integration tokens need roster:read for reads and roster:write for writes, plus an authorized user role. No token issuance API or public registration is shipped. Accounts are created by administrators and schedulers in the Accounts page, or with php artisan roster:create-user.

Crew endpoints scope to users.crew_member_id and published periods. Management lists, candidate explanations and peer documents are unavailable to crew users. Unknown/unsupported roles fail closed. Do not expose passwords, remember tokens, tokens, or complete User models in JSON.

All changes use validated inputs and transactional services. Audit records contain operational changes only, never login passwords/tokens. Restrict database/backups and audit retention under an agreed organizational policy.

Production seeding creates reference data only; DemoSeeder refuses production. Demo login requires DEMO_EMAIL and DEMO_PASSWORD supplied locally. No committed credentials, reset endpoint or destructive sample reset UI.

Password reset: links are single-use, hashed, expire after config('auth.passwords.users.expire') minutes and are throttled; the request answer never reveals whether an account exists; a reset revokes all sessions and tokens. Backups (administrators only) contain crew personal data but never accounts, passwords, sessions, tokens or reset tokens; restore validates the format and every column, needs the typed word RESTORE, runs in one transaction and is audited. Imports are previewed, refused while rows have errors or the data changed, and committed through the same validated, audited services as manual edits. CSV exports neutralise spreadsheet formulas; PDFs are rendered with remote resources and PHP disabled.

Before production: implement account lifecycle/recovery, review least privilege, choose database and backup retention, configure queue/email services, run concurrency/load/security review, and obtain operational validation of every legality rule.
