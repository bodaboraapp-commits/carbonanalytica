<?php

declare(strict_types=1);

use App\Db;
use App\Repositories\UserRepository;
use App\Services\PlatformConsoleService;
use App\Uuid;

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
