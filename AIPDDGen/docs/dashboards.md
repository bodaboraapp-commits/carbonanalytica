# Dashboards: workspace (tenant) vs platform console (staff)

The product now has **two separate dashboards** with different audiences and data boundaries.

| | Workspace dashboard | Platform console |
| --- | --- | --- |
| URL | `/dashboard` | `/admin` (+ `/admin/workspaces`, `/admin/kyc`, `/admin/users`, `/admin/templates`, `/admin/audit`, `/admin/tasks`) |
| Audience | Any active member of a workspace (owner, admin, editor, viewer) | Platform staff only (`users.is_platform_admin = 1`) |
| Data scope | Only the caller's current workspace — every query filtered by `team_id` via `Tenancy` | Every workspace, every account |
| Look | Action Center layout: attention tiles, by-status breakdown, pipeline cards, KPI tiles, then projects + create form | Management console: platform KPIs, quick links, KYC preview, newest workspaces, recent audit, plans, sign-in security |
| Access rule | Active membership required; suspended or foreign workspace → not found | Tenant account → **404** (console is invisible to customers) |

The previous staff "Action Center" design moved to the tenant dashboard; staff get a fresh console focused on operating the platform.

## Workspace navigation (signed-in tenant pages)

Signed-in tenant pages share one layout with a **workspace side panel** next to the main column, in this order: **Dashboard** (`/dashboard`, active on the dashboard itself), **Projects** (`/dashboard#projects`, jumps to the dashboard's projects section and is active on `/projects/*`), **Team** (`/team`), **Organization** (`/onboarding`) and — platform admins only — **Action Center** (`/admin`, the console shortcut). The current page is marked with `aria-current="page"`; under 980px the panel collapses into a CSS-only drawer (no JavaScript, same checkbox pattern as the console) and the styles live in `public/assets/tenant-nav.css`. Guest pages keep the simple top navigation, and the platform console keeps its own `layout_admin.php`.

## Workspace dashboard (`/dashboard`)

Sections:

1. **Organization prompt** — continue onboarding when the profile is incomplete or show "submitted for review".
2. **Action Center tiles** — projects in setup, fact sheets to approve, drafts in review.
3. **By project status** — counts per status, linking to the project list.
4. **Workspace pipeline** — projects, fact sheets pending, drafts in review, uploaded documents.
5. **Workspace totals** — team members, projects, drafts, confirmed facts.
6. **Projects** — the project list plus the create form (unchanged), inline comments/description.
7. **Team** — Admin/Creator and Staff/Workers lists, plus a **Job queue** link (`/dashboard/tasks`) with the workspace's open review-task count.

**Workspace job queue (`/dashboard/tasks`)**: owner/admin roles only; lists this workspace's `project_validation`, `fact_sheet_review`, `draft_review` and `other` tasks with scope/type/search filters and assign-to-me / unassign / complete actions. Platform tasks (organisation KYB, template QA) are excluded — they stay in the Super Admin queue at `/admin/tasks`, which now shows only those two platform work types.

### Project page (`/projects/{id}`): the AI drafting tunnel

The tenant project page is where submitted evidence becomes a PDD draft through the platform admin's configured AI engine (`DraftService` + `AiEngineService`; the workspace never sees or sets the provider key).

- **AI drafting card** (replaces the old "PDD draft" card):
  - *Drafting readiness* — three real conditions with fix paths: AI engine enabled + API key (platform staff → `/admin/ai`), a **published** PDD template for the project registry (platform staff → `/admin/templates`), and an **approved** fact sheet (administrator approval; the approval screen arrives in a later step). A separate *latest drafts* list links to `/drafts/{id}` and keeps the draft label.
  - *Honest blocker* — the authoritative reason comes from `DraftService::readiness()` and is shown verbatim (translated), including the per-template SKILL.md requirement (`error.ai_engine_skill_pending`, naming the template). The **Generate draft** button posts the existing `POST /projects/{id}/draft/generate` and is disabled with the reason when not ready; viewers get a read-only note instead.
  - *Input submission tunnel* — editors+ submit a textarea and/or a `.txt`/`.md` attachment (`POST /projects/{id}/inputs`, CSRF, tenant-scoped, audited `project.input_added`). At least one is required; the attachment is stored privately **and** its plaintext is read into `inputs.source_text` (BOM-stripped, UTF-8 validated, 20k cap, no parsing) so `DraftService::inputExcerpts()` feeds it to the engine. The card lists the latest five inputs with 160-character excerpts and a real count.
- **Drafting pipeline (horizontal stepper)** — five steps with connector lines, numbered/✓ bubbles and `<progress max="5" value="{done}">` plus an "X of 5 complete" summary: Inputs and documents (done when ≥1 non-deleted input, with the real count), Fact sheet (done when an `approved` fact_sheet_versions row exists), PDD draft (done when a latest draft exists, with its version), Gap report and Export (honestly marked *available in a later step*). The current step is highlighted with `aria-current="step"`; the layout is 5→2→1 columns under 900/640px and respects reduced motion.

Data source: `src/Services/ProjectAiService.php` (read-only payload + input helper; only calls pinned `DraftService`/`AiEngineService` public APIs) and `src/Controllers/ProjectAiController.php` (input POST). `templates/pages/project.php` renders the card + pipeline.

Data source: `src/Services/WorkspaceDashboardService.php` (dashboard) and `src/Services/WorkspaceTaskService.php` (queue) — both call `Tenancy::teamForUser()` first, so they cannot read another workspace. Viewers see the numbers but not the create form or queue.

### Workspace people: Admin/Creator + Staff/Workers

Every workspace always has at least one **Admin/Creator** (the owner membership is created with the workspace and cannot be demoted or deleted). Additional people are grouped in the UI:

| Group | Internal roles | Meaning |
| --- | --- | --- |
| **Admin/Creator** | `owner`, `admin` | Runs the workspace; invites people, changes roles, manages the organization profile |
| **Staff/Workers** | `editor`, `viewer` | Editor = staff/worker who creates and edits projects; Viewer = read-only stakeholder |

The workspace dashboard shows a **Team** card listing both groups with names, emails and role badges, plus an *Invite staff/workers* action for admins/owner when no staff exist yet. The Super Admin workspaces table adds **Admin/Creator** and **Staff/Workers** columns (creator name, admin count, staff count). Role labels in the team page and invitations are: *Admin/Creator*, *Admin*, *Staff/Worker*, *Viewer*.

## Platform console (`/admin`)

| Page | What it shows |
| --- | --- |
| `/admin` | **Super Admin dashboard**: stat cards with real 7-day deltas, trend directions + sparklines, SVG growth charts with 7/30/60/90/All-time range chips (line/area + this-period-vs-previous bars) and platform controls (maintenance mode, sign-ups, two-factor) |
| `/admin/workspaces` | Every workspace: name/slug, KYC status (badge), members, projects, created, last activity; search + KYC filter + pagination |
| `/admin/kyc` | **KYC review screen** (staff only): status filter chips (submitted / verified / rejected / all with counts) over a queue of organisation profiles — company, workspace, status, documents, submitted date, recorded decision + note. Each submitted row expands into a no-JS **Review decision** panel: **Verify** (optional approval note) or **Reject** (required reason, inline field error). A decision records `reviewed_at`/`review_note`, completes the open KYB review task, audits it and emails the primary contact; the workspace sees the outcome and can resubmit after a rejection |
| `/admin/users` | Every account: name/email, workspace count, platform-admin badge, created, last sign-in; search + "only platform admins" + "new users (last 7 days)" filters; promote/demote platform admins |
| `/admin/templates` | Template library. **Add template** (name, registry Gold Standard / Verra VCS, language, optional methodology + version label) with the private source document (PDF/DOCX/MD/TXT); every added version creates its own **SKILL.md requirement** in the AI Engine, shown as *pending* until staff upload/replace the skill document (markdown read into the DB, never a public file) — a pending skill blocks drafting for that template version. The **Manage** column opens a per-version dialog to upload/replace the private source, manage the SKILL.md, publish or retire the version, or delete a draft/retired version; counts per status; staff-only and audited (source is never exposed as a public URL) |
| `/admin/audit` | Audit trail in a two-column layout: **Recent activity** first (latest 5 entries, its own `recent_page` pagination), then the filterable/paginated Audit log table |
| `/admin/system` | **System Health** (SYSTEM group): platform totals as stat cards (KYC waiting, verified organisations, published templates, documents, plan mix) above the health table (migrations, DB size, storage, disk, mail transport, environment/PHP, APP_URL) |
| `/admin/ai` | **AI Engine** (SYSTEM group): staff-only LLM provider settings (OpenAI / Claude / DeepSeek / OpenRouter / Ollama presets + custom), write-only API key encrypted with AES-256-GCM (`AI_ENGINE_SECRET`), model picker with presets + custom id, per-registry drafting skills, enable switch, Test connection; drives citation-checked draft generation for Gold Standard / Verra projects |
| `/admin/database` | Migration ledger with **Apply pending migrations** and **Run seed** buttons, connection summary and seed readiness (staff-only deployment page) |
| `/admin/tasks` | Platform review queue: organisation KYB + template QA tasks (workspace tasks live in each workspace's own `/dashboard/tasks` queue). KYB rows link to **Open KYC review** and cannot be closed with the generic Complete action — the decision is made on `/admin/kyc` |

### Super Admin dashboard (`/admin`)

Platform staff land here automatically after sign-in (a plain login redirects `/dashboard` → `/admin` for platform admins; an explicit `next=` destination is still honoured). Tenant accounts get **404** on every console page.

The overview is the **Super Admin dashboard**:

- **Stats**: four stat cards (workspaces, projects, users, open review tasks with breached-SLA badge) each with a real 7-day delta, inline SVG sparkline and a trend direction (↑ Increasing / ↓ Decreasing / → No change when the prior week has no rows — never an invented percent).
- **Growth charts** (server-rendered SVG, no JS library): an equal-sized pair of cards — a 7-day multi-series line/area chart for users, projects, drafts and review tasks (per-point hover titles + visually hidden data table) and a "this week vs previous week" paired bar chart from real totals — with the compact stat strip rendered above them: active users (7d), new users (30d), platform admins, workspace admins, staff/workers and facts stored.
- **Console chrome**: sticky top bar (search, public portal, language, SLA bell, greeting, sign-out) plus a Montra-style floating left panel — grouped uppercase sections (Overview; Operations: Job queue / Workspaces / KYC queue / Users; Content: Templates; System: System Health / AI Engine / Audit & security / Database), icon+label rows, solid green active pill, account block and a CSRF sign-out row; collapses behind a CSS-only Menu toggle under 980px. Stat cards across the app (dashboard, KYC, templates, system) share the same `.stat-card` design.
- **System Health** (SYSTEM group → `/admin/system`): pending migrations, database size, storage used, free disk, email transport, environment/PHP and APP_URL — moved out of the overview into its own page.
- **Platform controls** (audited as `admin.settings.changed`, stored in `platform_settings` — migration `0007`) render as separated rows with one accessible no-JS switch each (green ↑/On, muted Off); same POST endpoints and CSRF tokens as before:
  - **Maintenance mode** — when on, non-staff visitors get a `503` maintenance page; staff keep full access and can still sign in (`/login` stays reachable).
  - **Sign-ups** — when off, the public `/signup` page closes with a notice; invitations still work.
  - **Two-factor sign-in** — per-account email OTP toggle.
- **Quick actions**: open queue, review KYC, manage database. Platform totals and the recent-activity list moved to their own pages (`/admin/system`, `/admin/audit`); newest users/workspaces panels were removed in favour of the Users filter and Workspaces page.

Data sources: `src/Services/PlatformConsoleService.php` (all pages re-check the platform-admin flag), `src/Services/TaskCenterService.php` + `src/Repositories/ReviewTaskRepository.php` for the job queue.

### Platform-admin management

- Staff can promote or demote another account on `/admin/users`.
- Self-demotion is refused (prevents locking the last admin out).
- Demoting the final platform admin is refused.
- Every change is audited as `admin.user.platform_admin_changed`.

### Sign-in security

The email second factor is **off by default** (migration `0006`) and is a per-account switch: `/admin` → *Sign-in security* card → **Turn on two-factor**. When on, the next sign-in asks for a 6-digit emailed code. Sign-up email confirmation is separate and unaffected.

## Routes and guards

```php
// src/routes.php
$router->get('/dashboard', [$pages, 'dashboard']);          // tenant, Tenancy guard
$router->get('/admin', [$adminConsole, 'overview']);        // staff only
$router->get('/admin/workspaces', [$adminConsole, 'workspaces']);
$router->get('/admin/kyc', [$adminConsole, 'kyc']);
$router->get('/admin/users', [$adminConsole, 'users']);
$router->post('/admin/users/{id}/platform-admin', [$adminConsole, 'togglePlatformAdmin']);
$router->post('/admin/settings/{key}', [$adminConsole, 'toggleSetting']); // maintenance_mode | signups_enabled
$router->get('/admin/templates', [$adminConsole, 'templates']);
$router->post('/admin/templates', [$adminTemplates, 'create']);            // add template + draft version + pending SKILL.md
$router->post('/admin/templates/{id}/skill', [$adminTemplates, 'uploadSkill']);
$router->post('/admin/templates/{id}/source', [$adminTemplates, 'uploadSource']);
$router->post('/admin/templates/{id}/status', [$adminTemplates, 'updateStatus']); // publish / retire
$router->post('/admin/templates/{id}/delete', [$adminTemplates, 'deleteVersion']);
$router->get('/admin/audit', [$adminConsole, 'audit']);
$router->get('/admin/tasks', [$adminTasks, 'tasks']);         // + assign/unassign/complete
$router->post('/admin/security/otp', [$adminTasks, 'toggleOtp']);

// Deployment / database (staff only, plus token-gated deploy URL)
$router->get('/admin/database', [$adminDatabase, 'show']);
$router->post('/admin/database/migrate', [$adminDatabase, 'migrate']);
$router->post('/admin/database/seed', [$adminDatabase, 'seed']);
$router->get('/admin/database/import', [$adminDatabase, 'showImport']);
$router->post('/admin/database/import', [$adminDatabase, 'importDump']);
$router->get('/deploy/import', [$deploy, 'import']);          // token only, 404 when disabled
```

- Tenant routes use `Auth::requireUser()` + `Tenancy`; cross-workspace ids resolve to 404.
- Console routes call `TaskCenterService::assertStaff()`, which returns 404 for non-staff.
- The staff link only appears in the customer header for platform admins; the console layout also sends `noindex`.

## Verification

```powershell
php bin/test.php workspace_dashboard   # 4 tests: scoping, foreign workspace 404, viewer read-only
php bin/test.php platform_console      # 14 tests: staff guard, lists/filters, KYC, templates, audit, admin toggles
php bin/test.php kyc_review            # 7 tests: verify/reject decisions, outcome email, resubmission, queue filters
php bin/test.php task_center           # 14 tests: queue actions, KBY complete guard, system-task sync
powershell -File tools/e2e-admin-smoke.ps1   # HTTP: overview + all console pages + job queue + CSRF
powershell -File tools/e2e-smoke.ps1         # tenant flow incl. dashboard + two-factor toggle
```

## Limitations / next steps

- KYC verify/reject is implemented on the dedicated `/admin/kyc` screen (task completed: decision + outcome email + tenant resubmission). Workspace validation, fact-sheet and draft reviews still live in each workspace's own queue; dedicated review screens can follow the same pattern.
- The template library page is a monitoring view until step 2 ships upload/parse/publish.
- Billing is represented by plan counts only; per-PDD checkout arrives in step 10.
- Workspace switching inside the console would need a "view as workspace" flow; today staff can jump to `/dashboard` as themselves only.
