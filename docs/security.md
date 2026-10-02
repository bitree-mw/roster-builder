# Security model

Use HTTPS in production; APP_DEBUG=false; SESSION_SECURE_COOKIE=true; same-origin frontend and API. Restrict SANCTUM_STATEFUL_DOMAINS to actual application hosts (including local ports). Do not use wildcard CORS with credentials.

Role and token scope are both required. Browser sessions receive Sanctum's transient token; role gates still apply. Integration tokens need roster:read for reads and roster:write for writes, plus an authorized user role. No token issuance API or public registration is shipped. Create accounts through controlled operator tooling until account administration is implemented.

Crew endpoints scope to users.crew_member_id and published periods. Management lists, candidate explanations and peer documents are unavailable to crew users. Unknown/unsupported roles fail closed. Do not expose passwords, remember tokens, tokens, or complete User models in JSON.

All changes use validated inputs and transactional services. Audit records contain operational changes only, never login passwords/tokens. Restrict database/backups and audit retention under an agreed organizational policy.

Production seeding creates reference data only; DemoSeeder refuses production. Demo login requires DEMO_EMAIL and DEMO_PASSWORD supplied locally. No committed credentials, reset endpoint or destructive sample reset UI.

Before production: implement account lifecycle/recovery, review least privilege, choose database and backup retention, configure queue/email services, run concurrency/load/security review, and obtain operational validation of every legality rule.
