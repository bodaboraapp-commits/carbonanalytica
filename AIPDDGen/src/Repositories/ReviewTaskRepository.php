<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Db;
use App\Uuid;

/**
 * Staff review queue rows (Action Center).
 *
 * These tasks are platform-level, not tenant data: only platform admins reach
 * them (guarded in Services\TaskCenterService). Each task still carries the
 * `team_id` of the workspace under review so a reviewer can jump to context and
 * so completed work remains traceable.
 */
final class ReviewTaskRepository
{
    /** The closed set of staff work types. */
    public const WORK_TYPES = [
        'organisation_kyb',
        'project_validation',
        'template_review',
        'fact_sheet_review',
        'draft_review',
        'other',
    ];

    /** Platform-scoped types: only Super Admin (staff) work on these. */
    public const PLATFORM_WORK_TYPES = ['organisation_kyb', 'template_review'];

    /** Workspace-scoped types: handled by the workspace's Admin/Creator. */
    public const WORKSPACE_WORK_TYPES = ['project_validation', 'fact_sheet_review', 'draft_review', 'other'];

    private const PLATFORM_TYPES_SQL = "'organisation_kyb','template_review'";
    private const WORKSPACE_TYPES_SQL = "'project_validation','fact_sheet_review','draft_review','other'";

    private const OPEN_STATUSES = ['unassigned', 'in_progress', 'blocked'];

    private const BREACH_SQL = "due_at IS NOT NULL AND due_at < NOW(3) AND status IN ('unassigned','in_progress','blocked')";

    /** @var array<string, bool> */
    private static array $tableCache = [];

    public static function tableExists(string $table): bool
    {
        if (isset(self::$tableCache[$table])) {
            return self::$tableCache[$table];
        }
        $row = Db::selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            [$table],
        );
        return self::$tableCache[$table] = ((int) ($row['total'] ?? 0)) > 0;
    }

    /**
     * Action Center counters for the current staff member.
     *
     * @return array{assigned_to_me: int, unassigned: int, breached: int, open_total: int, completed_by_me: int}
     */
    public static function summary(string $userId): array
    {
        return self::summaryWhere($userId, '1 = 1', []);
    }

    /** Platform-scoped counters for the staff job queue. */
    public static function summaryPlatform(string $userId): array
    {
        return self::summaryWhere($userId, 'work_type IN (' . self::PLATFORM_TYPES_SQL . ')', []);
    }

    /** Workspace-scoped counters for the workspace admin job queue. */
    public static function summaryForTeam(string $userId, string $teamId): array
    {
        return self::summaryWhere(
            $userId,
            'team_id = ? AND work_type IN (' . self::WORKSPACE_TYPES_SQL . ')',
            [$teamId],
        );
    }

    /** @return array{assigned_to_me: int, unassigned: int, breached: int, open_total: int, completed_by_me: int} */
    private static function summaryWhere(string $userId, string $where, array $params): array
    {
        $row = Db::selectOne(
            "SELECT
                SUM(CASE WHEN assigned_user_id = ? AND status IN ('unassigned','in_progress','blocked') THEN 1 ELSE 0 END) AS assigned_to_me,
                SUM(CASE WHEN assigned_user_id IS NULL AND status = 'unassigned' THEN 1 ELSE 0 END) AS unassigned,
                SUM(CASE WHEN " . self::BREACH_SQL . " THEN 1 ELSE 0 END) AS breached,
                SUM(CASE WHEN status IN ('unassigned','in_progress','blocked') THEN 1 ELSE 0 END) AS open_total,
                SUM(CASE WHEN status = 'completed' AND completed_by_user_id = ? THEN 1 ELSE 0 END) AS completed_by_me
             FROM review_tasks
             WHERE {$where}",
            array_merge([$userId, $userId], $params),
        );

        return [
            'assigned_to_me' => (int) ($row['assigned_to_me'] ?? 0),
            'unassigned' => (int) ($row['unassigned'] ?? 0),
            'breached' => (int) ($row['breached'] ?? 0),
            'open_total' => (int) ($row['open_total'] ?? 0),
            'completed_by_me' => (int) ($row['completed_by_me'] ?? 0),
        ];
    }

    /** @return list<array{work_type: string, total: int}> */
    public static function byWorkType(): array
    {
        return self::byWorkTypeWhere('1 = 1', []);
    }

    /** @return list<array{work_type: string, total: int}> */
    public static function byWorkTypePlatform(): array
    {
        return self::byWorkTypeWhere('work_type IN (' . self::PLATFORM_TYPES_SQL . ')', []);
    }

    /** @return list<array{work_type: string, total: int}> */
    public static function byWorkTypeForTeam(string $teamId): array
    {
        return self::byWorkTypeWhere('team_id = ? AND work_type IN (' . self::WORKSPACE_TYPES_SQL . ')', [$teamId]);
    }

    /** @return list<array{work_type: string, total: int}> */
    private static function byWorkTypeWhere(string $where, array $params): array
    {
        $rows = Db::select(
            "SELECT work_type, COUNT(*) AS total
             FROM review_tasks
             WHERE {$where} AND status IN ('unassigned','in_progress','blocked')
             GROUP BY work_type
             ORDER BY total DESC, work_type ASC",
            $params,
        );

        return array_map(
            static fn (array $row): array => [
                'work_type' => (string) $row['work_type'],
                'total' => (int) $row['total'],
            ],
            $rows,
        );
    }

    /**
     * Queue page: filtered, paginated task list.
     *
     * @param array{scope?: string, work_type?: string, q?: string, page?: int} $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function paginate(string $userId, array $filters, int $perPage = 25, bool $platformOnly = false): array
    {
        [$where, $params] = self::buildWhere($userId, $filters, $platformOnly ? self::PLATFORM_WORK_TYPES : null, null);

        return self::paginateQuery($where, $params, $filters, $perPage);
    }

    /**
     * Workspace admin queue: only the caller's team and workspace work types.
     *
     * @param array{scope?: string, work_type?: string, q?: string, page?: int} $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function paginateForTeam(string $userId, string $teamId, array $filters, int $perPage = 25): array
    {
        [$where, $params] = self::buildWhere($userId, $filters, self::WORKSPACE_WORK_TYPES, $teamId);

        return self::paginateQuery($where, $params, $filters, $perPage);
    }

    /** @return array{rows: list<array<string, mixed>>, total: int} */
    private static function paginateQuery(string $where, array $params, array $filters, int $perPage): array
    {
        $countRow = Db::selectOne(
            "SELECT COUNT(*) AS total FROM review_tasks rt LEFT JOIN teams t ON t.id = rt.team_id WHERE {$where}",
            $params,
        );
        $total = (int) ($countRow['total'] ?? 0);

        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $rows = Db::select(
            "SELECT rt.*, t.name AS team_name,
                    u.name AS assignee_name
             FROM review_tasks rt
             LEFT JOIN teams t ON t.id = rt.team_id
             LEFT JOIN users u ON u.id = rt.assigned_user_id
             WHERE {$where}
             ORDER BY
                CASE WHEN rt.due_at IS NOT NULL AND rt.due_at < NOW(3)
                          AND rt.status IN ('unassigned','in_progress','blocked') THEN 0 ELSE 1 END,
                rt.due_at IS NULL, rt.due_at ASC, rt.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $taskId): ?array
    {
        return Db::selectOne(
            'SELECT rt.*, t.name AS team_name, u.name AS assignee_name
             FROM review_tasks rt
             LEFT JOIN teams t ON t.id = rt.team_id
             LEFT JOIN users u ON u.id = rt.assigned_user_id
             WHERE rt.id = ? LIMIT 1',
            [$taskId],
        );
    }

    /** @param array<string, mixed> $data */
    public static function create(array $data): array
    {
        $id = Uuid::v4();
        Db::insert('review_tasks', [
            'id' => $id,
            'team_id' => $data['team_id'] ?? null,
            'work_type' => $data['work_type'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'unassigned',
            'priority' => $data['priority'] ?? 'normal',
            'assigned_user_id' => $data['assigned_user_id'] ?? null,
            'due_at' => $data['due_at'] ?? null,
            'target_type' => $data['target_type'] ?? null,
            'target_id' => $data['target_id'] ?? null,
            'created_by_user_id' => $data['created_by_user_id'] ?? null,
        ]);

        $task = self::find($id);
        if ($task === null) {
            throw new \RuntimeException('Failed to create review task');
        }
        return $task;
    }

    /** @param array<string, string|int|null> $fields */
    public static function update(string $taskId, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $assignments = implode(', ', array_map(
            static fn (string $column): string => "`{$column}` = :{$column}",
            array_keys($fields),
        ));
        $statement = Db::pdo()->prepare("UPDATE review_tasks SET {$assignments} WHERE id = :id");
        $statement->bindValue(':id', $taskId);
        foreach ($fields as $column => $value) {
            $statement->bindValue(':' . $column, $value);
        }
        $statement->execute();
    }

    public static function countOpen(): int
    {
        $row = Db::selectOne(
            "SELECT COUNT(*) AS total FROM review_tasks WHERE status IN ('unassigned','in_progress','blocked')",
        );
        return (int) ($row['total'] ?? 0);
    }

    /**
     * Create one KBY review task per submitted organisation profile that does
     * not have an open task yet. Safe to call on every Action Center load.
     */
    public static function syncSubmittedOrganisations(?string $actorUserId): int
    {
        if (!self::tableExists('organization_profiles')) {
            return 0;
        }

        $pending = Db::select(
            "SELECT op.id, op.team_id, t.name AS team_name
             FROM organization_profiles op
             INNER JOIN teams t ON t.id = op.team_id
             WHERE op.status = 'submitted'
               AND NOT EXISTS (
                   SELECT 1 FROM review_tasks rt
                   WHERE rt.target_type = 'organization_profile'
                     AND rt.target_id = op.id
                     AND rt.status IN ('unassigned','in_progress','blocked','completed')
               )",
        );

        $created = 0;
        foreach ($pending as $profile) {
            self::create([
                'team_id' => (string) $profile['team_id'],
                'work_type' => 'organisation_kyb',
                'title' => 'Review organisation profile — ' . (string) $profile['team_name'],
                'description' => 'Verify registration documents and organisation details before approving the workspace.',
                'priority' => 'high',
                'due_at' => gmdate('Y-m-d H:i:s', time() + 2 * 24 * 60 * 60),
                'target_type' => 'organization_profile',
                'target_id' => (string) $profile['id'],
                'created_by_user_id' => $actorUserId,
            ]);
            $created++;
        }

        return $created;
    }

    /** @return array<string, int> platform-wide counters for the KPI tiles */
    public static function platformMetrics(): array
    {
        $metrics = [];

        $count = static function (string $sql): int {
            $row = Db::selectOne($sql);
            return (int) ($row['total'] ?? 0);
        };

        $metrics['workspaces'] = $count('SELECT COUNT(*) AS total FROM teams WHERE deleted_at IS NULL');
        $metrics['projects'] = $count('SELECT COUNT(*) AS total FROM projects WHERE deleted_at IS NULL');
        $metrics['people'] = $count('SELECT COUNT(*) AS total FROM users WHERE deleted_at IS NULL');
        $metrics['drafts'] = $count('SELECT COUNT(*) AS total FROM drafts');
        $metrics['drafts_in_review'] = $count("SELECT COUNT(*) AS total FROM drafts WHERE status = 'in_review'");
        $metrics['templates_published'] = $count("SELECT COUNT(*) AS total FROM template_versions WHERE status = 'published'");
        $metrics['fact_sheets_pending'] = $count(
            "SELECT COUNT(*) AS total FROM projects WHERE deleted_at IS NULL AND status = 'facts_pending_approval'",
        );
        $metrics['open_tasks'] = self::countOpen();

        $metrics['organisations_submitted'] = self::tableExists('organization_profiles')
            ? $count("SELECT COUNT(*) AS total FROM organization_profiles WHERE status = 'submitted'")
            : 0;
        $metrics['organisation_documents'] = self::tableExists('organization_documents')
            ? $count('SELECT COUNT(*) AS total FROM organization_documents')
            : 0;

        return $metrics;
    }

    /**
     * @param array{scope?: string, work_type?: string, q?: string} $filters
     * @param list<string>|null $restrictTypes when set, only these work types
     * @return array{0: string, 1: list<mixed>}
     */
    private static function buildWhere(string $userId, array $filters, ?array $restrictTypes = null, ?string $teamId = null): array
    {
        $clauses = [];
        $params = [];

        if ($teamId !== null) {
            $clauses[] = 'rt.team_id = ?';
            $params[] = $teamId;
        }

        if ($restrictTypes !== null) {
            $clauses[] = 'rt.work_type IN (' . implode(',', array_map(
                static fn (string $type): string => "'" . $type . "'",
                $restrictTypes,
            )) . ')';
        }

        $scope = (string) ($filters['scope'] ?? 'open');
        switch ($scope) {
            case 'mine':
                $clauses[] = "rt.assigned_user_id = ? AND rt.status IN ('unassigned','in_progress','blocked')";
                $params[] = $userId;
                break;
            case 'unassigned':
                $clauses[] = "rt.assigned_user_id IS NULL AND rt.status = 'unassigned'";
                break;
            case 'breached':
                $clauses[] = self::BREACH_SQL;
                break;
            case 'completed':
                $clauses[] = "rt.status IN ('completed','cancelled')";
                break;
            case 'all':
                $clauses[] = '1 = 1';
                break;
            case 'open':
            default:
                $clauses[] = "rt.status IN ('unassigned','in_progress','blocked')";
                break;
        }

        $workType = (string) ($filters['work_type'] ?? '');
        if ($workType !== '' && in_array($workType, self::WORK_TYPES, true)) {
            $clauses[] = 'rt.work_type = ?';
            $params[] = $workType;
        }

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $clauses[] = '(rt.title LIKE ? OR rt.description LIKE ? OR t.name LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        return [implode(' AND ', $clauses), $params];
    }
}
