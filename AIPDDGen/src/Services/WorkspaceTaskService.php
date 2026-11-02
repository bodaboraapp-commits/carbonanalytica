<?php

declare(strict_types=1);

namespace App\Services;

use App\AppError;
use App\Audit;
use App\Repositories\ReviewTaskRepository;
use App\Tenancy;

/**
 * Workspace admin job queue.
 *
 * Workspace-scoped review tasks (project validation, fact-sheet review, draft
 * review, other) belong to the workspace's Admin/Creator (owner/admin role) and
 * are invisible to platform staff and to other workspaces. The tenant gate is
 * Tenancy, so cross-workspace ids are a 404, never a 403.
 */
final class WorkspaceTaskService
{
    public const WORK_TYPES = ReviewTaskRepository::WORKSPACE_WORK_TYPES;

    public const OPEN_STATUSES = TaskCenterService::OPEN_STATUSES;

    /**
     * @param array{scope?: string, work_type?: string, q?: string, page?: int} $filters
     * @return array<string, mixed>
     */
    public static function queue(string $userId, string $teamId, array $filters): array
    {
        Tenancy::teamForUser($userId, $teamId, 'admin');

        $allowedScopes = ['open', 'mine', 'unassigned', 'breached', 'completed', 'all'];
        $scope = (string) ($filters['scope'] ?? 'open');
        $filters['scope'] = in_array($scope, $allowedScopes, true) ? $scope : 'open';
        $workType = (string) ($filters['work_type'] ?? '');
        $filters['work_type'] = in_array($workType, self::WORK_TYPES, true) ? $workType : '';
        $filters['q'] = trim((string) ($filters['q'] ?? ''));
        $filters['page'] = max(1, (int) ($filters['page'] ?? 1));

        $page = ReviewTaskRepository::paginateForTeam($userId, $teamId, $filters, 25);
        $perPage = 25;

        return [
            'rows' => $page['rows'],
            'total' => $page['total'],
            'page' => $filters['page'],
            'pages' => max(1, (int) ceil($page['total'] / $perPage)),
            'filters' => $filters,
            'summary' => ReviewTaskRepository::summaryForTeam($userId, $teamId),
            'by_work_type' => ReviewTaskRepository::byWorkTypeForTeam($teamId),
        ];
    }

    public static function assignToSelf(string $taskId, string $userId, string $teamId): array
    {
        $task = self::requireWorkspaceTask($taskId, $userId, $teamId);

        if ($task['assigned_user_id'] !== null && (string) $task['assigned_user_id'] !== $userId) {
            throw AppError::conflict('This task is already assigned to someone else.');
        }

        ReviewTaskRepository::update($taskId, [
            'assigned_user_id' => $userId,
            'status' => 'in_progress',
        ]);

        self::audit($task, $userId, 'review_task.assigned', ['work_type' => (string) $task['work_type']]);

        return self::fresh($taskId);
    }

    public static function unassign(string $taskId, string $userId, string $teamId): array
    {
        $task = self::requireWorkspaceTask($taskId, $userId, $teamId);

        ReviewTaskRepository::update($taskId, [
            'assigned_user_id' => null,
            'status' => 'unassigned',
        ]);

        self::audit($task, $userId, 'review_task.unassigned', ['previous_assignee' => $task['assigned_user_id']]);

        return self::fresh($taskId);
    }

    public static function complete(string $taskId, string $userId, string $teamId): array
    {
        $task = self::requireWorkspaceTask($taskId, $userId, $teamId);

        if ($task['assigned_user_id'] !== null && (string) $task['assigned_user_id'] !== $userId) {
            throw AppError::conflict('This task is assigned to someone else.');
        }

        ReviewTaskRepository::update($taskId, [
            'status' => 'completed',
            'assigned_user_id' => $task['assigned_user_id'] ?? $userId,
            'completed_at' => gmdate('Y-m-d H:i:s'),
            'completed_by_user_id' => $userId,
        ]);

        self::audit($task, $userId, 'review_task.completed', ['work_type' => (string) $task['work_type']]);

        return self::fresh($taskId);
    }

    /** @return array<string, mixed> */
    private static function requireWorkspaceTask(string $taskId, string $userId, string $teamId): array
    {
        Tenancy::teamForUser($userId, $teamId, 'admin');

        $task = ReviewTaskRepository::find($taskId);
        if ($task === null
            || (string) ($task['team_id'] ?? '') !== $teamId
            || !in_array((string) $task['work_type'], self::WORK_TYPES, true)) {
            throw AppError::notFound('That task');
        }

        if (!in_array((string) $task['status'], self::OPEN_STATUSES, true)) {
            throw AppError::conflict('That task is already closed.');
        }

        return $task;
    }

    /** @param array<string, mixed> $task */
    private static function audit(array $task, string $userId, string $action, array $metadata): void
    {
        Audit::record([
            'team_id' => (string) $task['team_id'],
            'actor_user_id' => $userId,
            'action' => $action,
            'target_type' => 'review_task',
            'target_id' => (string) $task['id'],
            'metadata' => $metadata,
        ]);
    }

    /** @return array<string, mixed> */
    private static function fresh(string $taskId): array
    {
        $task = ReviewTaskRepository::find($taskId);
        if ($task === null) {
            throw AppError::notFound('That task');
        }
        return $task;
    }
}
