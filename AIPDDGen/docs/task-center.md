# Review task queue and Action Center metrics

The Action Center **layout** now powers the tenant-facing workspace dashboard (`/dashboard`); the staff **platform console** lives at `/admin`. See [dashboards.md](dashboards.md) for the split. This document covers the review-task model, the SLA rules and the job queue that staff use at `/admin/tasks`.

**URLs:** `/dashboard` (workspace Action Center) and `/admin/tasks` (staff job queue).
**Access:** workspace dashboard = any active workspace member (tenant-scoped); job queue = platform staff only (`users.is_platform_admin = 1`), tenant accounts get **404**.
**Schema:** migration [`0004_review_tasks.sql`](../migrations/0004_review_tasks.sql) adds `review_tasks`; the dashboard numbers come from `WorkspaceDashboardService` (tenant) and `PlatformConsoleService` (staff).

---

## What it shows

### Action Center (`/admin`)

| Block | Content |
| --- | --- |
| Attention tiles | **Assigned to me**, **Unassigned**, **SLA breached** — each links to the matching queue scope. |
| By work type | Open task counts grouped by work type (organisation KYB, project validation, template review, fact-sheet approvals, draft review, other), each row linking to a filtered queue. |
| SLA alert bar | Appears when tasks are past due: *"N task(s) breached SLA — review first"*. |
| Pipeline status | Cards for **fact sheets pending approval**, **drafts in review**, **template versions live**, **organisation documents uploaded**. |
| Platform KPIs | Total workspaces, total projects, people, open review tasks. |

### Job queue (`/admin/tasks`)

- Filters: scope (open / assigned to me / unassigned / SLA breached / closed / everything), work type, free-text search across task title, description and workspace name.
- Ordered worst-first: breached tasks, then nearest due date, then newest.
- Per-task actions: **Assign to me**, **Unassign**, **Mark complete** — all POST → redirect → GET with CSRF protection.
- Pagination (25 per page), empty states, overdue rows highlighted with a **Breached** badge.

---

## SLA semantics

`review_tasks.due_at` is the SLA deadline. A task is **breached** when:

```
due_at IS NOT NULL AND due_at < NOW() AND status IN ('unassigned','in_progress','blocked')
```

Completing or cancelling clears the breach without editing history; the audit trail keeps every transition. There is no configurable SLA policy yet (see *Limitations*).

## Work types

| Value | Label | Typical trigger |
| --- | --- | --- |
| `organisation_kyb` | Organisation (KYB) reviews | Workspace submits its organization profile (step 1 onboarding) |
| `project_validation` | Project validation reviews | Project setup complete, awaiting staff validation |
| `template_review` | Template review (QA) | New template version passed its structural checks |
| `fact_sheet_review` | Fact sheet approvals | Fact sheet submitted for approval |
| `draft_review` | Draft reviews | Draft sent for internal review |
| `other` | Other | Manual/catch-all |

Only `organisation_kyb` is auto-created today: `TaskCenterService::syncSystemTasks()` scans `organization_profiles` with `status = 'submitted'` and creates one task per profile, idempotently (checked by target type + id). The other types are seeded for the demo and will be wired to their steps as those land (steps 2–7). Customer-facing actions never create staff tasks silently; the sync runs when the console loads and is safe to retry.

## Data model

```
review_tasks
  id                 CHAR(36) PK
  team_id            CHAR(36) NULL   -> teams(id) ON DELETE SET NULL   (workspace under review)
  work_type          ENUM(...)                                        (see table above)
  title              VARCHAR(200)
  description        TEXT NULL
  status             ENUM('unassigned','in_progress','blocked','completed','cancelled')
  priority           ENUM('low','normal','high','urgent')
  assigned_user_id   CHAR(36) NULL   -> users(id) ON DELETE SET NULL
  due_at             DATETIME(3) NULL                                 (SLA deadline)
  target_type        VARCHAR(40) NULL (e.g. organization_profile)
  target_id          CHAR(36) NULL
  created_by_user_id CHAR(36) NULL
  completed_at       DATETIME(3) NULL
  completed_by_user_id CHAR(36) NULL
  created_at / updated_at
```

Indexes: `(assigned_user_id, status, due_at)`, `(work_type, status)`, `(team_id)`, `(due_at)`, `(target_type, target_id)`.

`team_id` is present so staff can see *which workspace* a task belongs to and so the audit trail stays complete on the workspace side. Staff access to tasks is **not** tenant access: `review_tasks` is platform data and is never exposed to customer sessions.

## Audit trail

Every mutation writes to `audit_logs` with `team_id` = the workspace under review:

- `review_task.created`
- `review_task.assigned`
- `review_task.unassigned`
- `review_task.completed`

## Files

| File | Purpose |
| --- | --- |
| `migrations/0004_review_tasks.sql` | `review_tasks` table |
| `src/Repositories/ReviewTaskRepository.php` | SQL: counters, grouping, queue pagination, onboarding sync, platform metrics |
| `src/Services/TaskCenterService.php` | Staff guard, dashboard aggregation, assign/unassign/complete rules, audit |
| `src/Controllers/AdminTaskController.php` | Guarded HTTP layer (dashboard, queue, actions) |
| `templates/layout_admin.php` | Staff console chrome: search, public-portal link, alert badge, greeting, tabs |
| `templates/pages/admin/dashboard.php` | Action Center |
| `templates/pages/admin/tasks.php` | Job queue |
| `lang/en.php`, `lang/fr.php` | `admin.*` strings in both languages |
| `tests/task_center_test.php` | 14 tests |
| `tools/e2e-admin-smoke.ps1` | HTTP smoke test for the console |

## Access and security

- Staff flag only: a normal owner/admin of a workspace is refused (404). The distinction matters because review work spans tenants.
- Every admin POST requires the session CSRF token (rejected with 419 otherwise).
- Assign/unassign/complete refuse closed tasks and tasks owned by another reviewer (`409 conflict`).
- Return paths are restricted to `/admin…` (no open redirect).
- The console adds `X-Robots-Tag`-style no-index meta and is excluded from the public site; the customer layout shows the link only to platform admins.

## Demo data

`php bin/seed.php` creates:

- staff account **admin@pddgen.local / AdminPassw0rd!123**;
- five review tasks (2 overdue, 2 unassigned, 3 assigned) across the demo workspace;
- one draft moved to `in_review` so the pipeline card is populated.

## Verification

```powershell
php bin/migrate.php                 # 0004
php bin/seed.php                    # staff account + demo queue
php bin/test.php task_center        # 14 task-center tests
powershell -File tools/e2e-admin-smoke.ps1   # HTTP: guard, dashboard, queue, actions, CSRF
```

Current status: migration applied, seed populated, task-center tests green, admin E2E green.

## Limitations / next steps

- No task-creation UI yet: staff tasks come from system triggers and the seed. A "New task" form is a small follow-up.
- SLA policy is a per-task `due_at`, not a rule table (e.g. "KYB reviews get 2 business days"). Add `sla_policies` when the review process is agreed.
- No email/notification fan-out on assignment or breach; the console shows the badge. Notification delivery can reuse the existing `email_outbox` transport.
- The other work types are seeded but not yet generated by their steps; wire them as steps 2–7 land.
- The "Template library" tab is a placeholder until step 2 (admin template library) is built.
