# Architecture decisions

Short records of the choices that shape the codebase. Each one lists the context, the decision, and what it costs. Deviations from `docs/PRD.md` are marked as such; the product owner explicitly chose the PHP/MySQL stack.

---

## ADR-001: PHP 8.2 on XAMPP instead of Node + PostgreSQL

**Status:** accepted (product-owner decision, overrides the PRD technical approach).

**Context.** The PRD proposes Next.js/React, a Node or FastAPI backend and PostgreSQL. The owner asked for the **lightest architecture** and chose to run on the existing XAMPP installation (PHP 8.2 + MariaDB 10.4, port 3307).

**Decision.** Build the MVP as a plain PHP 8.2 application:

- server-rendered pages + small JSON API, no SPA build chain;
- PSR-4 autoloader and a ~40-line router instead of a framework;
- native PHP sessions, `password_hash`, prepared statements (PDO);
- zero Composer runtime dependencies (Composer/PHPUnit not required to run or test).

**Consequences.**

- (+) One deployable folder under Apache, no `node_modules`, no build step, works on the owner's machine and any cheap PHP host.
- (+) Fewer moving parts to secure and operate; a consultant can read the whole request path.
- (−) No React, so the PRD's TipTap editor cannot be used as-is. Step 8 will vendor a small rich-text editor (Trix or Quill) or use a server-rendered contenteditable module; recorded in ADR-007.
- (−) The AI pipeline runs as PHP background jobs (a `bin/worker.php` loop) instead of a Node queue. Step 4 adds it.
- (−) PostgreSQL-specific retrieval is replaced (ADR-002).

---

## ADR-002: MySQL/MariaDB instead of PostgreSQL + pgvector

**Status:** accepted (part of ADR-001).

**Context.** The PRD's RAG design assumes pgvector for embeddings over inputs and template passages. MariaDB 10.4 has no vector type.

**Decision.** Keep the same relational model on InnoDB with these substitutes:

1. **Keyword retrieval at MVP:** `FULLTEXT` indexes on input chunks and template passages (InnoDB supports them). Retrieval returns the best lexical matches to the drafting prompt.
2. **Embeddings later if needed:** store vectors as JSON arrays and score with cosine similarity in PHP for small per-project corpora (the MVP limit is a handful of inputs), or add a vector service when corpus size justifies it.
3. **Schema portability:** no PostgreSQL-only defaults; UUIDs are generated in PHP; timestamps are `DATETIME(3)` UTC; JSON columns replace `jsonb`.

**Consequences.**

- (+) Runs on the owner's existing database server; nothing to install.
- (−) Retrieval quality is lexical, not semantic. Engineering note in step 4: combine FULLTEXT with fact-sheet keys so drafts are grounded even without embeddings.
- MySQL has no partial indexes, so "one owner per team" and "one live invitation per email" use persisted generated columns with unique indexes (`team_members.owner_scope`, `team_invitations.live_email`).

---

## ADR-003: Native PHP sessions + `users.session_version` instead of JWT or a sessions table

**Status:** accepted.

**Context.** Sessions must survive restarts, and signing out everywhere (especially after a password reset) must invalidate existing sessions immediately.

**Decision.** Use file-backed PHP sessions stored in `storage/sessions/` (inside the project). Store `user_id` and the user's current `session_version` in the session. Every request re-reads the user row; a version mismatch destroys the session. Password reset and "sign out everywhere" increment `session_version`.

**Consequences.**

- (+) No extra table, no token crypto, revocation is immediate.
- (+) Session files stay on the app server, never in URLs.
- (−) Sessions are tied to one server; a multi-server deployment needs a shared session store (Redis or the database) — noted for step 10 scaling, not an MVP concern.

---

## ADR-004: Password hashing with PHP's `password_hash`

**Status:** accepted.

**Decision.** `PASSWORD_ARGON2ID` when the PHP build provides it, otherwise `PASSWORD_BCRYPT`; cost/parameters chosen by PHP. `password_needs_rehash()` upgrades hashes transparently on the next successful sign-in.

**Consequences.** No native extension or dependency needed, and the stored hash format is self-describing, so algorithm changes never lock anyone out.

---

## ADR-005: No framework, no Composer runtime dependencies

**Status:** accepted.

**Decision.**

- `src/` holds plain classes with a small autoloader (`App\Foo` → `src/Foo.php`).
- `src/Router.php` maps method + path patterns to controller methods.
- `src/View.php` renders PHP templates with a layout; `e()` escapes output.
- Tests run with `php bin/test.php` (a ~120-line runner, no PHPUnit).
- `composer.json` is intentionally absent; a future contributor can add PHPUnit if the suite grows.

**Consequences.** (+) Nothing to install, no supply chain, works offline. (−) No framework conveniences (validation, ORM, migrations) — all of it is in `src/` and `migrations/`, deliberately small.

---

## ADR-006: Server-rendered pages first, JSON API second

**Status:** accepted.

**Decision.** Every user flow is a plain HTML form → POST → 303 redirect → GET page, protected by a CSRF token. No JavaScript is required. `/api/health`, `/api/session` and `/api/projects` return the same data as JSON for future clients.

**Consequences.**

- (+) Works on slow connections and old browsers, is keyboard/screen-reader friendly, and cannot break when JS fails.
- (+) Authentication is testable from HTTP without a browser (see `tools/e2e-smoke.ps1`).
- (−) Interactive steps later (fact sheet confirmation, editor autosave) will need small amounts of JavaScript; they will be added progressively, with the no-JS path kept for forms.

---

## ADR-007: Rich-text editor choice deferred to step 8

**Status:** open, decided in step 8.

**Context.** The PRD names TipTap (React). Without React, a lighter option is needed.

**Candidates.** Trix (small, accessible, no dependencies), Quill 2 (more features, larger), or a minimal `contenteditable` editor with a JSON model matching `draft_sections.content_json`.

**Decision now.** The schema already stores ProseMirror-style JSON (`content_json`) plus a plain-text projection (`content_text`), so any of these editors can be adopted without a migration. Confirm the choice when step 8 starts.

---

## ADR-008: Soft delete plus a documented 30-day purge

**Status:** accepted.

**Decision.** Deleting a user, team, project or input sets `deleted_at`; reads filter it out. `users.deletion_requested_at` records the privacy request. Step 10 adds `bin/purge-deletions.php` to hard-delete rows older than 30 days (cascades already exist), which satisfies the "deletable on request within 30 days" requirement.

**Consequences.** Audit and recovery work during the window; the purge job is a scheduled task, documented rather than assumed.

---

## ADR-009: Email delivery through an outbox with a log transport

**Status:** accepted.

**Decision.** `App\Mailer` writes every message to `email_outbox` and, for the `log` transport, to `storage/logs/mail.log`. Verification, reset and invitation links are visible in logs during development. Swapping `EMAIL_TRANSPORT` to a real SMTP/SES implementation is a single class change.

**Consequences.** Sign-up never fails because of a mail outage, and tests can assert on the outbox (`tests/helpers.php` extracts tokens from emails).

---

## ADR-010: Resend transport and emailed one-time codes for sign-up and sign-in

**Status:** accepted.

**Context.** Link-based email verification proved mailbox ownership once, at sign-up, and a password alone created a session. The product owner asked for OTP verification through Resend on sign-up and sign-in.

**Decision.**

- `App\ResendTransport` posts to the Resend HTTP API; `App\Mailer` keeps the outbox contract (table row per message, delivery errors recorded, never thrown) and adds the provider message id.
- Sign-up sends a six-digit code (purpose `signup`) and marks the address verified when it is entered.
- Sign-in checks the password, then always sends a code (purpose `signin`); no session exists until the code is verified.
- Codes live in `otp_codes` as SHA-256 hashes, expire in 10 minutes, are single use, die after 5 wrong attempts, and are rate limited on resend and verification.
- The pending state is session data without a user id, so every signed-in route stays closed until the second factor passes.
- Fixture domains (`pddgen.local`, `example.com`, `example.org`) never reach the provider, and outside production message bodies are mirrored to `storage/logs/mail.log` so codes stay reachable locally.

**Consequences.**

- (+) Mailbox ownership is proven on every sign-in, not just once; a stolen password alone is not enough.
- (+) Email delivery is a real provider integration, still swappable behind `Mailer`, and a provider outage cannot corrupt sign-up.
- (+) Tests and the e2e smoke test read codes from the outbox/log, so the suite needs no mail service.
- (−) Every sign-in now costs an email and a code entry; compromised mailboxes are the remaining weak link (no TOTP yet).
- (−) The Resend key is a deployment secret; it belongs in `.env.local`/environment, never in the repository.

**Update (same day, product–owner feedback):** the sign-in second factor is now **opt-in per account** (`users.otp_enabled`, default off; migration `0006_otp_preference.sql`). Sign-in sends a code only when the account has switched it on from the staff Action Center; sign-up email verification still always uses a code. The code screen renders six single-digit boxes, assembled server-side by slot index and enhanced with auto-advance/paste in `public/assets/otp.js`.
