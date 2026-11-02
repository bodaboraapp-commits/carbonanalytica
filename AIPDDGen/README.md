# AI PDD Generator

Turn scattered project inputs — notes, transcripts, documents, screenshots, short recordings — into a registry-aligned carbon project design document (PDD) draft, with a citation behind every claim, a gap report, and Word/PDF export.

Built for consultants and enterprise project teams, **Africa first**, in **English and French**.

This repository implements **step 1: data model and authentication** (see [docs/step-01.md](docs/step-01.md)). The remaining steps are listed under [Roadmap](#roadmap).

---

## Stack

| Layer | Choice | Note |
| --- | --- | --- |
| Runtime | **PHP 8.2** (XAMPP) | No framework, no build step, no Composer runtime packages. |
| Database | **MySQL / MariaDB 10.4+** (XAMPP, port 3307 here) | InnoDB, `utf8mb4`, UTC. See [ADR-002](docs/decisions.md#adr-002-mysqlmariadb-instead-of-postgresql--pgvector). |
| Pages | Server-rendered PHP templates | Forms work without JavaScript. |
| API | Small JSON endpoints (`/api/*`) | The same services the pages use. |
| Auth | Native PHP sessions + `session_version` | Instant revocation; CSRF tokens; Argon2id/bcrypt hashing. |
| Email | Resend API + `email_outbox` | Sign-up/sign-in one-time codes; reset and invitation links. Every message is recorded in `email_outbox` and mirrored to `storage/logs/mail.log` outside production. |
| Tests | Built-in runner (`php bin/test.php`) + PowerShell HTTP smoke test | No PHPUnit needed. |

The PRD's original Node/PostgreSQL stack was replaced at the product owner's request with the lightest option that runs on the existing XAMPP server. Decisions and consequences: [docs/decisions.md](docs/decisions.md).

---

## Quick start

### 1. Start MySQL/MariaDB

Use the XAMPP control panel (Start MySQL), or from a terminal:

```powershell
C:\xampp\mysql\bin\mysqld.exe --defaults-file=C:\xampp\mysql\bin\my.ini --console
```

This XAMPP installation is configured on **port 3307**. If your installation uses 3306, set `DB_PORT` in `.env.local`.

### 2. Configure

```powershell
copy .env.example .env.local
```

Default database credentials are XAMPP's (`root`, no password). Change them in `.env.local` for anything other than local development.

### 3. Create the database and demo data

```powershell
C:\xampp\php\php.exe bin/setup.php    # creates pdd_generator + pdd_generator_test, runs migrations
C:\xampp\php\php.exe bin/seed.php     # provisional methodologies, GS v1.4 structure, demo workspace
```

### 4. Run

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8000 -t public public/router.php
```

Open <http://127.0.0.1:8000>. Sign in with the seeded demo account:

- **Email:** `demo@pddgen.local`
- **Password:** `DemoPassw0rd!123`

The seeded **staff account** opens the Action Center and review queue at `/admin`:

- **Email:** `admin@pddgen.local`
- **Password:** `AdminPassw0rd!123`

Sign-up always confirms the address with a six-digit emailed code (`/verify-otp`). Sign-in is password-only by default; the optional second factor is per account and can be switched on in the staff Action Center (`/admin` → Sign-in security), after which that account's next sign-in asks for an emailed code too. With `EMAIL_TRANSPORT=resend` those emails go through Resend; for the seeded fixture addresses nothing is sent, and in development every message body is written to `storage/logs/mail.log`, so open that file for the latest code. Codes are single use, expire in 10 minutes, and allow five attempts.

### Apache alternative

Point a virtual host (or use a subdirectory) at the `public/` folder. The app detects its base path automatically, so both of these work:

- `http://localhost/` with `DocumentRoot C:/xampp/htdocs/www/CarbonAnalytica/AIPDDGen/public`
- `http://localhost/www/CarbonAnalytica/AIPDDGen/public/`

`public/.htaccess` routes everything to `index.php` and blocks dotfiles/backups.

---

## Commands

| Command | What it does |
| --- | --- |
| `php bin/setup.php` | Create databases and apply migrations (safe to re-run). |
| `php bin/migrate.php` | Apply pending migrations only. |
| `php bin/seed.php` | Seed the methodology shortlist, Gold Standard v1.4 structure and demo workspace. |
| `php bin/reset.php --confirm` | Drop and rebuild the database (refuses non-`_test` names without `--confirm`). |
| `php bin/test.php [filter]` | Run the test suite against `pdd_generator_test`. |
| `powershell -File tools/e2e-smoke.ps1` | Browser-level HTTP smoke test (dev server must be running). |
| `powershell -File tools/e2e-admin-smoke.ps1` | HTTP smoke test for the staff Action Center and job queue. |
| `php -l <file>` | Syntax check a file (used as the lint step for all PHP files). |

---

## Project layout

```
bin/                 CLI scripts: setup, migrate, seed, reset, tests
config/config.php    Configuration (overridable by .env.local)
docs/                PRD, data model, decisions, step summary, open questions
lang/                English and French message catalogs (key parity is tested)
migrations/          Numbered SQL migrations
public/              Web root: front controller, router, CSS, .htaccess
src/                 Application code (namespaced App\)
  Controllers/       Thin HTTP layer
  Repositories/      SQL with mandatory tenant filters
  Services/          Business rules (auth, teams, projects)
templates/           PHP templates (layout, pages, partials)
tests/               Test files + helpers (no external test framework)
tools/               e2e-smoke.ps1
storage/             Runtime data: sessions, logs, uploads (gitignored)
```

### Request flow

```
public/index.php
  → CSRF check on POST
  → src/routes.php → Controller
      → Auth / Tenancy (membership + role)
      → Service (business rules, transactions, audit)
      → Repository (SQL filtered by team_id)
  → View::render(template, layout)
  → AppError → JSON for /api/*, otherwise an accessible error page
```

---

## Security model

| Concern | Mitigation |
| --- | --- |
| Password storage | `password_hash` (Argon2id when available), transparent rehash on sign-in. |
| Session hijacking | HttpOnly + SameSite=Lax + Secure (on HTTPS) cookie, id regeneration at sign-in, 30-day sliding expiry. |
| Session revocation | `users.session_version` invalidated on password reset and "sign out everywhere". |
| CSRF | Per-session token on every state-changing form, validated with `hash_equals`. |
| Tenant isolation | Single access gate (`Tenancy`), every repository query filters on `team_id`, cross-tenant = 404. |
| Account enumeration | Identical error for unknown email and wrong password; reset endpoint always reports success. |
| Brute force | Fixed-window rate limits in MySQL (per IP and per account). |
| Sign-in second factor | Optional per account (`users.otp_enabled`, off by default, staff-console toggle). When on, a correct password starts a six-digit email code and no session exists until it verifies (`otp_codes`, 10-minute expiry, 5 attempts, resend limited). Sign-up always verifies the address with a code. |
| One-time code storage | Only the SHA-256 hash is stored; a code is single use, a resend invalidation kills the previous one, and comparison uses `hash_equals`. |
| Token leakage | Password-reset and invitation tokens are random and stored only as SHA-256 hashes; one-time codes likewise. |
| Injection | PDO prepared statements everywhere; no dynamic SQL with user input. |
| XSS | All template output escaped through `e()`; CSP restricts scripts and styles to `self`. |
| Clickjacking / sniffing | `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, restrictive permissions policy. |

---

## Testing

```powershell
php bin/test.php            # 85 tests
powershell -File tools/e2e-smoke.ps1
```

The suite covers the happy path and the rules that must never break:

- **Tenant isolation:** cross-workspace reads/lists/creates/deletes fail as 404, repository queries filter by team, audit is scoped (8 tests).
- **Roles:** viewer/editor/admin/owner matrix, owner invariants, suspended members (9 tests).
- **Auth:** sign-up, duplicate/lowercase email, sign-in failures, rate limiting, verification, password reset revocation, token single-use (13 tests).
- **Invitations:** create/accept/revoke/expiry, wrong-email rejection, admin restrictions (9 tests).
- **Security:** CSRF, session versioning, no plaintext tokens or passwords stored/emailed (8 tests).
- **Schema:** constraints (unique emails, one owner, live invitations), cascades, timezone alignment (8 tests).
- **Contracts:** i18n key parity, validator, tokens, password hashing (18 tests).
- **Projects:** create/list/detail/soft delete (5 tests).

Citations and "no unsupported numbers" become testable when the drafting pipeline exists (steps 5–7); the schema and service contracts already carry the fields they will need.

---

## Roadmap

| Step | Scope | Status |
| --- | --- | --- |
| 1 | Data model and auth | **Done** (approved) |
| 1b | Review job queue (tasks, SLAs, assignment) | **Done** |
| 1c | Dashboards split: workspace Action Center + fresh platform console | **Done** |
| 2 | Admin template library | Next |
| 3 | Project wizard | Planned |
| 4 | Input ingestion (parse, chunk, retrieve) | Planned |
| 5 | Fact extraction + fact sheet UI | Planned |
| 6 | Section generation with citations | Planned |
| 7 | Gap and consistency checker | Planned |
| 8 | Editor, comments, version history | Planned |
| 9 | Word/PDF export | Planned |
| 10 | Billing, free tier, audit, deletion purge | Planned |

Out of scope for the MVP (per PRD): other registries, public template marketplace, live meeting bots, long video, emission reduction calculations, direct registry submission, mobile apps, SSO/SAML.

---

## Data and privacy

- Customer uploads are private to their workspace; there is no path from project data into the shared template library.
- No model training on customer data; drafting will use retrieval over admin-curated material at request time.
- Account deletion is requestable (`deletion_requested_at`); the 30-day hard purge job arrives in step 10.
- Dates in generated documents use `DD/MM/YYYY`, numbers use thousands commas and a decimal point, per the Gold Standard template rules stored in `template_sections.format_rules`.

---

## Notes for this environment

- XAMPP's PHP prints `Module "openssl" is already loaded` on startup. It is a duplicate `extension=openssl` line in `C:\xampp\php\php.ini`, harmless, and outside the project.
- MariaDB here listens on **3307** (set in `C:\xampp\mysql\bin\my.ini`); the app reads `DB_PORT`.
- Runtime files live under `storage/`; `storage/sessions` holds PHP session files so they stay inside the project.
- `.env.local` configures Resend (`EMAIL_TRANSPORT=resend`, `RESEND_API_KEY`, `RESEND_FROM`). The sending domain must be verified in the Resend account; a send-only API key is enough. Fixture domains (`pddgen.local`, `example.com`, `example.org`) never reach the provider, and outside production the message body is mirrored to `storage/logs/mail.log`.

---

## Documentation

- [PRD](docs/PRD.md) — product requirements (source of truth).
- [Data model](docs/data-model.md) — tables, invariants, roles, traceability.
- [Decisions](docs/decisions.md) — architecture decision records.
- [Step 1 summary](docs/step-01.md) — what shipped, PRD IDs, verification, limitations.
- [Organization onboarding](docs/organization-onboarding.md) — the 4-step verification wizard for the organization behind a workspace.
- [Email OTP and Resend](docs/otp-email-verification.md) — one-time codes for sign-up and the sign-in second factor.
- [Dashboards](docs/dashboards.md) — workspace Action Center (`/dashboard`) and the platform console (`/admin`).
- [Deployment](docs/deployment.md) — live-server checklist, clean DB dump, one-click migration page.
- [Task center](docs/task-center.md) — review queue, SLA rules and the review-task model.
- [Open questions](docs/open-questions.md) — what the product owner needs to answer next.
