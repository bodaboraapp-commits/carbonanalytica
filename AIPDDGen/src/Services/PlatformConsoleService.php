<?php

declare(strict_types=1);

namespace App\Services;

use App\AppError;
use App\Audit;
use App\Config;
use App\Db;
use App\Migrator;
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

    /**
     * @param string $range growth window: '7'|'30'|'60'|'90'|'all' (invalid → '7')
     * @return array<string, mixed>
     */
    public static function overview(string $userId, string $range = '7'): array
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

        // Super Admin: growth, activity and overall totals.
        $since = static fn (int $days): string => gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $metrics['admins'] = (int) (Db::selectOne('SELECT COUNT(*) AS total FROM users WHERE is_platform_admin = 1 AND deleted_at IS NULL')['total'] ?? 0);
        $metrics['signups_7d'] = (int) (Db::selectOne('SELECT COUNT(*) AS total FROM users WHERE deleted_at IS NULL AND created_at >= ?', [$since(7)])['total'] ?? 0);
        $metrics['signups_30d'] = (int) (Db::selectOne('SELECT COUNT(*) AS total FROM users WHERE deleted_at IS NULL AND created_at >= ?', [$since(30)])['total'] ?? 0);
        $metrics['projects_7d'] = (int) (Db::selectOne('SELECT COUNT(*) AS total FROM projects WHERE deleted_at IS NULL AND created_at >= ?', [$since(7)])['total'] ?? 0);
        $metrics['drafts_7d'] = (int) (Db::selectOne('SELECT COUNT(*) AS total FROM drafts WHERE created_at >= ?', [$since(7)])['total'] ?? 0);
        $metrics['active_7d'] = (int) (Db::selectOne('SELECT COUNT(*) AS total FROM users WHERE deleted_at IS NULL AND last_seen_at >= ?', [$since(7)])['total'] ?? 0);
        $metrics['audit_24h'] = (int) (Db::selectOne('SELECT COUNT(*) AS total FROM audit_logs WHERE created_at >= ?', [$since(1)])['total'] ?? 0);
        $metrics['facts_total'] = (int) (Db::selectOne('SELECT COUNT(*) AS total FROM facts')['total'] ?? 0);
        $metrics['review_breached'] = (int) (Db::selectOne(
            "SELECT COUNT(*) AS total FROM review_tasks
             WHERE status IN ('unassigned','in_progress','blocked') AND due_at IS NOT NULL AND due_at < NOW(3)",
        )['total'] ?? 0);
        // Platform-wide Admin/Creator + Staff/Worker headcount across every workspace.
        $metrics['creators_total'] = (int) (Db::selectOne(
            "SELECT COUNT(*) AS total FROM team_members WHERE status = 'active' AND role IN ('owner','admin')",
        )['total'] ?? 0);
        $metrics['staff_total'] = (int) (Db::selectOne(
            "SELECT COUNT(*) AS total FROM team_members WHERE status = 'active' AND role IN ('editor','viewer')",
        )['total'] ?? 0);

        // Real last-7-days daily series + change against the previous 7 days.
        // Every point is a row count grouped by DATE(created_at); missing days
        // are filled with 0 so the sparkline geometry is stable. Deltas are
        // null when the previous period had no rows, so the template can show
        // "New" instead of inventing a percentage. `workspaces` feeds the hero
        // workspaces tile; `drafts` is available for draft-related surfaces.
        $trends = [
            'users' => self::dailyTrend('users', 'deleted_at IS NULL'),
            'projects' => self::dailyTrend('projects', 'deleted_at IS NULL'),
            'drafts' => self::dailyTrend('drafts'),
            'tasks' => self::dailyTrend('review_tasks'),
            'workspaces' => self::dailyTrend('teams', 'deleted_at IS NULL'),
        ];

        // The Growth and activity panel has its own user-selected window (range
        // chips); the hero cards above keep the last-7-days trends untouched.
        $range = self::normaliseGrowthRange($range);
        $growth = self::growthAnalytics($range);

        // System health (its own page under SYSTEM: /admin/system).
        $system = self::buildSystem();

        $plans = Db::select('SELECT plan, COUNT(*) AS total FROM teams WHERE deleted_at IS NULL GROUP BY plan');
        $planCounts = [];
        foreach ($plans as $row) {
            $planCounts[(string) $row['plan']] = (int) $row['total'];
        }

        $recentUsers = Db::select(
            'SELECT id, name, email, is_platform_admin, email_verified_at, created_at
             FROM users WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 5',
        );

        return [
            'metrics' => $metrics,
            'trends' => $trends,
            'range' => $range,
            'growth' => $growth,
            'system' => $system,
            'settings' => PlatformSettingsService::all(),
            'plans' => $planCounts,
            'recent_audit' => self::audit($userId, ['per_page' => 8])['rows'],
            'recent_workspaces' => self::workspaces($userId, ['per_page' => 5])['rows'],
            'recent_users' => $recentUsers,
            'kyc_preview' => self::kycQueue($userId)['rows'],
            'templates_preview' => self::templates($userId)['rows'],
        ];
    }

    /**
     * Last-7-days daily row counts (oldest first, 7 ints), the totals of this
     * week and the previous week, and the percent change between them.
     *
     * Real data only: counts come from rows grouped by DATE(created_at) over a
     * 14-day window; days with no rows are filled with 0. `current` is the sum
     * of `series` (last 7 days), `previous` the sum of the 7 days before that.
     * `delta` is null when the previous period had no rows at all (no
     * percentage can be derived); `direction` is still derived from the real
     * totals comparison (`up`/`down`/`flat`) so surfaces can label the trend
     * without inventing a percentage.
     *
     * @param string $table internal table literal (never user input)
     * @param string $where internal predicate for that table (never user input)
     * @return array{series: list<int>, delta: int|null, current: int, previous: int, direction: 'up'|'down'|'flat'}
     */
    private static function dailyTrend(string $table, string $where = '1 = 1'): array
    {
        $today = new \DateTimeImmutable(gmdate('Y-m-d'), new \DateTimeZone('UTC'));
        $windowStart = $today->modify('-13 days');

        $rows = Db::select(
            "SELECT DATE(created_at) AS day, COUNT(*) AS total
             FROM {$table}
             WHERE {$where} AND created_at >= ?
             GROUP BY DATE(created_at)",
            [$windowStart->format('Y-m-d 00:00:00')],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['day']] = (int) $row['total'];
        }

        $series = [];
        $previousTotal = 0;
        for ($offset = 13; $offset >= 0; $offset--) {
            $day = $today->modify(sprintf('-%d days', $offset))->format('Y-m-d');
            $count = $counts[$day] ?? 0;
            if ($offset >= 7) {
                $previousTotal += $count;
            } else {
                $series[] = $count;
            }
        }

        $currentTotal = array_sum($series);
        $delta = $previousTotal === 0
            ? null
            : (int) round((($currentTotal - $previousTotal) / $previousTotal) * 100);
        $direction = $currentTotal > $previousTotal
            ? 'up'
            : ($currentTotal < $previousTotal ? 'down' : 'flat');

        return [
            'series' => $series,
            'delta' => $delta,
            'current' => $currentTotal,
            'previous' => $previousTotal,
            'direction' => $direction,
        ];
    }

    /** Validate a Growth and activity range; anything unknown falls back to 7 days. */
    private static function normaliseGrowthRange(string $range): string
    {
        return in_array($range, ['7', '30', '60', '90', 'all'], true) ? $range : '7';
    }

    /**
     * Range-window growth payload for the "Growth and activity" panel.
     *
     * Real SQL only: per-metric counts grouped by DATE(created_at) inside the
     * selected window (monthly buckets for "all" when the real span is longer
     * than ~180 days), the window total, the total of the preceding
     * equal-length window (used by the bar comparison; 0 for "all" because no
     * earlier data window exists) and window counts for the two time-bound
     * strip metrics. Snapshot strip metrics are handled by the template.
     *
     * @return array{
     *   range: string,
     *   granularity: 'day'|'month',
     *   span_days: int,
     *   metrics: array<string, array{series: list<int>, labels: list<string>, total: int, previous: int}>,
     *   strip: array{active_users: int, new_users: int}
     * }
     */
    private static function growthAnalytics(string $range): array
    {
        $today = new \DateTimeImmutable(gmdate('Y-m-d'), new \DateTimeZone('UTC'));

        // Internal table literals only; the date bound is a prepared parameter.
        $sources = [
            'users' => ['table' => 'users', 'where' => 'deleted_at IS NULL'],
            'projects' => ['table' => 'projects', 'where' => 'deleted_at IS NULL'],
            'drafts' => ['table' => 'drafts', 'where' => '1 = 1'],
            'tasks' => ['table' => 'review_tasks', 'where' => '1 = 1'],
        ];

        $granularity = 'day';
        $previousWindow = null;
        if ($range === 'all') {
            $earliest = null;
            foreach ($sources as $source) {
                $row = Db::selectOne(
                    "SELECT MIN(created_at) AS first_at FROM {$source['table']} WHERE {$source['where']}",
                );
                $first = $row['first_at'] ?? null;
                if (is_string($first) && $first !== '' && ($earliest === null || $first < $earliest)) {
                    $earliest = $first;
                }
            }
            $seriesStart = $earliest !== null
                ? new \DateTimeImmutable(substr($earliest, 0, 10), new \DateTimeZone('UTC'))
                : $today;
            $spanDays = (int) $seriesStart->diff($today)->days + 1;
            $granularity = $spanDays > 180 ? 'month' : 'day';
            $windowStart = $seriesStart->format('Y-m-d 00:00:00');
        } else {
            $days = (int) $range;
            $spanDays = $days;
            $seriesStart = $today->modify(sprintf('-%d days', $days - 1));
            $windowStart = $seriesStart->format('Y-m-d 00:00:00');
            $previousWindow = [
                $today->modify(sprintf('-%d days', 2 * $days - 1))->format('Y-m-d 00:00:00'),
                $seriesStart->format('Y-m-d 00:00:00'),
            ];
        }

        $metrics = [];
        foreach ($sources as $key => $source) {
            $series = [];
            $labels = [];
            if ($granularity === 'month') {
                $rows = Db::select(
                    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS bucket, COUNT(*) AS total
                     FROM {$source['table']}
                     WHERE {$source['where']} AND created_at >= ?
                     GROUP BY DATE_FORMAT(created_at, '%Y-%m')",
                    [$windowStart],
                );
                $counts = [];
                foreach ($rows as $row) {
                    $counts[(string) $row['bucket']] = (int) $row['total'];
                }
                $cursor = new \DateTimeImmutable($seriesStart->format('Y-m-01'), new \DateTimeZone('UTC'));
                $lastMonth = new \DateTimeImmutable($today->format('Y-m-01'), new \DateTimeZone('UTC'));
                while ($cursor <= $lastMonth) {
                    $bucket = $cursor->format('Y-m');
                    $labels[] = $bucket;
                    $series[] = $counts[$bucket] ?? 0;
                    $cursor = $cursor->modify('+1 month');
                }
            } else {
                $rows = Db::select(
                    "SELECT DATE(created_at) AS bucket, COUNT(*) AS total
                     FROM {$source['table']}
                     WHERE {$source['where']} AND created_at >= ?
                     GROUP BY DATE(created_at)",
                    [$windowStart],
                );
                $counts = [];
                foreach ($rows as $row) {
                    $counts[(string) $row['bucket']] = (int) $row['total'];
                }
                $cursor = $seriesStart;
                while ($cursor <= $today) {
                    $day = $cursor->format('Y-m-d');
                    $labels[] = $day;
                    $series[] = $counts[$day] ?? 0;
                    $cursor = $cursor->modify('+1 day');
                }
            }

            $previous = 0;
            if ($previousWindow !== null) {
                $previous = (int) (Db::selectOne(
                    "SELECT COUNT(*) AS total FROM {$source['table']}
                     WHERE {$source['where']} AND created_at >= ? AND created_at < ?",
                    $previousWindow,
                )['total'] ?? 0);
            }

            $metrics[$key] = [
                'series' => $series,
                'labels' => $labels,
                'total' => array_sum($series),
                'previous' => $previous,
            ];
        }

        if ($range === 'all') {
            $activeUsers = (int) (Db::selectOne(
                'SELECT COUNT(*) AS total FROM users WHERE deleted_at IS NULL AND last_seen_at IS NOT NULL',
            )['total'] ?? 0);
            $newUsers = (int) (Db::selectOne(
                'SELECT COUNT(*) AS total FROM users WHERE deleted_at IS NULL',
            )['total'] ?? 0);
        } else {
            $activeUsers = (int) (Db::selectOne(
                'SELECT COUNT(*) AS total FROM users WHERE deleted_at IS NULL AND last_seen_at >= ?',
                [$windowStart],
            )['total'] ?? 0);
            $newUsers = (int) (Db::selectOne(
                'SELECT COUNT(*) AS total FROM users WHERE deleted_at IS NULL AND created_at >= ?',
                [$windowStart],
            )['total'] ?? 0);
        }

        return [
            'range' => $range,
            'granularity' => $granularity,
            'span_days' => $spanDays,
            'metrics' => $metrics,
            'strip' => [
                'active_users' => $activeUsers,
                'new_users' => $newUsers,
            ],
        ];
    }

    private static function directorySize(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += (int) $file->getSize();
            }
        }

        return $size;
    }

    /**
     * System health block for the dedicated /admin/system page.
     *
     * @return array<string, int|string>
     */
    public static function systemHealth(string $userId): array
    {
        self::assertStaff($userId);
        return self::buildSystem();
    }

    /** @return array<string, int|string> */
    private static function buildSystem(): array
    {
        $pendingMigrations = 0;
        try {
            $pendingMigrations = count(Migrator::status(Db::pdo(), APP_ROOT . '/migrations')['pending']);
        } catch (\Throwable) {
            $pendingMigrations = -1;
        }

        $dbSize = (float) (Db::selectOne(
            'SELECT ROUND((SUM(data_length) + SUM(index_length)) / 1024 / 1024, 1) AS total
             FROM information_schema.tables WHERE table_schema = DATABASE()',
        )['total'] ?? 0);
        $diskFree = @disk_free_space(APP_ROOT);

        return [
            'db_size_mb' => $dbSize,
            'storage_mb' => round(self::directorySize(APP_ROOT . '/storage') / 1024 / 1024, 1),
            'disk_free_mb' => $diskFree !== false ? round($diskFree / 1024 / 1024) : -1,
            'pending_migrations' => $pendingMigrations,
            'php' => PHP_VERSION,
            'env' => (string) Config::get('app.env'),
            'url' => (string) Config::get('app.url'),
            'mail' => (string) Config::get('email.transport'),
        ];
    }

    /**
     * Platform totals moved from the overview ("Platform totals" panel) onto the
     * dedicated System Health page, where they render as stat cards above the
     * health table: KYC/organisation/template/document counters plus the real
     * workspace plan distribution. Aggregate queries only; nothing invented.
     *
     * @return array{
     *     kyc_waiting: int,
     *     verified_orgs: int,
     *     templates_published: int,
     *     documents_uploaded: int,
     *     plans: array{free: int, payg: int, team: int}
     * }
     */
    public static function platformTotals(string $userId): array
    {
        self::assertStaff($userId);

        $metrics = ReviewTaskRepository::platformMetrics();
        $verifiedOrgs = ReviewTaskRepository::tableExists('organization_profiles')
            ? (int) (Db::selectOne("SELECT COUNT(*) AS total FROM organization_profiles WHERE status = 'verified'")['total'] ?? 0)
            : 0;

        $plans = ['free' => 0, 'payg' => 0, 'team' => 0];
        $planRows = Db::select('SELECT plan, COUNT(*) AS total FROM teams WHERE deleted_at IS NULL GROUP BY plan');
        foreach ($planRows as $row) {
            $plan = (string) $row['plan'];
            if (array_key_exists($plan, $plans)) {
                $plans[$plan] = (int) $row['total'];
            }
        }

        return [
            'kyc_waiting' => (int) $metrics['organisations_submitted'],
            'verified_orgs' => $verifiedOrgs,
            'templates_published' => (int) $metrics['templates_published'],
            'documents_uploaded' => (int) $metrics['organisation_documents'],
            'plans' => $plans,
        ];
    }

    /**
     * Real platform analytics for the Workspaces page stat cards. Every value
     * is an existing row count; plans come from the same grouping used on the
     * System Health page.
     *
     * @return array{total: int, kyc_waiting: int, verified_orgs: int, plans: array{free: int, payg: int, team: int}}
     */
    public static function workspaceAnalytics(string $userId): array
    {
        self::assertStaff($userId);

        $totals = self::platformTotals($userId);
        $total = (int) (Db::selectOne('SELECT COUNT(*) AS total FROM teams WHERE deleted_at IS NULL')['total'] ?? 0);

        return [
            'total' => $total,
            'kyc_waiting' => $totals['kyc_waiting'],
            'verified_orgs' => $totals['verified_orgs'],
            'plans' => $totals['plans'],
        ];
    }

    /**
     * Real platform analytics for the Users page stat cards: total accounts,
     * platform admins, accounts created in the last 7 days (same window as the
     * `new=1` filter) and accounts active in the last 7 days.
     *
     * @return array{total: int, platform_admins: int, new_7d: int, active_7d: int}
     */
    public static function userAnalytics(string $userId): array
    {
        self::assertStaff($userId);

        $since = gmdate('Y-m-d H:i:s', time() - 7 * 86400);

        return [
            'total' => (int) (Db::selectOne('SELECT COUNT(*) AS total FROM users WHERE deleted_at IS NULL')['total'] ?? 0),
            'platform_admins' => (int) (Db::selectOne('SELECT COUNT(*) AS total FROM users WHERE deleted_at IS NULL AND is_platform_admin = 1')['total'] ?? 0),
            'new_7d' => (int) (Db::selectOne('SELECT COUNT(*) AS total FROM users WHERE deleted_at IS NULL AND created_at >= ?', [$since])['total'] ?? 0),
            'active_7d' => (int) (Db::selectOne('SELECT COUNT(*) AS total FROM users WHERE deleted_at IS NULL AND last_seen_at >= ?', [$since])['total'] ?? 0),
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
                    (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = t.id AND tm.status = 'active' AND tm.role IN ('owner','admin')) AS creators,
                    (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = t.id AND tm.status = 'active' AND tm.role IN ('editor','viewer')) AS staff,
                    (SELECT u.name FROM team_members tm
                     INNER JOIN users u ON u.id = tm.user_id
                     WHERE tm.team_id = t.id AND tm.status = 'active' AND tm.role = 'owner' LIMIT 1) AS creator_name,
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
     * @param array{q?: string, admin?: string, new?: string, page?: int, per_page?: int} $filters
     * @return array{rows: list<array<string,mixed>>, total: int, page: int, pages: int, primary_admin_id: string|null}
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

        // "New users": accounts created in the last 7 days (composable with the
        // search and platform-admin filters). Fixed interval, no user input.
        if (($filters['new'] ?? '') === '1') {
            $where[] = 'u.created_at >= NOW(3) - INTERVAL 7 DAY';
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
            'primary_admin_id' => self::primaryPlatformAdminId(),
        ];
    }

    /**
     * The PRIMARY platform super admin is the earliest-created active platform
     * admin (tie-break id ASC, so the resolution is deterministic). It can never
     * be demoted, so a deployment always keeps its original super-admin account.
     */
    private static function primaryPlatformAdminId(): ?string
    {
        $row = Db::selectOne(
            'SELECT id FROM users
             WHERE is_platform_admin = 1 AND deleted_at IS NULL
             ORDER BY created_at ASC, id ASC
             LIMIT 1',
        );

        return $row === null ? null : (string) $row['id'];
    }

    /**
     * KYC review queue rows (staff scope), filtered by decision status.
     *
     * @param array{status?: string} $filters
     * @return array{rows: list<array<string,mixed>>, counts: array<string,int>, status: string}
     */
    public static function kycQueue(string $userId, array $filters = []): array
    {
        self::assertStaff($userId);

        $status = (string) ($filters['status'] ?? 'submitted');
        if (!in_array($status, ['submitted', 'verified', 'rejected', 'all'], true)) {
            $status = 'submitted';
        }

        if (!ReviewTaskRepository::tableExists('organization_profiles')) {
            return ['rows' => [], 'counts' => [], 'status' => $status];
        }

        $where = "op.status IN ('submitted','verified','rejected')";
        $params = [];
        if ($status !== 'all') {
            $where = 'op.status = ?';
            $params = [$status];
        }

        $rows = Db::select(
            "SELECT op.id, op.team_id, op.legal_name, op.trading_name, op.status, op.submitted_at,
                    op.reviewed_at, op.review_note,
                    t.name AS team_name,
                    (SELECT COUNT(*) FROM organization_documents od WHERE od.team_id = op.team_id) AS documents,
                    (SELECT rt.id FROM review_tasks rt
                     WHERE rt.target_type = 'organization_profile' AND rt.target_id = op.id
                       AND rt.status IN ('unassigned','in_progress','blocked')
                     ORDER BY rt.created_at DESC LIMIT 1) AS open_task_id
             FROM organization_profiles op
             INNER JOIN teams t ON t.id = op.team_id
             WHERE {$where}
             ORDER BY FIELD(op.status, 'submitted', 'rejected', 'verified'), op.submitted_at DESC
             LIMIT 100",
            $params,
        );

        $countsRows = Db::select('SELECT status, COUNT(*) AS total FROM organization_profiles GROUP BY status');
        $counts = [];
        foreach ($countsRows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return ['rows' => $rows, 'counts' => $counts, 'status' => $status];
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

            // The primary super admin (earliest-created active platform admin)
            // is permanent: no other admin may demote it.
            $primaryAdminId = self::primaryPlatformAdminId();
            if ($primaryAdminId !== null && $targetUserId === $primaryAdminId) {
                throw AppError::conflict('The primary platform administrator cannot be removed.');
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
