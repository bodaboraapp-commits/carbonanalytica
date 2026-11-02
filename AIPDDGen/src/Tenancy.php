<?php

declare(strict_types=1);

namespace App;

/**
 * Tenant access layer: the single place where team membership and roles are
 * checked. Repositories always filter by the returned team id, and cross-tenant
 * lookups resolve to 404 so IDs cannot be probed across workspaces.
 */
final class Tenancy
{
    public const ROLE_RANK = ['viewer' => 1, 'editor' => 2, 'admin' => 3, 'owner' => 4];

    public static function roleAtLeast(string $role, string $minimum): bool
    {
        return (self::ROLE_RANK[$role] ?? 0) >= (self::ROLE_RANK[$minimum] ?? 99);
    }

    /**
     * Resolve a team the user may act in.
     *
     * @return array{team: array<string, mixed>, role: string, membership_id: string}
     */
    public static function teamForUser(string $userId, string $teamId, string $minimumRole = 'viewer'): array
    {
        $row = Db::selectOne(
            'SELECT t.*, tm.role AS membership_role, tm.id AS membership_id
             FROM team_members tm
             INNER JOIN teams t ON t.id = tm.team_id
             WHERE tm.team_id = ? AND tm.user_id = ? AND tm.status = ? AND t.deleted_at IS NULL
             LIMIT 1',
            [$teamId, $userId, 'active'],
        );

        if ($row === null) {
            throw AppError::notFound('That workspace');
        }

        $role = (string) $row['membership_role'];
        if (!self::roleAtLeast($role, $minimumRole)) {
            throw AppError::forbidden();
        }

        return [
            'team' => $row,
            'role' => $role,
            'membership_id' => (string) $row['membership_id'],
        ];
    }

    /**
     * Resolve a project together with the caller's team role.
     * A project in another tenant looks exactly like a missing project.
     *
     * @return array{project: array<string, mixed>, team: array<string, mixed>, role: string}
     */
    public static function projectForUser(string $userId, string $projectId, string $minimumRole = 'viewer'): array
    {
        $project = Db::selectOne(
            'SELECT * FROM projects WHERE id = ? AND deleted_at IS NULL LIMIT 1',
            [$projectId],
        );

        if ($project === null) {
            throw AppError::notFound('That project');
        }

        $membership = Db::selectOne(
            'SELECT tm.role AS membership_role, tm.id AS membership_id
             FROM team_members tm
             INNER JOIN teams t ON t.id = tm.team_id
             WHERE tm.team_id = ? AND tm.user_id = ? AND tm.status = ? AND t.deleted_at IS NULL
             LIMIT 1',
            [(string) $project['team_id'], $userId, 'active'],
        );

        if ($membership === null) {
            throw AppError::notFound('That project');
        }

        if (!self::roleAtLeast((string) $membership['membership_role'], $minimumRole)) {
            throw AppError::forbidden();
        }

        $team = Db::selectOne('SELECT * FROM teams WHERE id = ? LIMIT 1', [(string) $project['team_id']]);
        if ($team === null) {
            throw AppError::notFound('That project');
        }

        return [
            'project' => $project,
            'team' => $team,
            'role' => (string) $membership['membership_role'],
        ];
    }

    /**
     * All workspaces the user belongs to, with membership counts.
     *
     * @return list<array<string, mixed>>
     */
    public static function teamsForUser(string $userId): array
    {
        return Db::select(
            'SELECT t.*, tm.role AS membership_role,
                    (SELECT COUNT(*) FROM team_members tm2 WHERE tm2.team_id = t.id AND tm2.status = ?) AS member_count,
                    (SELECT COUNT(*) FROM projects p WHERE p.team_id = t.id AND p.deleted_at IS NULL) AS project_count
             FROM team_members tm
             INNER JOIN teams t ON t.id = tm.team_id
             WHERE tm.user_id = ? AND tm.status = ? AND t.deleted_at IS NULL
             ORDER BY tm.created_at ASC',
            ['active', $userId, 'active'],
        );
    }

    /**
     * The workspace for the current page: an explicit ?team= request is honoured
     * only if the user is a member, otherwise their stored default is used.
     *
     * @return array<string, mixed>
     */
    public static function currentTeam(string $userId, ?string $requested = null): array
    {
        $teams = self::teamsForUser($userId);
        if ($teams === []) {
            throw AppError::notFound('That workspace');
        }

        $candidate = $requested;
        if ($candidate === null || $candidate === '') {
            $candidate = Session::get('current_team_id');
        }

        $selected = null;
        if (is_string($candidate) && $candidate !== '') {
            foreach ($teams as $team) {
                if ((string) $team['id'] === $candidate || (string) $team['slug'] === $candidate) {
                    $selected = $team;
                    break;
                }
            }
        }

        if ($selected === null) {
            $defaultTeamId = Session::get('default_team_id');
            foreach ($teams as $team) {
                if ($defaultTeamId !== null && (string) $team['id'] === (string) $defaultTeamId) {
                    $selected = $team;
                    break;
                }
            }
        }

        $selected ??= $teams[0];
        Session::set('current_team_id', (string) $selected['id']);

        return $selected;
    }

    /** @return list<string> team ids the user may read */
    public static function teamIdsForUser(string $userId): array
    {
        return array_map(
            static fn (array $team): string => (string) $team['id'],
            self::teamsForUser($userId),
        );
    }
}
