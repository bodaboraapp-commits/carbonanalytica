<?php

declare(strict_types=1);

use App\Db;
use App\Repositories\UserRepository;
use App\Services\PlatformConsoleService;
use App\Services\PlatformSettingsService;
use App\Services\ProjectService;

/**
 * Super Admin dashboard: platform settings toggles and the enriched stats.
 */
function make_super_staff(string $email = 'super-staff@example.com'): array
{
    $account = make_user($email, 'Super Staff');
    Db::execute('UPDATE users SET is_platform_admin = 1 WHERE id = ?', [(string) $account['user']['id']]);
    $account['user'] = UserRepository::findById((string) $account['user']['id']);
    return $account;
}

return [
    'settings default to maintenance off and sign-ups on' => static function (): void {
        PlatformSettingsService::clearCache();

        assert_false(PlatformSettingsService::maintenanceMode());
        assert_true(PlatformSettingsService::signupsEnabled());
        assert_same(['maintenance_mode', 'signups_enabled'], PlatformSettingsService::editableKeys());
    },

    'staff can flip maintenance mode and sign-ups, and it is audited' => static function (): void {
        $staff = make_super_staff('super-toggle@example.com');
        $userId = (string) $staff['user']['id'];

        PlatformSettingsService::setEnabled($userId, PlatformSettingsService::MAINTENANCE_MODE, true);
        assert_true(PlatformSettingsService::maintenanceMode());
        assert_same(1, count_rows('audit_logs', "action = 'admin.settings.changed'"));

        PlatformSettingsService::setEnabled($userId, PlatformSettingsService::SIGNUPS_ENABLED, false);
        assert_false(PlatformSettingsService::signupsEnabled());
        assert_same(2, count_rows('audit_logs', "action = 'admin.settings.changed'"));

        // Turning maintenance off restores the normal state.
        PlatformSettingsService::setEnabled($userId, PlatformSettingsService::MAINTENANCE_MODE, false);
        assert_false(PlatformSettingsService::maintenanceMode());

        $row = Db::selectOne("SELECT setting_value FROM platform_settings WHERE setting_key = 'signups_enabled'");
        assert_same('0', (string) $row['setting_value']);
    },

    'settings are staff-only and unknown keys are refused' => static function (): void {
        $tenant = make_user('super-tenant@example.com');
        $userId = (string) $tenant['user']['id'];

        assert_app_error('not_found', static function () use ($userId): void {
            PlatformSettingsService::setEnabled($userId, PlatformSettingsService::MAINTENANCE_MODE, true);
        });

        $staff = make_super_staff('super-guard@example.com');
        try {
            PlatformSettingsService::setEnabled((string) $staff['user']['id'], 'not_a_setting', true);
            throw new \RuntimeException('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            // expected
        }
    },

    'overview exposes stats, growth, system health and recent users' => static function (): void {
        $staff = make_super_staff('super-overview@example.com');
        $userId = (string) $staff['user']['id'];

        $owner = make_user('super-growth@example.com');
        ProjectService::create((string) $owner['user']['id'], (string) $owner['team']['id'], 'Growth project');
        Db::execute('UPDATE users SET last_seen_at = NOW(3) WHERE id = ?', [$userId]);

        $overview = PlatformConsoleService::overview($userId);

        foreach ([
            'workspaces', 'projects', 'people', 'open_tasks', 'admins',
            'signups_7d', 'signups_30d', 'projects_7d', 'drafts_7d',
            'active_7d', 'audit_24h', 'facts_total', 'review_breached',
            'verified_orgs', 'templates_published',
        ] as $key) {
            assert_true(array_key_exists($key, $overview['metrics']), 'missing metric ' . $key);
        }

        foreach (['db_size_mb', 'storage_mb', 'disk_free_mb', 'pending_migrations', 'php', 'env', 'url', 'mail'] as $key) {
            assert_true(array_key_exists($key, $overview['system']), 'missing system key ' . $key);
        }

        assert_true($overview['system']['pending_migrations'] >= 0);
        assert_true(isset($overview['settings']['maintenance_mode']));
        assert_true(isset($overview['settings']['signups_enabled']));
        assert_true(is_array($overview['recent_users']));
        assert_true($overview['metrics']['signups_7d'] >= 2);
        assert_true($overview['metrics']['projects_7d'] >= 1);
        assert_true($overview['metrics']['admins'] >= 1);
        assert_true($overview['metrics']['active_7d'] >= 1);
    },

    'maintenance mode flips are visible through the shared overview state' => static function (): void {
        $staff = make_super_staff('super-maintenance@example.com');
        $userId = (string) $staff['user']['id'];

        PlatformSettingsService::setEnabled($userId, PlatformSettingsService::MAINTENANCE_MODE, true);
        $overview = PlatformConsoleService::overview($userId);
        assert_same('1', $overview['settings']['maintenance_mode']);

        PlatformSettingsService::setEnabled($userId, PlatformSettingsService::MAINTENANCE_MODE, false);
        assert_same('0', PlatformConsoleService::overview($userId)['settings']['maintenance_mode']);
    },
];
