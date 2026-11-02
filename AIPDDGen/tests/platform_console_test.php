<?php

declare(strict_types=1);

use App\Db;
use App\Repositories\UserRepository;
use App\Services\PlatformConsoleService;
use App\Uuid;
use App\View;

/**
 * Fresh platform console: staff-only data across every workspace.
 */
function make_console_staff(string $email = 'console-staff@example.com'): array
{
    $account = make_user($email, 'Console Staff');
    Db::execute('UPDATE users SET is_platform_admin = 1 WHERE id = ?', [(string) $account['user']['id']]);
    $account['user'] = UserRepository::findById((string) $account['user']['id']);
    return $account;
}

return [
    'hides every console page from tenant accounts' => static function (): void {
        $tenant = make_user('console-tenant@example.com');
        $userId = (string) $tenant['user']['id'];

        foreach (['overview', 'workspaces', 'users', 'kyc'] as $method) {
            assert_app_error('not_found', static function () use ($method, $userId): void {
                match ($method) {
                    'overview' => PlatformConsoleService::overview($userId),
                    'workspaces' => PlatformConsoleService::workspaces($userId, []),
                    'users' => PlatformConsoleService::users($userId, []),
                    'kyc' => PlatformConsoleService::kycQueue($userId),
                };
            }, 'method ' . $method);
        }

        assert_app_error('not_found', static function () use ($userId): void {
            PlatformConsoleService::audit($userId, []);
        });
        assert_app_error('not_found', static function () use ($userId): void {
            PlatformConsoleService::templates($userId);
        });
        assert_app_error('not_found', static function () use ($userId): void {
            PlatformConsoleService::platformTotals($userId);
        });
    },

    'overview reports platform-wide numbers and plan distribution' => static function (): void {
        $staff = make_console_staff('console-overview@example.com');
        $owner = make_user('console-ws@example.com');
        // The owner counts as a creator; add an editor so the staff total is >= 1.
        add_team_member((string) $owner['team']['id'], (string) $owner['user']['id'], 'console-overview-editor@example.com', 'editor');

        $overview = PlatformConsoleService::overview((string) $staff['user']['id']);

        foreach (['workspaces', 'projects', 'people', 'open_tasks', 'templates_published', 'organisations_submitted', 'verified_orgs'] as $key) {
            assert_true(array_key_exists($key, $overview['metrics']), 'missing metric ' . $key);
        }
        // Platform-wide people totals: Admin/Creators + Staff/Workers.
        foreach (['creators_total', 'staff_total'] as $key) {
            assert_true(array_key_exists($key, $overview['metrics']), 'missing metric ' . $key);
            assert_true(is_int($overview['metrics'][$key]), $key . ' must be an integer');
            assert_true($overview['metrics'][$key] >= 1, $key . ' must count at least one active member');
        }
        assert_true($overview['metrics']['workspaces'] >= 2);
        assert_true(isset($overview['plans']['free']));
        assert_true(array_key_exists('recent_audit', $overview));
        assert_true(array_key_exists('recent_workspaces', $overview));
        assert_true(array_key_exists('kyc_preview', $overview));
        assert_not_null($owner['team']['id']);

        // Hero stat cards read real 7-day trends: a daily series per metric and
        // a percent change against the previous 7 days (null when the previous
        // period is empty; the template then labels the real direction instead
        // of inventing a percentage).
        assert_true(array_key_exists('trends', $overview), 'overview must expose trends');
        foreach (['users', 'projects', 'drafts', 'tasks', 'workspaces'] as $key) {
            assert_true(array_key_exists($key, $overview['trends']), 'missing trend ' . $key);
            $trend = $overview['trends'][$key];
            assert_true(array_key_exists('series', $trend), $key . ' trend needs a series');
            assert_true(is_array($trend['series']) && array_is_list($trend['series']), $key . ' series must be a list');
            assert_true(count($trend['series']) >= 6, $key . ' series needs at least 6 daily points');
            foreach ($trend['series'] as $index => $point) {
                assert_true(is_int($point), $key . ' series point ' . $index . ' must be an int');
                assert_true($point >= 0, $key . ' series point ' . $index . ' must not be negative');
            }
            assert_true(array_key_exists('delta', $trend), $key . ' trend needs a delta');
            assert_true(
                $trend['delta'] === null || is_int($trend['delta']),
                $key . ' delta must be an int or null',
            );
            assert_true(array_key_exists('direction', $trend), $key . ' trend needs a direction');
            assert_true(
                in_array($trend['direction'], ['up', 'down', 'flat'], true),
                $key . ' direction must be up, down or flat',
            );
        }
        // Counts are real rows: this test just created accounts and teams today.
        assert_true(array_sum($overview['trends']['users']['series']) >= 1, 'users trend must count today\'s signups');
        assert_true(array_sum($overview['trends']['workspaces']['series']) >= 1, 'workspaces trend must count today\'s teams');

        // Charts need both 7-day windows: `current` sums the daily series and
        // `previous` is the real row count of the 7 days before (both ints >= 0).
        // No pixel values are asserted, only that the windows are real and consistent.
        foreach (['users', 'projects', 'drafts', 'tasks', 'workspaces'] as $key) {
            $trend = $overview['trends'][$key];
            foreach (['current', 'previous'] as $field) {
                assert_true(array_key_exists($field, $trend), $key . ' trend needs ' . $field);
                assert_true(is_int($trend[$field]), $key . ' ' . $field . ' must be an int');
                assert_true($trend[$field] >= 0, $key . ' ' . $field . ' must not be negative');
            }
            assert_same(array_sum($trend['series']), $trend['current'], $key . ' current must equal its daily series total');
            $expectedDirection = $trend['current'] > $trend['previous']
                ? 'up'
                : ($trend['current'] < $trend['previous'] ? 'down' : 'flat');
            assert_same($expectedDirection, $trend['direction'], $key . ' direction must match current vs previous totals');
        }

        // The template turns those real totals into server-rendered charts: an
        // inline SVG line/area chart, the week-over-week bar chart and the stat
        // strip for the remaining numbers (no JS, no external assets).
        $markup = View::capture('pages/admin/overview', [
            'user' => $staff['user'],
            'overview' => $overview,
            'taskBadge' => 0,
        ]);
        foreach ([
            'growth-grid',
            'growth-chart',
            'growth-bars',
            'growth-strip',
            'growth-chart__line',
            'growth-bars__bar',
            '<svg',
            'visually-hidden growth-chart__data',
        ] as $marker) {
            assert_contains($marker, $markup, 'overview markup must contain ' . $marker);
        }

        // The hero-card trend foot shows the real absolute change across the two
        // 7-day windows (current − previous): green '+N' when it went up, red
        // '−N' when it went down, muted '0' when unchanged. No words/percents.
        $minus = "\u{2212}";
        assert_false(str_contains($markup, 'is-new'), 'hero cards must not render the old "New" trend state');
        assert_false(str_contains($markup, 'Increasing'), 'hero cards must not render the old Increasing word');
        assert_false(str_contains($markup, 'Decreasing'), 'hero cards must not render the old Decreasing word');
        assert_false(str_contains($markup, 'No change'), 'hero cards must not render the old No change word');
        assert_false(str_contains($markup, '% in 7 days'), 'hero cards must not render the old percent text');

        // Parse the four rendered hero-card feet (order: workspaces, projects,
        // users, tasks): class (green up / red down / muted flat), the optional
        // leading direction arrow, and the absolute-change text.
        assert_same(
            4,
            preg_match_all('/stat-card__delta (is-up|is-down|is-flat)">(.*?)<span>([^<]*)<\/span>/s', $markup, $feetMatch),
            'overview must render four hero-card trend feet with up/down/flat classes',
        );
        $cardTrendKeys = ['workspaces', 'projects', 'users', 'tasks'];
        $upArrowPath = 'M8 12.5V3.5';
        $downArrowPath = 'M8 3.5v9';
        $feetChecked = 0;
        foreach ($cardTrendKeys as $index => $key) {
            $trend = $overview['trends'][$key];
            $change = $trend['current'] - $trend['previous'];
            $expectedClass = $change > 0 ? 'is-up' : ($change < 0 ? 'is-down' : 'is-flat');
            $expectedText = $change > 0 ? '+' . $change : ($change < 0 ? $minus . abs($change) : '0');
            assert_same($expectedClass, $feetMatch[1][$index], $key . ' hero card trend class');
            assert_same($expectedText, $feetMatch[3][$index], $key . ' hero card absolute change text');
            $arrowHtml = $feetMatch[2][$index];
            if ($change > 0) {
                assert_contains($upArrowPath, $arrowHtml, $key . ' hero card must render the upward arrow');
            } elseif ($change < 0) {
                assert_contains($downArrowPath, $arrowHtml, $key . ' hero card must render the downward arrow');
            } else {
                assert_false(str_contains($arrowHtml, '<svg'), $key . ' flat hero card must render no arrow');
            }
            $feetChecked++;
        }
        assert_same(4, $feetChecked, 'every hero card foot must be checked against its real totals');

        // Layout requirements: both growth charts render at the same size (one
        // shared aspect ratio with equal grid columns) and the stat strip sits
        // on top of the two charts.
        assert_true(
            strpos($markup, 'growth-strip') < strpos($markup, 'growth-grid'),
            'growth stat strip must render above the two charts',
        );
        assert_same(1, preg_match('/class="growth-chart__svg"[^>]*viewBox="0 0 ([\d.]+) ([\d.]+)"/', $markup, $chartBox), 'line chart svg needs a viewBox');
        assert_same(1, preg_match('/class="growth-bars__svg"[^>]*viewBox="0 0 ([\d.]+) ([\d.]+)"/', $markup, $barBox), 'bar chart svg needs a viewBox');
        $chartRatio = (float) $chartBox[1] / (float) $chartBox[2];
        $barRatio = (float) $barBox[1] / (float) $barBox[2];
        assert_true(
            abs($chartRatio - $barRatio) < 0.001,
            'both growth charts must share an aspect ratio so they render at the same dimensions',
        );

        // The overview no longer duplicates the users directory: the "Newest
        // users" panel is gone; admins use the Users page "new" filter instead.
        assert_false(str_contains($markup, 'super-new-users-title'), 'overview must not render the Newest users panel');
        assert_false(str_contains($markup, t('super.recent_users')), 'overview must not render the Newest users title');

        // The overview also no longer lists the newest workspaces; the full
        // list lives on /admin/workspaces.
        assert_false(str_contains($markup, 'super-ws-title'), 'overview must not render the Newest workspaces panel');
        assert_false(str_contains($markup, t('console.recent_workspaces')), 'overview must not render the Newest workspaces title');

        // "Recent activity" moved to the Audit & security page: the data stays in
        // the service payload, but the overview no longer renders the panel.
        assert_false(str_contains($markup, 'super-audit-title'), 'overview must not render the Recent activity panel');
        assert_false(str_contains($markup, t('console.recent_audit')), 'overview must not render the Recent activity heading');

        // The Platform totals panel moved to /admin/system: the overview shows
        // the numbers nowhere anymore (no ledger panel markup or heading).
        assert_false(str_contains($markup, 'super-balance-title'), 'overview must not render the Platform totals panel');
        assert_false(str_contains($markup, t('super.ledger')), 'overview must not render the Platform totals heading');
    },

    // SYNTHETIC RENDER TEST ONLY: the dev/test databases have no real decreases
    // in the 7-day windows yet, so one card's previous-week total is forced
    // higher than its current-week total to exercise the red '−N' branch. The
    // production code path itself only ever uses the real current/previous
    // values returned by PlatformConsoleService::overview().
    'hero card trend foot renders the minus form for a decrease' => static function (): void {
        $staff = make_console_staff('console-trend-foot@example.com');
        $userId = (string) $staff['user']['id'];
        make_user('console-trend-foot-ws@example.com');

        $overview = PlatformConsoleService::overview($userId);
        // Synthetic window for the Projects card: current week 0, previous week 5.
        $overview['trends']['projects'] = [
            'series' => [5, 0, 0, 0, 0, 0, 0],
            'delta' => null,
            'current' => 0,
            'previous' => 5,
            'direction' => 'down',
        ];

        $markup = View::capture('pages/admin/overview', [
            'user' => $staff['user'],
            'overview' => $overview,
            'taskBadge' => 0,
        ]);

        $minus = "\u{2212}";
        assert_contains('stat-card__delta is-down', $markup, 'a decreasing trend must render the red is-down class');
        assert_contains('<span>' . $minus . '5</span>', $markup, 'a decreasing trend must render the real drop with a minus sign');
        assert_same(
            1,
            preg_match('/stat-card__delta is-down">\s*<svg[^>]*><path d="M8 3\.5v9/', $markup),
            'a decreasing trend must render the downward arrow before the number',
        );
        assert_false(str_contains($markup, 'Decreasing'), 'the trend foot must not fall back to words');

        // SYNTHETIC flat window (Tasks card: current 0, previous 0) renders a
        // muted '0' with no arrow at all.
        $overview['trends']['tasks'] = [
            'series' => [0, 0, 0, 0, 0, 0, 0],
            'delta' => null,
            'current' => 0,
            'previous' => 0,
            'direction' => 'flat',
        ];
        $flatMarkup = View::capture('pages/admin/overview', [
            'user' => $staff['user'],
            'overview' => $overview,
            'taskBadge' => 0,
        ]);
        assert_same(
            1,
            preg_match('/stat-card__delta is-flat">\s*<span>0<\/span>/', $flatMarkup),
            'a flat trend must render a muted 0 with no arrow before it',
        );
    },

    'platform totals render as cards on the system health page' => static function (): void {
        $staff = make_console_staff('console-system@example.com');
        $userId = (string) $staff['user']['id'];
        $owner = make_user('console-system-ws@example.com');

        // Real rows so the counters have something genuine to count.
        Db::insert('organization_profiles', [
            'id' => Uuid::v4(),
            'team_id' => (string) $owner['team']['id'],
            'legal_name' => 'System Totals Ltd',
            'status' => 'submitted',
            'submitted_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $totals = PlatformConsoleService::platformTotals($userId);

        foreach (['kyc_waiting', 'verified_orgs', 'templates_published', 'documents_uploaded'] as $key) {
            assert_true(array_key_exists($key, $totals), 'missing platform total ' . $key);
            assert_true(is_int($totals[$key]), $key . ' must be an int');
            assert_true($totals[$key] >= 0, $key . ' must not be negative');
        }
        assert_true(is_array($totals['plans']), 'plans must be an array');
        foreach (['free', 'payg', 'team'] as $plan) {
            assert_true(array_key_exists($plan, $totals['plans']), 'missing plan ' . $plan);
            assert_true(is_int($totals['plans'][$plan]), 'plan ' . $plan . ' must be an int');
            assert_true($totals['plans'][$plan] >= 0, 'plan ' . $plan . ' must not be negative');
        }
        assert_true($totals['kyc_waiting'] >= 1, 'the submitted KYC profile must be counted');

        // The system health template renders the five totals as compact stat
        // cards ABOVE the existing health summary table (no JS needed).
        $systemMarkup = View::capture('pages/admin/system', [
            'user' => $staff['user'],
            'system' => PlatformConsoleService::systemHealth($userId),
            'totals' => $totals,
            'taskBadge' => 0,
        ]);
        foreach ([
            'stat-grid',
            'stat-card__value',
            'system-totals-title',
            'summary-list',
            t('super.ledger'),
            t('super.kyc_waiting'),
            t('super.verified_orgs'),
            t('super.templates_live'),
            t('super.docs_uploaded'),
            t('console.plan_free'),
        ] as $marker) {
            assert_contains($marker, $systemMarkup, 'system page markup must contain ' . $marker);
        }
        assert_same(5, substr_count($systemMarkup, '<article class="stat-card">'), 'system page must render five totals cards');

        $cardsPosition = strpos($systemMarkup, 'stat-grid');
        $tablePosition = strpos($systemMarkup, 'summary-list');
        assert_true($cardsPosition !== false && $tablePosition !== false, 'system page needs both cards and the health table');
        assert_true($cardsPosition < $tablePosition, 'platform totals cards must render above the health table');
    },

    'workspaces list is searchable and shows KYC status' => static function (): void {
        $staff = make_console_staff('console-ws-admin@example.com');
        $alice = make_user('console-alice@example.com');
        $bob = make_user('console-bob@example.com');

        Db::insert('organization_profiles', [
            'id' => Uuid::v4(),
            'team_id' => (string) $alice['team']['id'],
            'legal_name' => 'Acme Carbon Ltd',
            'status' => 'submitted',
            'submitted_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $all = PlatformConsoleService::workspaces((string) $staff['user']['id'], []);
        assert_true($all['total'] >= 3);

        $search = PlatformConsoleService::workspaces((string) $staff['user']['id'], ['q' => 'Acme']);
        assert_same(1, $search['total']);
        assert_same('submitted', (string) $search['rows'][0]['kyc_status']);

        // Each row names its admin/creator and counts staff/workers.
        assert_same((string) $alice['user']['name'], (string) $search['rows'][0]['creator_name']);
        assert_true((int) $search['rows'][0]['creators'] >= 1);
        assert_same(0, (int) $search['rows'][0]['staff']);

        $submitted = PlatformConsoleService::workspaces((string) $staff['user']['id'], ['kyc' => 'submitted']);
        assert_same(1, $submitted['total']);

        assert_not_null($bob['team']['id']);
    },

    'users list marks platform admins and can be filtered' => static function (): void {
        $staff = make_console_staff('console-users@example.com');
        $tenant = make_user('console-plain@example.com');

        $all = PlatformConsoleService::users((string) $staff['user']['id'], []);
        assert_true($all['total'] >= 2);

        $admins = PlatformConsoleService::users((string) $staff['user']['id'], ['admin' => '1']);
        assert_true($admins['total'] >= 1);
        foreach ($admins['rows'] as $row) {
            assert_same(1, (int) $row['is_platform_admin']);
        }

        $search = PlatformConsoleService::users((string) $staff['user']['id'], ['q' => 'console-plain']);
        assert_same(1, $search['total']);
        assert_same((string) $tenant['user']['id'], (string) $search['rows'][0]['id']);

        // "New users" filter: accounts created in the last 7 days only.
        $staffId = (string) $staff['user']['id'];
        $newUsers = PlatformConsoleService::users($staffId, ['new' => '1']);
        assert_true($newUsers['total'] >= 2, 'new-users filter must find recently created accounts');
        $foundNew = false;
        foreach ($newUsers['rows'] as $row) {
            assert_true(
                strtotime((string) $row['created_at']) >= time() - 8 * 86400,
                'new-users filter must only return accounts created in the last 7 days',
            );
            if ((string) $row['id'] === (string) $tenant['user']['id']) {
                $foundNew = true;
            }
        }
        assert_true($foundNew, 'new-users filter must include the just-created account');

        // The new-users filter composes with the platform-admin filter.
        $newAdmins = PlatformConsoleService::users($staffId, ['admin' => '1', 'new' => '1']);
        assert_true($newAdmins['total'] >= 1, 'new filter must compose with the platform-admin filter');
        foreach ($newAdmins['rows'] as $row) {
            assert_same(1, (int) $row['is_platform_admin']);
        }

        // A backdated account is excluded from the new-users filter, with and
        // without a search term, while remaining visible in the normal list.
        $old = make_user('console-old@example.com');
        $oldId = (string) $old['user']['id'];
        Db::execute(
            'UPDATE users SET created_at = ? WHERE id = ?',
            [gmdate('Y-m-d H:i:s', time() - 30 * 86400), $oldId],
        );
        assert_same(1, PlatformConsoleService::users($staffId, ['q' => 'console-old'])['total']);
        assert_same(0, PlatformConsoleService::users($staffId, ['q' => 'console-old', 'new' => '1'])['total']);
        $newAfter = PlatformConsoleService::users($staffId, ['new' => '1']);
        $newAfterIds = array_map(static fn (array $row): string => (string) $row['id'], $newAfter['rows']);
        assert_false(in_array($oldId, $newAfterIds, true), 'backdated account must not appear in the new-users filter');
    },

    'KYC queue lists submitted profiles with documents and review state' => static function (): void {
        $staff = make_console_staff('console-kyc@example.com');
        $owner = make_user('console-kyc-org@example.com');

        $profileId = Uuid::v4();
        Db::insert('organization_profiles', [
            'id' => $profileId,
            'team_id' => (string) $owner['team']['id'],
            'legal_name' => 'Kisumu Carbon Coop',
            'status' => 'submitted',
            'submitted_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $queue = PlatformConsoleService::kycQueue((string) $staff['user']['id']);
        assert_same(1, (int) ($queue['counts']['submitted'] ?? 0));

        $found = null;
        foreach ($queue['rows'] as $row) {
            if ((string) $row['id'] === $profileId) {
                $found = $row;
            }
        }
        assert_not_null($found);
        assert_same('Kisumu Carbon Coop', (string) $found['legal_name']);
        assert_same((string) $owner['team']['name'], (string) $found['team_name']);
        assert_same(0, (int) $found['documents']);
    },

    'template library lists versions with parsed section and field counts' => static function (): void {
        $staff = make_console_staff('console-templates@example.com');

        $templateId = Uuid::v4();
        Db::insert('templates', [
            'id' => $templateId,
            'name' => 'Console Test Template',
            'kind' => 'pdd_template',
            'registry' => 'gold_standard',
            'language' => 'en',
        ]);
        $versionId = Uuid::v4();
        Db::insert('template_versions', [
            'id' => $versionId,
            'template_id' => $templateId,
            'version_label' => 'v1.0',
            'language' => 'en',
            'status' => 'draft',
        ]);
        $sectionId = Uuid::v4();
        Db::insert('template_sections', [
            'id' => $sectionId,
            'template_version_id' => $versionId,
            'ordinal' => 1,
            'stable_key' => 'A',
            'heading' => 'Description',
            'level' => 1,
        ]);
        Db::insert('template_fields', [
            'id' => Uuid::v4(),
            'template_section_id' => $sectionId,
            'ordinal' => 1,
            'field_key' => 'project_name',
            'label' => 'Project name',
            'field_type' => 'text',
        ]);

        $templates = PlatformConsoleService::templates((string) $staff['user']['id']);
        $row = null;
        foreach ($templates['rows'] as $candidate) {
            if ((string) $candidate['id'] === $versionId) {
                $row = $candidate;
            }
        }
        assert_not_null($row);
        assert_same('Console Test Template', (string) $row['template_name']);
        assert_same(1, (int) $row['sections']);
        assert_same(1, (int) $row['fields']);
        assert_true(($templates['counts']['draft'] ?? 0) >= 1);
    },

    'audit log lists activity and filters by action' => static function (): void {
        $staff = make_console_staff('console-audit@example.com');
        $staffId = (string) $staff['user']['id'];
        $owner = make_user('console-audit-user@example.com');

        \App\Services\ProjectService::create((string) $owner['user']['id'], (string) $owner['team']['id'], 'Audit project');

        $log = PlatformConsoleService::audit($staffId, []);
        assert_true($log['total'] >= 1);

        $filtered = PlatformConsoleService::audit($staffId, ['action' => 'project.created']);
        assert_true($filtered['total'] >= 1);
        foreach ($filtered['rows'] as $row) {
            assert_contains('project.created', (string) $row['action']);
        }

        // Enough real audit rows for two pages of the leading Recent activity
        // panel (5 per page): every sign-up writes a real audit entry.
        for ($i = 1; $i <= 5; $i++) {
            make_user('console-audit-extra-' . $i . '@example.com');
        }

        // Recent activity leads the page: latest unfiltered entries, 5 per page,
        // paged with its own `recent_page` parameter.
        $recent = PlatformConsoleService::audit($staffId, ['page' => 1, 'per_page' => 5]);
        assert_same(5, count($recent['rows']), 'Recent activity must render exactly five entries per page');
        assert_true($recent['pages'] >= 2, 'test data must span at least two Recent activity pages');
        assert_same(
            $recent['total'],
            PlatformConsoleService::audit($staffId, [])['total'],
            'Recent activity must use the unfiltered audit total',
        );

        $pageData = [
            'user' => $staff['user'],
            'audit' => PlatformConsoleService::audit($staffId, []),
            'recent_audit' => $recent['rows'],
            'recent_page' => $recent['page'],
            'recent_pages' => $recent['pages'],
            'recent_total' => $recent['total'],
            'taskBadge' => 0,
        ];
        $markup = View::capture('pages/admin/audit', $pageData);

        // Two-column layout with Recent activity FIRST, then the audit log table.
        assert_contains('audit-columns', $markup, 'audit page needs the two-column layout');
        $recentPos = strpos($markup, 'audit-recent-title');
        $logPos = strpos($markup, 'audit-log-title');
        assert_true($recentPos !== false && $logPos !== false, 'audit page needs both column headings');
        assert_true($recentPos < $logPos, 'Recent activity must render before the Audit log column');

        // Recent panel: exactly five list rows and its own next-page link.
        $recentPanel = substr($markup, (int) $recentPos, (int) $logPos - (int) $recentPos);
        assert_same(5, preg_match_all('/summary-list__item/', $recentPanel), 'Recent activity must render exactly five rows on page 1');
        assert_contains('recent_page=2', $recentPanel, 'Recent activity needs its own recent_page next link');
        assert_contains(t('console.recent_audit'), $markup, 'audit page needs the Recent activity heading');

        // The full audit trail is untouched: same table, action filter and rows.
        assert_contains('admin-table', $markup, 'audit page needs the audit log table');
        assert_contains(t('console.link_audit'), $markup, 'audit page needs the audit log heading');
        assert_contains('name="action"', $markup, 'audit page needs the action filter');
        assert_contains('project.created', $markup, 'audit page must render real audit rows');

        // The table filter still narrows the table while the recent panel stays.
        $filteredMarkup = View::capture('pages/admin/audit', [
            'audit' => PlatformConsoleService::audit($staffId, ['action' => 'project.created']),
        ] + $pageData);
        assert_contains('audit-recent-title', $filteredMarkup, 'filtering the table must keep the recent panel');
        assert_same(1, preg_match_all('/<code class="mono">/', $filteredMarkup), 'the action filter must narrow the audit table to one row');
        assert_true(
            preg_match_all('/<code class="mono">/', $filteredMarkup) < preg_match_all('/<code class="mono">/', $markup),
            'the filtered audit table must render fewer rows than the unfiltered one',
        );
    },

    'granting and revoking platform admin is audited and guarded' => static function (): void {
        $staff = make_console_staff('console-grant@example.com');
        $member = make_user('console-member@example.com');
        $memberId = (string) $member['user']['id'];

        $promoted = PlatformConsoleService::setPlatformAdmin((string) $staff['user']['id'], $memberId, true);
        assert_same(1, (int) $promoted['is_platform_admin']);
        assert_same(1, count_rows('audit_logs', "action = 'admin.user.platform_admin_changed'"));

        // The new admin can act as staff.
        assert_not_null(PlatformConsoleService::overview($memberId));

        $demoted = PlatformConsoleService::setPlatformAdmin((string) $staff['user']['id'], $memberId, false);
        assert_same(0, (int) $demoted['is_platform_admin']);

        // Self-demotion is refused to prevent lockout.
        assert_app_error('conflict', static function () use ($staff): void {
            PlatformConsoleService::setPlatformAdmin((string) $staff['user']['id'], (string) $staff['user']['id'], false);
        });

        assert_app_error('not_found', static function () use ($staff, $memberId): void {
            PlatformConsoleService::setPlatformAdmin($memberId, (string) $staff['user']['id'], false);
        }, 'a tenant account cannot manage console access');
    },

    'AI Engine page renders the honest not-configured placeholder' => static function (): void {
        $staff = make_console_staff('console-ai@example.com');

        $markup = View::capture('pages/admin/ai', [
            'user' => $staff['user'],
            'taskBadge' => 0,
        ]);

        assert_contains(t('ai.title'), $markup, 'AI Engine page must render its title');
        assert_contains(t('ai.sub'), $markup, 'AI Engine page must render its subtitle');
        assert_contains(t('ai.status'), $markup, 'AI Engine page must state the real not-configured status');
        assert_contains(t('ai.badge'), $markup, 'AI Engine page must render the not-configured badge');
        assert_contains('panel-card', $markup, 'AI Engine page must use the standard panel card');

        // No provider/model/API-key settings are invented on an unconfigured build.
        foreach (['api_key', 'API key', 'openai', 'model_name', 'temperature'] as $invented) {
            assert_false(str_contains($markup, $invented), 'AI Engine page must not invent the setting ' . $invented);
        }
    },
];
