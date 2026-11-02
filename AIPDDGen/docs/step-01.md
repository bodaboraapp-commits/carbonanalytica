# Step 1 — Data model and auth

**PRD requirements implemented:** F1 (accounts, projects owned by a user or team), F10 groundwork (team roles, audit log), plus the schema foundation for F4, F5, F6, F7, F8, F9 and F13. F2 and F3 are deliberately partial (see below).

**Deliverable:** a working PHP 8.2 application on XAMPP with email/password and Google sign-in, a personal workspace with role-based access, invitations, tenant isolation, audit logging, an English/French interface and a tested MySQL schema.

---

## What was built

### Data model (23 tables + migration ledger)

`migrations/0001_init.sql` creates users, OAuth accounts, verification tokens, email outbox, rate-limit counters, teams, memberships, invitations, methodologies, templates/versions/sections, projects, inputs, facts, fact-sheet versions, drafts/sections/versions, comments, gap reports/items and audit logs. Full description: [data-model.md](data-model.md).

Highlights:

- case-insensitive unique emails, one owner per team and one live invitation per email, all enforced by the database (generated columns + unique indexes, because MySQL has no partial indexes);
- every tenant-owned row carries `team_id` and cascades from `teams`;
- drafts cannot exist without an approved fact-sheet version and a recorded template version;
- template rules (date format, number format, figure/table IDs) are stored as JSON data per section.

### Authentication and security

- Email/password sign-up, sign-in, sign-out, email verification, password reset (all single-use tokens, only hashes stored).
- Google sign-in via OIDC authorization-code flow with PKCE, implemented with cURL (no SDK). Disabled unless `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET` are configured.
- Session handling: native PHP sessions, HttpOnly + SameSite=Lax cookies, session id regeneration on sign-in, sliding expiry, and `session_version`-based instant revocation after a password reset.
- CSRF token on every state-changing form, plus `SameSite=Lax`; CSP, `X-Frame-Options`, `X-Content-Type-Options`, referrer and permissions policies.
- Rate limiting in MySQL for sign-in (per IP and per email), password reset and verification resend.
- Password hashing with Argon2id when available, otherwise bcrypt; constant-time token comparison; no account enumeration on sign-in or password reset.
- Audit log for sign-up, sign-in/failure, sign-out, verification, password reset, invitations, role changes, member removal and project create/delete.

### Teams, roles and tenant isolation

- Automatic personal workspace on sign-up; invitations with role selection, expiry and single use; accept requires the invited email.
- Role matrix: viewer (read), editor (create/edit content), admin (also delete projects, invite editors/viewers, revoke invitations), owner (also invite admins, change roles, remove members, delete the workspace).
- `src/Tenancy.php` is the single access gate; cross-tenant access returns 404 so IDs cannot be probed. Project repositories always filter on the team id from that gate.

### UI (English and French, WCAG 2.1 AA oriented)

- Landing page, sign-in, sign-up, forgot/reset password, verify email, dashboard with project list and create form, project detail with pipeline placeholder, team page with members/invitations/role controls, invitation acceptance page and error pages.
- Server-rendered forms (work without JavaScript), labelled inputs, field-level errors with `aria-invalid`/`aria-describedby`, live-region flash messages, skip link, visible focus, reduced-motion support, responsive layout, 4.5:1 contrast.
- Language switcher stores the choice in a cookie and on the user row; every string exists in both languages (a test enforces key parity).

---

## Verification

| Check | Result |
| --- | --- |
| PHP syntax lint (`php -l`, 58 files) | clean |
| Test suite (`php bin/test.php`) | **85 passed, 0 failed** (auth, verification/reset, invitations, roles, tenant isolation, projects, schema constraints, i18n parity, validator, tokens, password, CSRF/session security) |
| End-to-end HTTP smoke (`tools/e2e-smoke.ps1`) | **all checks passed**: public pages, signup, dashboard, project creation, team page, invitation create/accept, cross-tenant 404, CSRF rejection, language switch, logout, seeded demo login |
| Database migration | applied to `pdd_generator` and `pdd_generator_test`, 23 tables, 46 foreign keys |

Run everything:

```powershell
php bin/setup.php        # create databases + migrate
php bin/seed.php         # provisional methodologies, GS v1.4 structure, demo workspace
php bin/test.php         # 85 tests
php -S 127.0.0.1:8000 -t public public/router.php   # dev server
powershell -File tools/e2e-smoke.ps1                # HTTP smoke test (server must be running)
```

**Demo account:** `demo@pddgen.local` / `DemoPassw0rd!123`.

---

## PRD IDs in detail

| ID | Requirement | Step 1 status |
| --- | --- | --- |
| F1 | Email/password + Google sign-in; projects owned by a user or a team | **Done.** |
| F2 | Setup wizard (registry, methodology, country, project type) | Schema + minimal create; wizard is step 3. |
| F3 | Upload and parse .txt, .docx, .pdf, .vtt, images; transcribe audio/video | Not yet; `inputs` table ready. |
| F4 | Fact sheet with confidence and source per fact | Schema ready; extraction/UI in step 5. |
| F5 | Section-by-section PDD generation from the selected template | Schema ready; step 6. |
| F6 | Gap and consistency checker | Schema ready (`gap_reports`, `gap_items`); step 7. |
| F7 | Rich-text editor with inline comments and per-section regenerate | Schema ready; step 8. |
| F8 | Export to .docx and PDF | Step 9. |
| F9 | Version history and restore | Schema ready (`draft_versions`); step 8. |
| F10 | Team workspace roles + audit log | **Done** (roles, members, audit). |
| F11 | Reusable library of past project text | Step 10 (out of MVP priority). |
| F12 | Per-PDD checkout, free tier, open-source core | Step 10; billing tables deliberately deferred. |
| F13 | Reference templates and admin template library | Schema + seeded GS v1.4 structure; admin console is step 2. |

---

## Known limitations of this step

- Methodologies are a provisional shortlist (`is_provisional = 1`) pending the product-owner answer.
- The Gold Standard template structure is seeded as **draft** and has no uploaded source document yet; step 2 adds the admin console, file storage and publishing workflow.
- Email transport was `log` at step 1 (writes `storage/logs/mail.log` and the outbox). It is now Resend with emailed one-time codes for sign-up and sign-in — see [otp-email-verification.md](otp-email-verification.md) and ADR-010.
- Google sign-in is implemented but untested against a live Google client (no credentials in this environment); the code path is unit-testable and fails gracefully when unconfigured.
- The rich-text editor is not built yet; `draft_sections.content_json` is ready for the step-8 choice (ADR-007).
- No background worker yet; the AI pipeline in later steps will use `bin/worker.php` (documented in step 4).
- `storage/` grows unbounded (logs, session files); a cleanup task is part of step 10.

---

## Next step (waiting for approval)

Step 2 — **Admin template library**: upload, tag (registry, methodology, version, language, effective date), parse into sections/fields/rules, version, publish, retire. This needs the answer to the Verra template question and the methodology shortlist (see [open questions](open-questions.md)).
