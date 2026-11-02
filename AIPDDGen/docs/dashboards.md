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
| `/admin` | **Super Admin dashboard**: stat cards with real 7-day deltas + sparklines, SVG growth charts (7-day line/area + this-week-vs-previous bars), platform controls (maintenance mode, sign-ups, two-factor), platform totals, newest users/workspaces and recent audit activity |
| `/admin/workspaces` | Every workspace: name/slug, KYC status (badge), members, projects, created, last activity; search + KYC filter + pagination |
| `/admin/kyc` | Organisation profiles submitted for verification: company, workspace, status, documents, submitted date, link to the open KYB task; counts per status |
| `/admin/users` | Every account: name/email, workspace count, platform-admin badge, last sign-in; search + "only platform admins" filter; promote/demote platform admins |
| `/admin/templates` | Template versions: template name, version, registry, language, parsed sections and fields, status, publish date; counts per status |
| `/admin/audit` | Audit trail: time, actor, action, workspace, metadata; filter by action; pagination |
| `/admin/system` | **System Health** (SYSTEM group): pending migrations, database size, storage used, free disk, email transport, environment/PHP and APP_URL |
| `/admin/database` | Migration ledger with **Apply pending migrations** and **Run seed** buttons, connection summary and seed readiness (staff-only deployment page) |
| `/admin/tasks` | Platform review queue: organisation KYB + template QA tasks (workspace tasks live in each workspace's own `/dashboard/tasks` queue) |

### Super Admin dashboard (`/admin`)

Platform staff land here automatically after sign-in (a plain login redirects `/dashboard` → `/admin` for platform admins; an explicit `next=` destination is still honoured). Tenant accounts get **404** on every console page.

The overview is the **Super Admin dashboard**:

- **Stats**: four stat cards (workspaces, projects, users, open review tasks with breached-SLA badge) each with a real 7-day delta, inline SVG sparkline and a trend direction (↑ Increasing / ↓ Decreasing / → No change when the prior week has no rows — never an invented percent), plus platform totals (KYC waiting, verified organisations, published templates, documents, plans).
- **Growth charts** (server-rendered SVG, no JS library): an equal-sized pair of cards — a 7-day multi-series line/area chart for users, projects, drafts and review tasks (per-point hover titles + visually hidden data table) and a "this week vs previous week" paired bar chart from real totals — with the compact stat strip rendered above them: active users (7d), new users (30d), platform admins, workspace admins, staff/workers and facts stored.
- **Console chrome**: sticky top bar (search, public portal, language, SLA bell, greeting, sign-out) plus a Montra-style floating left panel — grouped uppercase sections, icon+label rows, solid green active pill, account block and a CSRF sign-out row; collapses behind a CSS-only Menu toggle under 980px.
- **System Health** (SYSTEM group → `/admin/system`): pending migrations, database size, storage used, free disk, email transport, environment/PHP and APP_URL — moved out of the overview into its own page.
- **Platform controls** (audited as `admin.settings.changed`, stored in `platform_settings` — migration `0007`):
  - **Maintenance mode** — when on, non-staff visitors get a `503` maintenance page; staff keep full access and can still sign in (`/login` stays reachable).
  - **Sign-ups** — when off, the public `/signup` page closes with a notice; invitations still work.
  - **Two-factor sign-in** — per-account email OTP toggle.
- **Quick actions**: open queue, review KYC, manage database; newest users/workspaces and recent audit activity are listed below.

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
php bin/test.php platform_console      # 8 tests: staff guard, lists/filters, KYC, templates, audit, admin toggles
powershell -File tools/e2e-admin-smoke.ps1   # HTTP: overview + all console pages + job queue + CSRF
powershell -File tools/e2e-smoke.ps1         # tenant flow incl. dashboard + two-factor toggle
```

## Limitations / next steps

- Console pages are read/manage-account only: approving or rejecting a KYC profile is done by completing its review task (job queue); a dedicated review screen can be added with step 2.
- The template library page is a monitoring view until step 2 ships upload/parse/publish.
- Billing is represented by plan counts only; per-PDD checkout arrives in step 10.
- Workspace switching inside the console would need a "view as workspace" flow; today staff can jump to `/dashboard` as themselves only.
