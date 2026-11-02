# AGENTS.md - AI agent entry point

Read this file before touching the repository. The full delegation, coordination and handoff playbook is [docs/ai-agents.md](docs/ai-agents.md). Load the repo skill [.dsh/skills/aipddgen/SKILL.md](.dsh/skills/aipddgen/SKILL.md) before changing code, schema, templates, translations or tests.

## What this is

AI PDD Generator (AIPDDGen): a framework-free **PHP 8.2 + MariaDB** web app on XAMPP that turns carbon-project inputs into citation-backed PDD drafts (Gold Standard / Verra aligned). Africa first, English + French.

Current status: step 1 (data model + auth), 1b (review job queue) and 1c (dashboard split) are done; step 2 (admin template library) is next; steps 3-10 are planned. [docs/PRD.md](docs/PRD.md) is the source of truth for product behavior.

Stack facts: no Composer, no Node/Next.js runtime, no build step. PSR-4 `App\` -> `src/`, custom router in `src/routes.php`, thin controllers, services hold business rules, repositories hold all SQL. Pages are server-rendered PHP templates; small JSON `/api/*` endpoints share the same services.

## Non-negotiable rules

1. **Tenant isolation.** One access gate only: `src/Tenancy.php`. Every repository query filters on `team_id`; cross-tenant access is a **404** (not 403). Never read tenant data around the gate.
2. **Roles.** `viewer < editor < admin < owner`; exactly one owner per team (DB-enforced). In the UI, owner + admin are shown as **Admin/Creator**, editor as **Staff/Worker**, viewer as **Viewer**. Staff console routes return 404 to tenant accounts.
3. **Security.** CSRF token on every state-changing POST; escape every template value with `e()`; PDO prepared statements only (no dynamic SQL with user input); CSP `self`; tokens stored only as SHA-256 hashes.
4. **Privacy.** Customer uploads are private to their workspace; no path from project data into the shared template library; no model training on customer data; deletion within the 30-day window (step 10).
5. **Citations and numbers.** Every generated claim cites its input/fact; unsupported content says "needs input" - never invented. Numbers (areas, tCO2e, dates, coordinates) are copied from the confirmed fact sheet; the model never creates them.
6. **Drafts and approval.** AI output is always labelled a draft; no drafting before the fact sheet is approved; `drafts.is_labeled_draft` stays true.
7. **Language.** English + French move together: edit `lang/en.php` and `lang/fr.php` in the same change; key parity is asserted by `tests/unit_i18n_test.php`. English is the registry language; French output is a working draft and must be labelled as such.
8. **Templates as data.** Registry/template rules are versioned data (`template_sections.format_rules`), never hard-coded prompts. Gold Standard v1.4 headings/columns stay unchanged; dates `DD/MM/YYYY`; thousands commas + decimal point; unique figure/table IDs; non-applicable sections marked intentionally blank.
9. **Migrations are history.** Never edit an applied migration (0001-0010 today). Add a new numbered `migrations/000N_name.sql`, additive, importable on MariaDB 10.5+ (numeric generated columns, never CHAR expressions - ADR-011).
10. **No new runtime layer.** No Composer, no Node, no build step, no new dependency without Lead/owner approval. Server-rendered pages first; JavaScript is progressive enhancement only.
11. **Secrets never in the repo.** `.env.local`, `RESEND_API_KEY`, Google OAuth secrets, `DEPLOY_TOKEN` and DB credentials stay in environment files (gitignored). `.tools/` is local only.
12. **Never weaken a guard to pass.** Do not disable tenancy, CSRF, escaping or a failing test to get unblocked. Fix the code, or stop and ask.

## Environment and commands

| Item | Value |
| --- | --- |
| PHP | `C:\xampp\php\php.exe` (8.2). Bare `php` may not be on PATH - use the absolute path. |
| Database | MariaDB 10.4 in XAMPP on **port 3307** (not 3306). App DB `pdd_generator`; test DB `pdd_generator_test`. Local root, empty password. |
| Config | `config/config.php` overridden by `.env.local` (template: `.env.example`). Keys: `APP_URL`, `APP_ENV`, `DB_HOST`, `DB_PORT` (3307), `DB_NAME`, `DB_USER`, `DB_PASS`, `EMAIL_TRANSPORT`. |
| Dev URL | `http://127.0.0.1:8000` - `php -S 127.0.0.1:8000 -t public public/router.php`. |
| Demo accounts | After `bin/seed.php`: `demo@pddgen.local` / `DemoPassw0rd!123` (tenant); `admin@pddgen.local` / `AdminPassw0rd!123` (platform staff, `/admin`). |
| Email | `EMAIL_TRANSPORT=log` locally; codes and links land in `storage/logs/mail.log` and the `email_outbox` table. |

| Command | Purpose |
| --- | --- |
| `php bin/setup.php` | Create both databases and apply migrations (idempotent). |
| `php bin/migrate.php` | Apply pending migrations only. |
| `php bin/seed.php` | Seed methodologies, Gold Standard v1.4 structure, demo workspace and staff account. |
| `php bin/reset.php --confirm` | Drop and rebuild the database; refuses non-`_test` names without `--confirm`. |
| `php bin/test.php [filter]` | Test suite against `pdd_generator_test`. Baseline at time of writing: **204 tests green** (plus AI Engine/drafting tests); trust the runner summary. |
| `php -l <file>` | Syntax check - the lint step for every changed PHP file. |
| `php -S 127.0.0.1:8000 -t public public/router.php` | Built-in dev server. |
| `powershell -File tools/e2e-smoke.ps1` | Tenant HTTP smoke test; needs the dev server on port 8000. |
| `powershell -File tools/e2e-admin-smoke.ps1` | Platform-console/queue HTTP smoke test; needs the dev server on port 8000. |

The test runner truncates tables and serializes runs with a MySQL `GET_LOCK`; **never run two test suites at the same time**. The suite, the app and the smokes share one MariaDB instance - coordinate DB-touching runs through the Lead.

## Where code lives

| Path | Contents | Rule |
| --- | --- | --- |
| `src/routes.php` | Every route: public, tenant, staff, `/api/*`, deploy. | New page/endpoint starts here. |
| `src/Controllers/` | Thin HTTP layer (`Admin*` controllers serve the console). | No SQL, no business rules. |
| `src/Services/` | Business rules, transactions, audit. | The only place for decisions. |
| `src/Repositories/` | All SQL. | Must filter `team_id`; PDO prepared statements only. |
| `src/*.php` | Gates/utilities: `Tenancy`, `Auth`, `Csrf`, `Session`, `View`, `Db`, `Migrator`, `Mailer`, `Validator`, `I18n`. | Do not duplicate these in new code. |
| `templates/` | `layout*.php`, `pages/`, `partials/`; `pages/admin/` is the platform console. | Escape via `e()`; no-JS forms must keep working. |
| `lang/en.php`, `lang/fr.php` | Message catalogs. | Both files in the same change. |
| `migrations/` | Numbered SQL (0001-0010 applied). | New file only; never edit history. |
| `tests/` | Procedural `*_test.php` + `helpers.php`; runner is `bin/test.php`. | Add tests for new rules, especially tenant isolation. |
| `bin/` | CLI scripts: setup, migrate, seed, reset, test. | Change only with Ops/Lead agreement. |
| `tools/` | `e2e-smoke.ps1`, `e2e-admin-smoke.ps1`. | Port 8000 is hardcoded. |
| `public/` | Web root: `index.php`, `router.php`, `assets/` (`motion.css`, `app.js`, self-hosted fonts), `.htaccess`. | No secrets; CSP `self` means no CDN assets. |
| `config/`, `database/` | Config defaults and the deploy dump (`database/pdd_generator_deploy.sql`). | See `database/README.md`. |
| `storage/` | Runtime sessions, logs, uploads. | Gitignored; never open customer uploads outside the storage layer. |
| `docs/` | PRD, data model, ADRs, dashboards, deployment, task center. | Update when behavior, commands or routes change. |

Request path: `public/index.php` (CSRF check on POST) -> `src/routes.php` -> Controller -> `Auth`/`Tenancy` gate -> Service -> Repository -> `View::render()`. `AppError` becomes JSON for `/api/*` and an accessible error page otherwise.

## Roles and write scopes

Write scopes are advisory and must stay disjoint. Need a file outside your scope? Ask the Lead first.

| Role | May write | Must not touch | Extra duty |
| --- | --- | --- | --- |
| Lead/Architect | Integration fixes anywhere after telling the owner; final review; commit/push | Specialist scopes in parallel with their owner | Task cards, dependency arbitration, final verification |
| Backend | `src/`, `migrations/`, `tests/` (backend tests), `config/` when carded | `templates/`, `public/assets/`, `lang/`, `docs/` | Routes, services, SQL, migrations, tests |
| Frontend/UI | `templates/`, `public/assets/`, `lang/en.php` + `lang/fr.php` | `src/`, `migrations/`, `tests/` | No-JS path, accessibility, reduced motion, lang parity |
| QA | Read-only by default; `tests/` only when the task card says so | Everything else | Run suites/smokes; report exact counts and failure lines |
| Ops/Deployment | `bin/`, `docs/deployment.md`, `database/README.md`, deployment config; `.tools/` locally | Application code, migrations, tests | Never commit secrets; no destructive ops on the app DB |
| Docs | `docs/`, `AGENTS.md`, `README.md` | Code, tests, migrations, assets | Commands/links must match the repo; UTF-8 without BOM |

## Workflow: claim -> implement -> verify -> hand off

1. Read the task card ([docs/ai-agents.md](docs/ai-agents.md#3-delegation-protocol) defines the fields) and the docs that govern the change.
2. Claim the shared task with its current revision; re-read and retry if the revision is stale.
3. Read the files before editing. Stay inside the write scope; keep the diff minimal and additive.
4. Verify with the checklist below; record the exact commands and their output.
5. Complete the task with a handoff note, then report to the Lead: task id, files, commands, results, risks, next steps.
6. The Lead reviews the diff, runs final verification and commits/pushes. Specialists never commit or push.

## Verification checklist

- [ ] `php -l` on every changed `.php` file.
- [ ] Targeted tests for the change, then the full suite: `php bin/test.php` - must stay green (204 baseline).
- [ ] HTTP routes/pages/templates changed: start the dev server and run both smokes (or the affected one); report the final line.
- [ ] `lang/en.php` + `lang/fr.php` updated together when strings change; `php bin/test.php unit_i18n` passes.
- [ ] Schema changes: new numbered migration, applied with `php bin/migrate.php`; migration history untouched.
- [ ] Tenant-scoped queries: cross-tenant test added; 404 behavior maintained.
- [ ] No secrets, debug output or `var_dump` left in the diff.
- [ ] Docs updated when a command, route or behavior changed; UTF-8 without BOM.
- [ ] If something could not be verified (server/DB down), say exactly what was attempted - never report green without running it.

## AI agent execution note

**Support is always available; guessing is not.**

- You may **always** reference [AGENTS.md](AGENTS.md), [docs/ai-agents.md](docs/ai-agents.md) and the project docs - [PRD](docs/PRD.md), [build guide](docs/build-guide.md), [data model](docs/data-model.md), [decisions](docs/decisions.md), [dashboards](docs/dashboards.md), [deployment](docs/deployment.md), [task center](docs/task-center.md), [OTP verification](docs/otp-email-verification.md) - plus the repo skill [.dsh/skills/aipddgen/SKILL.md](.dsh/skills/aipddgen/SKILL.md). Reading them needs no permission and is expected before work.
- You may **always ask the Lead agent (or the product owner through the Lead) for support** when you are blocked, uncertain, missing context, or when requirements are ambiguous.
- **Never invent requirements**, acceptance criteria, product behavior or data. **Never silently change scope**, files or verification. If the task card contradicts the docs or is unclear, stop and ask before writing.
- A short "blocked on X; I tried Y" message is a good result. A wrong implementation is not.

## Doc map

| Document | Use it for |
| --- | --- |
| [README.md](README.md) | Setup, commands, security model, roadmap. |
| [docs/ai-agents.md](docs/ai-agents.md) | Full agent playbook: roles, delegation, coordination, handoff, escalation. |
| [docs/PRD.md](docs/PRD.md) | Product source of truth; feature IDs F1-F13. |
| [docs/build-guide.md](docs/build-guide.md) | Build order, hard rules, out of scope. |
| [docs/data-model.md](docs/data-model.md) | Tables, invariants, roles, traceability. |
| [docs/decisions.md](docs/decisions.md) | Architecture decision records (ADR-001 to ADR-011). |
| [docs/dashboards.md](docs/dashboards.md) | Workspace `/dashboard` vs platform console `/admin`. |
| [docs/task-center.md](docs/task-center.md) | Review queue, SLA rules, review-task model. |
| [docs/deployment.md](docs/deployment.md) | Live-server checklist, dump import, one-click migration page. |
| [docs/otp-email-verification.md](docs/otp-email-verification.md) | Sign-up codes and the sign-in second factor. |
| [database/README.md](database/README.md) | Deploy dump and import options. |
| [.dsh/skills/aipddgen/SKILL.md](.dsh/skills/aipddgen/SKILL.md) | Repo-local skill: environment, commands, architecture, pitfalls. |
