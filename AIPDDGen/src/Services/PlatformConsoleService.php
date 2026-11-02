<?php

declare(strict_types=1);

namespace App\Services;

use App\AppError;
use App\Audit;
use App\Db;
use App\Repositories\ReviewTaskRepository;
use App\Repositories\UserRepository;

/**
 * Platform console data: every workspace, every user, KYC submissions, the
 * template library, the audit trail and the platform-wide numbers.
 *
 * Staff-only (routes are gated on `users.is_platform_admin`); this service also
 * re-checks the flag so no future caller can bypass the guard.
 */
final class PlatformConsoleService
{
    private const PER_PAGE = 20;

    public static function assertStaff(string $userId): array
    {
        return TaskCenterService::assertStaff($userId);
    }

    /** @return array<string, mixed> */
    public static function overview(string $userId): array
    {
        self::assertStaff($userId);

        $metrics = ReviewTaskRepository::platformMetrics();
        $metrics['verified_orgs'] = ReviewTaskRepository::tableExists('organization_profiles')
            ? (int) (Db::selectOne("SELECT COUNT(*) AS total FROM organization_profiles WHERE status = 'verified'")['total'] ?? 0)
            : 0;
        $metrics['org_profile_total'] = ReviewTaskRepository::tableExists('organization_profiles')
            ? (int) (Db::selectOne('SELECT COUNT(*) AS total FROM organization_profiles')['total'] ?? 0)
            : 0;
        $metrics['rejected_orgs'] = ReviewTaskRepository::tableExists('organization_profiles')
            ? (int) (Db::selectOne("SELECT COUNT(*) AS total FROM organization_profiles WHERE status = 'rejected'")['total'] ?? 0)
            : 0;

        $plans = Db::select('SELECT plan, COUNT(*) AS total FROM teams WHERE deleted_at IS NULL GROUP BY plan');
        $planCounts = [];
        foreach ($plans as $row) {
            $planCounts[(string) $row['plan']] = (int) $row['total'];
        }

        return [
            'metrics' => $metrics,
            'plans' => $planCounts,
            'recent_audit' => self::audit($userId, ['per_page' => 8])['rows'],
            'recent_workspaces' => self::workspaces($userId, ['per_page' => 5])['rows'],
            'kyc_preview' => self::kycQueue($userId)['rows'],
            'templates_preview' => self::templates($userId)['rows'],
        ];
    }

    /**
     * @param array{q?: string, kyc?: string, page?: int, per_page?: int} $filters
     * @return array{rows: list<array<string,mixed>>, total: int, page: int, pages: int}
     */
    public static function workspaces(string $userId, array $filters): array
    {
        self::assertStaff($userId);

        $where = ['t.deleted_at IS NULL'];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(t.name LIKE ? OR t.slug LIKE ? OR op.legal_name LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $kyc = (string) ($filters['kyc'] ?? '');
        if (in_array($kyc, ['draft', 'submitted', 'verified', 'rejected'], true)) {
            $where[] = 'op.status = ?';
            $params[] = $kyc;
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) (Db::selectOne(
            "SELECT COUNT(*) AS total FROM teams t LEFT JOIN organization_profiles op ON op.team_id = t.id WHERE {$whereSql}",
            $params,
        )['total'] ?? 0);

        $perPage = max(1, min((int) ($filters['per_page'] ?? self::PER_PAGE), 100));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $rows = Db::select(
            "SELECT t.id, t.name, t.slug, t.plan, t.created_at,
                    op.status AS kyc_status, op.legal_name, op.submitted_at,
                    (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = t.id AND tm.status = 'active') AS members,
                    (SELECT COUNT(*) FROM projects p WHERE p.team_id = t.id AND p.deleted_at IS NULL) AS projects,
                    (SELECT MAX(al.created_at) FROM audit_logs al WHERE al.team_id = t.id) AS last_activity
             FROM teams t
             LEFT JOIN organization_profiles op ON op.team_id = t.id
             WHERE {$whereSql}
             ORDER BY t.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * @param array{q?: string, admin?: string, page?: int, per_page?: int} $filters
     * @return array{rows: list<array<string,mixed>>, total: int, page: int, pages: int}
     */
    public static function users(string $userId, array $filters): array
    {
        self::assertStaff($userId);

        $where = ['u.deleted_at IS NULL'];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(u.name LIKE ? OR u.email LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }

        if (($filters['admin'] ?? '') === '1') {
            $where[] = 'u.is_platform_admin = 1';
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) (Db::selectOne("SELECT COUNT(*) AS total FROM users u WHERE {$whereSql}", $params)['total'] ?? 0);

        $perPage = max(1, min((int) ($filters['per_page'] ?? self::PER_PAGE), 100));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $rows = Db::select(
            "SELECT u.id, u.name, u.email, u.locale, u.is_platform_admin, u.email_verified_at,
                    u.last_seen_at, u.created_at,
                    (SELECT COUNT(*) FROM team_members tm WHERE tm.user_id = u.id AND tm.status = 'active') AS teams
             FROM users u
             WHERE {$whereSql}
             ORDER BY u.is_platform_admin DESC, u.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /** @return array{rows: list<array<string,mixed>>, counts: array<string,int>} */
    public static function kycQueue(string $userId): array
    {
        self::assertStaff($userId);

        if (!ReviewTaskRepository::tableExists('organization_profiles')) {
            return ['rows' => [], 'counts' => []];
        }

        $rows = Db::select(
            "SELECT op.id, op.team_id, op.legal_name, op.trading_name, op.status, op.submitted_at, op.reviewed_at,
                    t.name AS team_name,
                    (SELECT COUNT(*) FROM organization_documents od WHERE od.team_id = op.team_id) AS documents,
                    (SELECT rt.id FROM review_tasks rt
                     WHERE rt.target_type = 'organization_profile' AND rt.target_id = op.id
                       AND rt.status IN ('unassigned','in_progress','blocked')
                     ORDER BY rt.created_at DESC LIMIT 1) AS open_task_id
             FROM organization_profiles op
             INNER JOIN teams t ON t.id = op.team_id
             WHERE op.status IN ('submitted','verified','rejected')
             ORDER BY FIELD(op.status, 'submitted', 'rejected', 'verified'), op.submitted_at DESC
             LIMIT 100",
        );

        $countsRows = Db::select('SELECT status, COUNT(*) AS total FROM organization_profiles GROUP BY status');
        $counts = [];
        foreach ($countsRows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return ['rows' => $rows, 'counts' => $counts];
    }

    /**
     * @return array{rows: list<array<string,mixed>>, counts: array<string,int>}
     */
    public static function templates(string $userId): array
    {
        self::assertStaff($userId);

        $rows = Db::select(
            "SELECT tv.id, tv.version_label, tv.language, tv.status, tv.effective_date, tv.published_at,
                    tv.validated_at, tpl.name AS template_name, tpl.registry, tpl.kind,
                    (SELECT COUNT(*) FROM template_sections ts WHERE ts.template_version_id = tv.id) AS sections,
                    (SELECT COUNT(*) FROM template_fields tf
                     INNER JOIN template_sections ts2 ON ts2.id = tf.template_section_id
                     WHERE ts2.template_version_id = tv.id) AS fields
             FROM template_versions tv
             INNER JOIN templates tpl ON tpl.id = tv.template_id
             ORDER BY tv.created_at DESC
             LIMIT 100",
        );

        $countsRows = Db::select('SELECT status, COUNT(*) AS total FROM template_versions GROUP BY status');
        $counts = [];
        foreach ($countsRows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return ['rows' => $rows, 'counts' => $counts];
    }

    /**
     * @param array{action?: string, page?: int, per_page?: int} $filters
     * @return array{rows: list<array<string,mixed>>, total: int, page: int, pages: int}
     */
    public static function audit(string $userId, array $filters): array
    {
        self::assertStaff($userId);

        $where = ['1 = 1'];
        $params = [];

        $action = trim((string) ($filters['action'] ?? ''));
        if ($action !== '') {
            $where[] = 'al.action LIKE ?';
            $params[] = '%' . $action . '%';
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) (Db::selectOne("SELECT COUNT(*) AS total FROM audit_logs al WHERE {$whereSql}", $params)['total'] ?? 0);

        $perPage = max(1, min((int) ($filters['per_page'] ?? 25), 100));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $rows = Db::select(
            "SELECT al.id, al.action, al.target_type, al.target_id, al.metadata, al.created_at,
                    u.name AS actor_name, u.email AS actor_email, t.name AS team_name
             FROM audit_logs al
             LEFT JOIN users u ON u.id = al.actor_user_id
             LEFT JOIN teams t ON t.id = al.team_id
             WHERE {$whereSql}
             ORDER BY al.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /** Grant or revoke the platform-admin flag for one account. */
    public static function setPlatformAdmin(string $actorUserId, string $targetUserId, bool $enabled): array
    {
        self::assertStaff($actorUserId);

        $target = UserRepository::findById($targetUserId);
        if ($target === null) {
            throw AppError::notFound('That account');
        }

        if (!$enabled) {
            if ($targetUserId === $actorUserId) {
                // Prevent locking yourself out of the console.
                throw AppError::conflict('You cannot remove your own platform-admin access.');
            }

            $admins = (int) (Db::selectOne('SELECT COUNT(*) AS total FROM users WHERE is_platform_admin = 1 AND deleted_at IS NULL')['total'] ?? 0);
            if ($admins <= 1) {
                throw AppError::conflict('At least one platform admin must remain.');
            }
        }

        Db::transaction(static function () use ($actorUserId, $targetUserId, $enabled): void {
            UserRepository::update($targetUserId, ['is_platform_admin' => $enabled ? 1 : 0]);

            Audit::record([
                'actor_user_id' => $actorUserId,
                'action' => 'admin.user.platform_admin_changed',
                'target_type' => 'user',
                'target_id' => $targetUserId,
                'metadata' => ['enabled' => $enabled],
            ]);
        });

        return UserRepository::findById($targetUserId) ?? $target;
    }

    public static function taskBadge(string $userId): int
    {
        return ReviewTaskRepository::summary($userId)['breached'];
    }
}
