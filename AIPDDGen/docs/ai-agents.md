# AI agents playbook

Companion to [AGENTS.md](../AGENTS.md). It defines how AI agents (the Lead and its specialist teammates) divide work in this repository: roles, write scopes, delegation protocol, coordination rules, definition of done, handoff format and escalation. Read it before claiming a task.

## AI agent execution note (read first)

**Every agent may always reference the project documentation and skill for support** - [AGENTS.md](../AGENTS.md), this playbook, [PRD](PRD.md), [build guide](build-guide.md), [data model](data-model.md), [decisions](decisions.md), [dashboards](dashboards.md), [deployment](deployment.md), [task center](task-center.md), [OTP verification](otp-email-verification.md) and [.dsh/skills/aipddgen/SKILL.md](../.dsh/skills/aipddgen/SKILL.md). Reading them needs no approval and is expected before work.

**Every agent may always ask the Lead agent - and, through the Lead, the product owner - for support** when blocked, uncertain, missing context, or when requirements are ambiguous.

**Never invent requirements. Never silently change scope.** If a task card is unclear or contradicts the docs, stop and ask before writing. (Repeated at the end of this file; the version in [AGENTS.md](../AGENTS.md) is normative for both.)

## 1. Purpose

- Keep specialist work independent and parallel-safe on one shared working tree and filesystem.
- Make every change traceable: task card -> diff -> verification output -> handoff note.
- Protect the product's non-negotiables (tenant isolation, citations and number locking, draft labelling, English/French parity, migration history) from being traded for speed.
- Give the Lead one review point and the owner one escalation path.

Scope: every AI agent or subagent working in this repository. Humans may use the same task cards.

## 2. Agent roles

| Role | Owns | Writes | Never touches |
| --- | --- | --- | --- |
| Lead / Architect | Decomposition, task cards, dependencies, final verification, commit/push | Integration fixes after telling the owning agent | Specialist scope in parallel with its owner; product requirements |
| Backend | Routes, controllers, services, repositories, migrations, backend tests | `src/`, `migrations/`, `tests/`, `config/` when carded | `templates/`, `public/assets/`, `lang/`, `docs/` |
| Frontend / UI | Templates, assets, design layer, i18n strings | `templates/`, `public/assets/`, `lang/en.php` + `lang/fr.php` | `src/`, `migrations/`, `tests/` |
| QA | Independent verification, exact test/smoke evidence | `tests/` only when the card assigns it; otherwise read-only | Source, templates, migrations, docs |
| Ops / Deployment | CLI scripts, deployment docs, environment procedure | `bin/`, `docs/deployment.md`, `database/README.md`, deployment config; `.tools/` locally | Application code, migrations, tests; committing secrets |
| Docs | Documentation that must match the code | `docs/`, `AGENTS.md`, `README.md` | Code, tests, migrations, assets |

### Lead / Architect

- **Responsibilities:** read the PRD/step goal, decompose it into non-overlapping task cards, record write scopes and dependencies, assign owners, arbitrate conflicts, run the final verification it certifies, then commit/push (or hand that to Ops).
- **Write scope:** coordination artifacts (task board) and integration fixes anywhere - but only after telling the owning agent, and never in parallel with that agent's task.
- **Must not:** implement product requirements by inventing them; commit unverified work; push secrets; bypass the task board to bypass scopes.
- **Hands off to:** commit + push, final report to the owner, updated docs when workflow changes.

### Backend

- **Responsibilities:** implement routes (`src/routes.php`), thin controllers, business rules in services, all SQL in repositories (mandatory `team_id` filter), additive migrations, audit events, backend tests.
- **Write scope:** `src/`, `migrations/`, `tests/` (backend test files), `config/` only when the card says so.
- **Must not:** change page markup, CSS/JS or language catalogs (request via Frontend); edit applied migrations; put SQL in controllers or templates; weaken the `Tenancy` gate.
- **Verification duty:** `php -l` on each changed PHP file, targeted tests, full suite, and a cross-tenant 404 test for any new tenant-scoped query.

### Frontend / UI

- **Responsibilities:** server-rendered templates, progressive-enhancement JavaScript, accessible no-JS forms, design layer (`public/assets/motion.css`, `app.js`, self-hosted fonts), strings in both catalogs, reduced-motion support.
- **Write scope:** `templates/`, `public/assets/`, `lang/en.php` and `lang/fr.php` together.
- **Must not:** touch `src/`, `migrations/`, or `tests/`; bypass `e()`, CSRF tokens or tenant guards in a template; add CDN assets (CSP is `self`); add a build step or JS framework.
- **Verification duty:** `php -l` on changed templates, `php bin/test.php unit_i18n`, both smokes when pages/routes change, and a quick check at `http://127.0.0.1:8000` including the no-JS path.

### QA

- **Responsibilities:** independent verification. Run lint, the full suite and the relevant HTTP smokes; report PASS/FAIL per step with exact counts, failure names and final output lines. Reproduce, do not repair.
- **Write scope:** read-only by default; `tests/` only when the task card explicitly assigns a test file. Test runs use the `pdd_generator_test` database through `bin/test.php`.
- **Must not:** edit source, templates, migrations or docs to make something pass; "fix" a failure silently; start or stop a server another agent is using; run suites in parallel. If the dev server or DB is unreachable, report that rather than starting services unless the card says to.
- **Verification duty:** report the literal commands and results; no adjective substitutes for a number.

### Ops / Deployment

- **Responsibilities:** CLI scripts in `bin/`, deploy dump (`database/pdd_generator_deploy.sql`), deployment docs, environment/secrets procedure, browser migration/import path, post-deploy checklist.
- **Write scope:** `bin/`, `docs/deployment.md`, `database/README.md`, deployment configuration, `.tools/` locally (gitignored - never committed).
- **Must not:** commit secrets (`.env.local`, `RESEND_API_KEY`, OAuth secrets, `DEPLOY_TOKEN`, DB passwords); run destructive commands against `pdd_generator`; edit applied migrations to "fix" production (add a new migration); touch application code, templates or tests.
- **Verification duty:** every documented step must be reproducible from a clean checkout; state exactly what was and was not executed.

### Docs

- **Responsibilities:** keep `AGENTS.md`, `README.md` and `docs/*` accurate to commands, routes and behavior; fix stale instructions; verify links resolve; write structured, plain Markdown (UTF-8, no BOM).
- **Write scope:** `docs/`, `AGENTS.md`, `README.md`.
- **Must not:** change code, tests, migrations or assets. If a document contradicts the code, report it to the Lead for a decision instead of picking a side.
- **Verification duty:** read the file after writing; confirm line counts and that required sections/notes are present; re-check every command and path against the repo.

## 3. Delegation protocol

### Task card fields (required)

| Field | Content | Example |
| --- | --- | --- |
| Goal | One or two sentences; the outcome, tied to a PRD ID or roadmap step. | "Add a cross-tenant 404 test for the new project query so F10 stays enforced." |
| Write scope | Workspace-relative file/dir prefixes. Advisory ownership; must be disjoint from other open tasks. | `src/`, `migrations/0009_*.sql`, `tests/tenant_isolation_test.php` |
| Acceptance criteria | Observable, testable outcomes. | "Foreign `team_id` returns 404; suite stays green; no SQL outside repositories." |
| Verification | Exact commands and expected results. | `php -l <files>`; `php bin/test.php tenant_isolation`; `php bin/test.php` |
| Dependencies | Task ids that must complete first, or `none`. | `blocked_by: task-6` |
| Handoff note | Where and how to report; use the template in section 6. | "Reply in the task, then message the Lead." |

Rules: cards must not overlap write scopes - if they do, the Lead splits or sequences them. Every card names its verification commands. "Make it work" is not an acceptance criterion.

### Lifecycle: claim -> work -> verify -> complete

| Step | Action | Failure handling |
| --- | --- | --- |
| 1. Discover | List ready, unowned tasks; read the full card. | Blocked by an open task: wait, do not start. |
| 2. Claim | `team_task_get` for the latest revision, then `team_task_update` action `claim` with that `expected_revision`. | Stale revision: re-get, re-claim. Never overwrite another owner. |
| 3. Prepare | Read the governing docs and the files before editing; confirm the write scope. | Need a file outside scope: message the Lead and wait for a card edit. |
| 4. Implement | Minimal additive diff inside scope; no opportunistic refactors. | Ambiguous requirement: ask; do not speculate. |
| 5. Verify | Run exactly the commands on the card; capture real output. | Own failure: fix it. Unrelated failure: report it with evidence. |
| 6. Complete | Write the handoff note, then complete the task with the current revision. | Cannot finish: report blocked/partial and release or leave it for the Lead. Never complete unverified work. |
| 7. Review | Lead checks the diff and reruns the final verification it certifies. | Lead returns the task for fixes with a note. |
| 8. Deliver | Lead (or Ops) commits and pushes; owner gets a short report. | Specialists never commit/push. |

Scope changes go through the Lead only: the Lead edits the card (`team_task_update` action `edit`) and notifies the owner; an agent never unilaterally widens or narrows scope. A blocked task stays pending with a reason - never pick up blocked work "to help".

## 4. Coordination rules

### Disjoint write scopes

- Write scopes are advisory, not locks. Assume a file can change under you; on a stale-file error, re-read and rebase your intended change.
- Two agents never write the same file in parallel. If your change needs another owner's file, request a card update through the Lead.
- Every handoff names every file added or modified.

### One shared database server

- XAMPP MariaDB on **port 3307** hosts the app DB `pdd_generator` (dev server + HTTP smokes) and the test DB `pdd_generator_test` (`bin/test.php`). Both are shared by all agents.
- The test runner truncates tables between tests and serializes runs with `GET_LOCK('pdd_generator_test_suite')` for up to 300 seconds; concurrent runs wait or abort. **Never start two suites, and never run a suite while a smoke test is exercising the app DB.** QA owns suite runs while active; other agents ask before running one.
- `bin/reset.php --confirm` drops and rebuilds a database. Never run it against `pdd_generator`, and never without an explicit card or Lead approval. It refuses non-`_test` names without `--confirm` by design.
- The e2e smokes hit the single dev server on `127.0.0.1:8000` and the app DB. Do not start a second server on that port or stop another agent's server; if the port is busy, ask the Lead.

### Migrations

- Additive, numbered files: `migrations/000N_name.sql`. Applied migrations (0001-0008 today) are history and are never edited.
- No concurrent migrations: announce before `php bin/migrate.php`; one runner at a time; schema changes belong to Backend.
- Keep migrations importable on MariaDB 10.4 (development) and 10.5+ (deployment): numeric generated columns, never CHAR expressions - see ADR-011 in [decisions](decisions.md).

### Git and delivery

- Commit and push only by the Lead or Ops. Specialists never run `git commit`, `git push`, or history rewrites.
- The working tree is shared: never checkout, stash, revert or discard files you did not change; never delete another agent's work.
- The Lead reviews the final diff and reruns the verification it certifies before committing.

### Secret and data hygiene

- `.env.local`, `RESEND_API_KEY`, Google OAuth secrets, `DEPLOY_TOKEN` and DB credentials: never in the repo, task cards, chat logs or docs. `.tools/` is gitignored and local only.
- Fixture accounts (`demo@pddgen.local`, `admin@pddgen.local`) are seeded demo data, not secrets - but production passwords must be changed (see [deployment](deployment.md)).
- Local email codes and links live in `storage/logs/mail.log` and `email_outbox`; do not paste customer uploads or personal data into reports.
- Customer uploads stay private to their workspace; no training on customer data; no project data in the shared template library.

### Scope of change

- No new Composer/Node dependencies, no build step, no new framework, no CDN assets.
- No debug output (`var_dump`, `print_r`) left in the diff; log through `storage/logs`.
- Do not edit tests to force green. A test change requires criteria in the task card.

## 5. Definition of done

A change is done when the card's acceptance criteria are met and the evidence is in hand.

| Change type | Required evidence |
| --- | --- |
| All changes | Acceptance criteria met; write scope respected; handoff note written; exact commands and results recorded. |
| Any PHP file | `php -l` reports no syntax errors on every changed file; targeted tests pass. |
| Behavior change | Full suite green via `php bin/test.php` (baseline 154 tests, 0 failed); new rules covered by tests. |
| HTTP/routes/pages | Dev server run plus `tools/e2e-smoke.ps1` and/or `tools/e2e-admin-smoke.ps1`; final result line reported. |
| Schema | New numbered migration applied cleanly to both databases; migration history untouched; MariaDB portability rule respected. |
| Language strings | Both `lang/en.php` and `lang/fr.php` updated; `unit_i18n` parity test green. |
| Tenant-scoped code | Cross-tenant access test (404) green; every new repository query filters `team_id`. |
| Security-sensitive code | CSRF token, `e()` escaping, PDO prepared statements and the tenancy gate intact; no weakening. |
| Docs | Links resolve; commands reproduce; UTF-8 without BOM; line count stated when requested. |
| Not verified | Explicitly state what was not run and why (server/DB unavailable, dependency open, out of scope). |

Never report done on a failure you do not understand. "Green" means the command ran and printed the expected summary, not that it looks fine.

## 6. Handoff / report template

```text
Task: task-<id> - <subject>
Status: done | blocked | partial
What changed: <1-3 sentences; what a user or system can now do>
Files: <path> (new|modified)   [one line per file]
Commands run:
  - <exact command> -> <exact result, e.g. "154 passed, 0 failed">
  - <exact command> -> <result>
Acceptance: <criterion> - met / not met (evidence line)
Risks / notes: <regression risk, follow-up needed, anything unverified>
Blocked on: <task id / missing decision / environment>   [blocked or partial only]
Next steps: <smallest next action for the Lead>
```

Rules: copy the real output instead of paraphrasing it; list every file; do not claim a check you did not run; keep the report to 10-20 lines. For blocked work, name the concrete blocking condition and what was attempted.

## 7. Support and escalation

### Ask immediately - before writing code - when

- the task card lacks a goal, acceptance criteria or verification commands, or conflicts with the documentation;
- two docs disagree, or behavior is not specified and needs a product decision;
- the work needs a file outside your write scope, or collides with another open task;
- a dependency (blocked_by task) is still open;
- the database or dev server is unavailable, or verification fails for a reason you cannot explain in one sentence;
- the change could be destructive: data loss, migrations on `pdd_generator`, reset, secrets, production configuration.

### How to ask

1. Message the Lead with the task id in the subject. The Lead relays product questions to the product owner.
2. Include: task id; what you tried; the exact command and full error or failure line; file and line references; docs consulted; one or two proposed options with trade-offs; the blast radius if the wrong option is chosen.
3. If blocked, leave the task state honest and say "blocked on X" with the condition. Do not spin or guess.

### Never do to "get unblocked"

- invent a requirement, error message, acceptance criterion or product behavior;
- silently change scope, files, architecture, or skip a teammate's card;
- disable a security guard, delete or skip a test, or edit an applied migration;
- commit/push, handle production secrets, or run destructive database commands.

## 8. AI agent execution note (repeated)

**Support is always available; guessing is not.**

- You may **always** reference [AGENTS.md](../AGENTS.md), this playbook, [PRD](PRD.md), [build guide](build-guide.md), [data model](data-model.md), [decisions](decisions.md), [dashboards](dashboards.md), [deployment](deployment.md), [task center](task-center.md), [OTP verification](otp-email-verification.md), [organization onboarding](organization-onboarding.md), [database README](../database/README.md), [README](../README.md) and the repo skill [.dsh/skills/aipddgen/SKILL.md](../.dsh/skills/aipddgen/SKILL.md). Reading needs no permission.
- You may **always ask the Lead agent (or the product owner through the Lead) for support** when blocked, uncertain, missing context, or when requirements are ambiguous.
- **Never invent requirements** or product behavior, and **never silently change scope**, acceptance criteria, files or verification. If the card is unclear or contradicts the docs, stop and ask before writing.
- If you cannot verify something, say so with the exact attempt. Never report green without running the command.

### Doc map

| Document | Use it for |
| --- | --- |
| [AGENTS.md](../AGENTS.md) | Entry point and normative non-negotiable rules. |
| [PRD](PRD.md) | Product source of truth; feature IDs F1-F13. |
| [build guide](build-guide.md) | Build order, hard rules, out of scope. |
| [data model](data-model.md) | Tables, invariants, roles, traceability. |
| [decisions](decisions.md) | ADRs: PHP/XAMPP, MySQL instead of PostgreSQL, portability, OTP, owner uniqueness. |
| [dashboards](dashboards.md) | Workspace Action Center `/dashboard` vs platform console `/admin`. |
| [deployment](deployment.md) | Live-server checklist, dump import, browser migrations. |
| [task center](task-center.md) | Review queue, SLA rules, review-task model. |
| [OTP verification](otp-email-verification.md) | Sign-up codes and the optional sign-in second factor. |
| [organization onboarding](organization-onboarding.md) | Workspace verification wizard and KYB review. |
| [database README](../database/README.md) | Deploy dump and import options. |
| [README](../README.md) | Setup, commands, security model, roadmap. |
| [.dsh/skills/aipddgen/SKILL.md](../.dsh/skills/aipddgen/SKILL.md) | Repo-local skill: environment, commands, architecture, pitfalls. |
