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

        // The hero-card trend foot no longer says "New": when the previous week
        // is empty it labels the real direction derived from current vs previous
        // totals (Increasing / Decreasing / No change).
        assert_false(str_contains($markup, 'is-new'), 'hero cards must not render the old "New" trend state');
        $directionLabels = ['up' => 'Increasing', 'down' => 'Decreasing', 'flat' => 'No change'];
        $labelledDirections = 0;
        foreach ($overview['trends'] as $key => $trend) {
            if ($trend['delta'] !== null) {
                continue;
            }
            $labelledDirections++;
            assert_contains($directionLabels[$trend['direction']], $markup, $key . ' trend must render its real direction label');
        }
        assert_true($labelledDirections >= 1, 'at least one hero card must exercise the direction label (empty previous week)');

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
        $owner = make_user('console-audit-user@example.com');

        \App\Services\ProjectService::create((string) $owner['user']['id'], (string) $owner['team']['id'], 'Audit project');

        $log = PlatformConsoleService::audit((string) $staff['user']['id'], []);
        assert_true($log['total'] >= 1);

        $filtered = PlatformConsoleService::audit((string) $staff['user']['id'], ['action' => 'project.created']);
        assert_true($filtered['total'] >= 1);
        foreach ($filtered['rows'] as $row) {
            assert_contains('project.created', (string) $row['action']);
        }
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
];
