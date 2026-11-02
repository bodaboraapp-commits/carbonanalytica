<?php

declare(strict_types=1);

namespace App\Services;

use App\AppError;
use App\Audit;
use App\Repositories\ReviewTaskRepository;
use App\Repositories\UserRepository;

/**
 * Staff Action Center: the review queue and its dashboard.
 *
 * Access is limited to platform admins (`users.is_platform_admin`). That flag is
 * separate from tenant roles: it grants no access to a workspace's project data,
 * only to staff-level review work. A non-admin receives 404 so the console is
 * not discoverable from a tenant account.
 *
 * Every mutation writes an audit entry (team_id = the workspace under review so
 * the workspace trail stays complete).
 */
final class TaskCenterService
{
    public const WORK_TYPES = ReviewTaskRepository::WORK_TYPES;

    public const OPEN_STATUSES = ['unassigned', 'in_progress', 'blocked'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    /** Resolve a staff member or fail as if the page did not exist. */
    public static function assertStaff(string $userId): array
    {
        $user = UserRepository::findById($userId);
        if ($user === null || (int) $user['is_platform_admin'] !== 1) {
            throw AppError::notFound('That page');
        }
        return $user;
    }

    public static function isStaff(array $user): bool
    {
        return (int) ($user['is_platform_admin'] ?? 0) === 1;
    }

    /**
     * Everything the Action Center dashboard needs.
     *
     * @return array{
     *   summary: array<string, int>,
     *   by_work_type: list<array{work_type: string, total: int}>,
     *   metrics: array<string, int>,
     *   synced: int
     * }
     */
    public static function dashboard(string $userId): array
    {
        self::assertStaff($userId);
        $synced = self::syncSystemTasks($userId);

        return [
            'summary' => ReviewTaskRepository::summary($userId),
            'by_work_type' => ReviewTaskRepository::byWorkType(),
            'metrics' => ReviewTaskRepository::platformMetrics(),
            'synced' => $synced,
        ];
    }

    /**
     * Filtered, paginated queue plus the counters used by the page chrome.
     *
     * @param array{scope?: string, work_type?: string, q?: string, page?: int} $filters
     * @return array<string, mixed>
     */
    public static function queue(string $userId, array $filters): array
    {
        self::assertStaff($userId);
        self::syncSystemTasks($userId);

        $allowedScopes = ['open', 'mine', 'unassigned', 'breached', 'completed', 'all'];
        $filters['scope'] = in_array($filters['scope'] ?? 'open', $allowedScopes, true)
            ? $filters['scope']
            : 'open';
        $filters['work_type'] = in_array($filters['work_type'] ?? '', self::WORK_TYPES, true)
            ? $filters['work_type']
            : '';
        $filters['q'] = trim((string) ($filters['q'] ?? ''));
        $filters['page'] = max(1, (int) ($filters['page'] ?? 1));

        $page = ReviewTaskRepository::paginate($userId, $filters, 25);
        $perPage = 25;
        $pages = max(1, (int) ceil($page['total'] / $perPage));

        return [
            'rows' => $page['rows'],
            'total' => $page['total'],
            'page' => $filters['page'],
            'pages' => $pages,
            'filters' => $filters,
            'summary' => ReviewTaskRepository::summary($userId),
            'by_work_type' => ReviewTaskRepository::byWorkType(),
        ];
    }

    public static function assignToSelf(string $taskId, string $userId): array
    {
        self::assertStaff($userId);
        $task = self::requireOpenTask($taskId);

        if ($task['assigned_user_id'] !== null && (string) $task['assigned_user_id'] !== $userId) {
            throw AppError::conflict('This task is already assigned to someone else.');
        }

        ReviewTaskRepository::update($taskId, [
            'assigned_user_id' => $userId,
            'status' => 'in_progress',
        ]);

        Audit::record([
            'team_id' => $task['team_id'],
            'actor_user_id' => $userId,
            'action' => 'review_task.assigned',
            'target_type' => 'review_task',
            'target_id' => $taskId,
            'metadata' => ['work_type' => (string) $task['work_type']],
        ]);

        return self::requireTask($taskId);
    }

    public static function unassign(string $taskId, string $userId): array
    {
        self::assertStaff($userId);
        $task = self::requireOpenTask($taskId);

        ReviewTaskRepository::update($taskId, [
            'assigned_user_id' => null,
            'status' => 'unassigned',
        ]);

        Audit::record([
            'team_id' => $task['team_id'],
            'actor_user_id' => $userId,
            'action' => 'review_task.unassigned',
            'target_type' => 'review_task',
            'target_id' => $taskId,
            'metadata' => ['previous_assignee' => $task['assigned_user_id']],
        ]);

        return self::requireTask($taskId);
    }

    public static function complete(string $taskId, string $userId): array
    {
        self::assertStaff($userId);
        $task = self::requireOpenTask($taskId);

        if ($task['assigned_user_id'] !== null && (string) $task['assigned_user_id'] !== $userId) {
            throw AppError::conflict('This task is assigned to someone else.');
        }

        ReviewTaskRepository::update($taskId, [
            'status' => 'completed',
            'assigned_user_id' => $task['assigned_user_id'] ?? $userId,
            'completed_at' => gmdate('Y-m-d H:i:s'),
            'completed_by_user_id' => $userId,
        ]);

        Audit::record([
            'team_id' => $task['team_id'],
            'actor_user_id' => $userId,
            'action' => 'review_task.completed',
            'target_type' => 'review_task',
            'target_id' => $taskId,
            'metadata' => ['work_type' => (string) $task['work_type']],
        ]);

        return self::requireTask($taskId);
    }

    /**
     * Create a task explicitly (seeds, tests and later system hooks).
     *
     * @param array{
     *   work_type: string, title: string, description?: ?string, priority?: string,
     *   team_id?: ?string, target_type?: ?string, target_id?: ?string,
     *   assigned_user_id?: ?string, due_at?: ?string
     * } $data
     */
    public static function createTask(string $actorUserId, array $data): array
    {
        self::assertStaff($actorUserId);

        $workType = (string) ($data['work_type'] ?? '');
        if (!in_array($workType, self::WORK_TYPES, true)) {
            throw AppError::validation(['work_type' => 'invalid_choice']);
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw AppError::validation(['title' => 'required']);
        }

        $priority = (string) ($data['priority'] ?? 'normal');
        if (!in_array($priority, self::PRIORITIES, true)) {
            throw AppError::validation(['priority' => 'invalid_choice']);
        }

        $teamId = $data['team_id'] ?? null;
        if ($teamId !== null) {
            // The workspace must exist; there is no tenant-membership requirement
            // because staff review work across tenants.
            $team = \App\Db::selectOne('SELECT id FROM teams WHERE id = ? AND deleted_at IS NULL', [$teamId]);
            if ($team === null) {
                throw AppError::notFound('That workspace');
            }
        }

        $task = ReviewTaskRepository::create([
            'team_id' => $teamId,
            'work_type' => $workType,
            'title' => $title,
            'description' => isset($data['description']) ? trim((string) $data['description']) : null,
            'priority' => $priority,
            'due_at' => $data['due_at'] ?? null,
            'target_type' => $data['target_type'] ?? null,
            'target_id' => $data['target_id'] ?? null,
            'assigned_user_id' => $data['assigned_user_id'] ?? null,
            'status' => isset($data['assigned_user_id']) ? 'in_progress' : 'unassigned',
            'created_by_user_id' => $actorUserId,
        ]);

        Audit::record([
            'team_id' => $task['team_id'],
            'actor_user_id' => $actorUserId,
            'action' => 'review_task.created',
            'target_type' => 'review_task',
            'target_id' => (string) $task['id'],
            'metadata' => ['work_type' => $workType, 'priority' => $priority],
        ]);

        return $task;
    }

    /**
     * Turn submitted organisation profiles into KBY review tasks once.
     * Idempotent: an existing task for the same target prevents a duplicate.
     */
    public static function syncSystemTasks(string $actorUserId): int
    {
        return ReviewTaskRepository::syncSubmittedOrganisations($actorUserId);
    }

    public static function workTypeLabelKey(string $workType): string
    {
        $known = [
            'organisation_kyb' => 'admin.work_type_organisation_kyb',
            'project_validation' => 'admin.work_type_project_validation',
            'template_review' => 'admin.work_type_template_review',
            'fact_sheet_review' => 'admin.work_type_fact_sheet_review',
            'draft_review' => 'admin.work_type_draft_review',
            'other' => 'admin.work_type_other',
        ];
        return $known[$workType] ?? 'admin.work_type_other';
    }

    public static function statusLabelKey(string $status): string
    {
        return match ($status) {
            'unassigned' => 'admin.status_unassigned',
            'in_progress' => 'admin.status_in_progress',
            'blocked' => 'admin.status_blocked',
            'completed' => 'admin.status_completed',
            'cancelled' => 'admin.status_cancelled',
            default => 'admin.status_unassigned',
        };
    }

    public static function priorityLabelKey(string $priority): string
    {
        return match ($priority) {
            'urgent' => 'admin.priority_urgent',
            'high' => 'admin.priority_high',
            'low' => 'admin.priority_low',
            default => 'admin.priority_normal',
        };
    }

    /** @return array<string, mixed> */
    private static function requireTask(string $taskId): array
    {
        $task = ReviewTaskRepository::find($taskId);
        if ($task === null) {
            throw AppError::notFound('That task');
        }
        return $task;
    }

    /** @return array<string, mixed> an open task, or a conflict if it is closed */
    private static function requireOpenTask(string $taskId): array
    {
        $task = self::requireTask($taskId);
        if (!in_array((string) $task['status'], self::OPEN_STATUSES, true)) {
            throw AppError::conflict('That task is already closed.');
        }
        return $task;
    }
}
