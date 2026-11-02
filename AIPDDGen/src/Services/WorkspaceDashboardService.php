<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\Repositories\ReviewTaskRepository;
use App\Tenancy;

/**
 * Workspace dashboard (the tenant-facing "Action Center").
 *
 * Every query is filtered by the workspace id from the tenant access layer, so a
 * member only ever sees their own workspace's numbers. Staff metrics live in
 * PlatformConsoleService and are never exposed here.
 */
final class WorkspaceDashboardService
{
    /**
     * @param array<string, mixed> $team row from Tenancy::currentTeam()
     * @return array<string, mixed>
     */
    public static function forTeam(string $userId, array $team): array
    {
        $teamId = (string) $team['id'];
        $scope = Tenancy::teamForUser($userId, $teamId, 'viewer'); // membership + role check
        $role = (string) $scope['role'];

        $count = static function (string $sql, array $params = []) use ($teamId): int {
            $row = Db::selectOne($sql, array_merge([$teamId], $params));
            return (int) ($row['total'] ?? 0);
        };

        $metrics = [
            'projects' => $count('SELECT COUNT(*) AS total FROM projects WHERE team_id = ? AND deleted_at IS NULL'),
            'projects_setup' => $count("SELECT COUNT(*) AS total FROM projects WHERE team_id = ? AND deleted_at IS NULL AND status = 'setup'"),
            'fact_sheets_pending' => $count("SELECT COUNT(*) AS total FROM projects WHERE team_id = ? AND deleted_at IS NULL AND status = 'facts_pending_approval'"),
            'projects_in_review' => $count("SELECT COUNT(*) AS total FROM projects WHERE team_id = ? AND deleted_at IS NULL AND status = 'review'"),
            'drafts_in_review' => $count("SELECT COUNT(*) AS total FROM drafts WHERE team_id = ? AND status = 'in_review'"),
            'drafts_total' => $count('SELECT COUNT(*) AS total FROM drafts WHERE team_id = ?'),
            'facts_confirmed' => $count("SELECT COUNT(*) AS total FROM facts WHERE team_id = ? AND status = 'confirmed'"),
            'members' => $count("SELECT COUNT(*) AS total FROM team_members WHERE team_id = ? AND status = 'active'"),
            'documents' => ReviewTaskRepository::tableExists('organization_documents')
                ? $count('SELECT COUNT(*) AS total FROM organization_documents WHERE team_id = ?')
                : 0,
            'invitations' => $count('SELECT COUNT(*) AS total FROM team_invitations WHERE team_id = ? AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > NOW(3)'),
            'open_review_tasks' => (int) (Db::selectOne(
                "SELECT COUNT(*) AS total FROM review_tasks
                 WHERE team_id = ? AND status IN ('unassigned','in_progress','blocked')",
                [$teamId],
            )['total'] ?? 0),
        ];

        $byStatus = Db::select(
            'SELECT status, COUNT(*) AS total
             FROM projects
             WHERE team_id = ? AND deleted_at IS NULL
             GROUP BY status
             ORDER BY total DESC, status ASC',
            [$teamId],
        );

        return [
            'metrics' => $metrics,
            'by_status' => array_map(
                static fn (array $row): array => [
                    'status' => (string) $row['status'],
                    'total' => (int) $row['total'],
                ],
                $byStatus,
            ),
            'role' => $role,
            'can_edit' => Tenancy::roleAtLeast($role, 'editor'),
            'can_manage_members' => Tenancy::roleAtLeast($role, 'admin'),
            'attention' => [
                'setup' => $metrics['projects_setup'],
                'fact_sheets' => $metrics['fact_sheets_pending'],
                'drafts' => $metrics['drafts_in_review'],
                'invitations' => $metrics['invitations'],
            ],
        ];
    }
}
