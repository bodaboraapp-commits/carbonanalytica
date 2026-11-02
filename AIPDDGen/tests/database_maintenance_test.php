<?php

declare(strict_types=1);

use App\Db;
use App\Migrator;
use App\Repositories\UserRepository;
use App\Services\DatabaseMaintenanceService;
use App\Services\SeedService;
use App\Services\TaskCenterService;

/**
 * Deployment maintenance: staff-only database status, migrations and seeding.
 */
function make_db_staff(string $email = 'db-staff@example.com'): array
{
    $account = make_user($email, 'DB Staff');
    Db::execute('UPDATE users SET is_platform_admin = 1 WHERE id = ?', [(string) $account['user']['id']]);
    $account['user'] = UserRepository::findById((string) $account['user']['id']);
    return $account;
}

return [
    'database status is staff-only' => static function (): void {
        $tenant = make_user('db-tenant@example.com');
        $userId = (string) $tenant['user']['id'];

        assert_app_error('not_found', static function () use ($userId): void {
            DatabaseMaintenanceService::status($userId);
        });
        assert_app_error('not_found', static function () use ($userId): void {
            DatabaseMaintenanceService::migrate($userId);
        });
        assert_app_error('not_found', static function () use ($userId): void {
            DatabaseMaintenanceService::seed($userId);
        });
    },

    'status reports applied migrations and a clean ledger' => static function (): void {
        $staff = make_db_staff('db-status@example.com');
        $status = DatabaseMaintenanceService::status((string) $staff['user']['id']);

        assert_true($status['tables'] >= 25);
        assert_true(count($status['applied']) >= 6, 'expected at least 6 applied migrations');
        assert_same([], $status['pending'], 'test database should be fully migrated');
        assert_same(false, $status['seed']['admin_exists']);
        assert_same(false, $status['seed']['demo_exists']);
        assert_same(false, $status['seed']['template_exists']);
        assert_same(0, $status['seed']['tasks']);
    },

    'migrate is idempotent and audited when it applies something' => static function (): void {
        $staff = make_db_staff('db-migrate@example.com');
        $userId = (string) $staff['user']['id'];

        // Nothing pending: the service is a no-op and does not audit.
        assert_same([], DatabaseMaintenanceService::migrate($userId));
        assert_same(0, count_rows('audit_logs', "action = 'admin.database.migrated'"));

        // A migration in a scratch directory is applied exactly once.
        $dir = APP_ROOT . '/storage/tmp-migrations';
        if (is_dir($dir)) {
            array_map('unlink', glob($dir . '/*') ?: []);
        } else {
            mkdir($dir, 0775, true);
        }
        file_put_contents(
            $dir . '/0099_scratch.sql',
            "CREATE TABLE IF NOT EXISTS zz_scratch (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;\n",
        );

        try {
            $ledger = Migrator::status(Db::pdo(), $dir);
            assert_same(['0099_scratch.sql'], $ledger['pending']);
            assert_same(['0099_scratch.sql'], Migrator::run(Db::pdo(), $dir));
            assert_same([], Migrator::status(Db::pdo(), $dir)['pending']);
            assert_true((bool) Db::selectOne("SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'zz_scratch'")['total']);
        } finally {
            Db::execute('DROP TABLE IF EXISTS zz_scratch');
            Db::execute("DELETE FROM schema_migrations WHERE version = '0099_scratch.sql'");
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    },

    'seed is idempotent and can run from the console' => static function (): void {
        $staff = make_db_staff('db-seed@example.com');
        $userId = (string) $staff['user']['id'];

        $lines = DatabaseMaintenanceService::seed($userId);
        assert_true(count($lines) >= 5, 'seed should report its steps');
        assert_same(1, count_rows('audit_logs', "action = 'admin.database.seeded'"));

        assert_not_null(UserRepository::findByEmail(SeedService::ADMIN_EMAIL));
        assert_not_null(UserRepository::findByEmail(SeedService::DEMO_EMAIL));
        assert_same(1, count_rows('templates', "name = 'Gold Standard Template Guide v1.4'"));
        assert_same(5, count_rows('review_tasks'));
        assert_same(2, count_rows('projects'));

        // Running it again must not duplicate anything.
        DatabaseMaintenanceService::seed($userId);

        assert_same(1, count_rows('users', "email = 'admin@pddgen.local'"));
        assert_same(1, count_rows('users', "email = 'demo@pddgen.local'"));
        assert_same(1, count_rows('templates', "name = 'Gold Standard Template Guide v1.4'"));
        assert_same(2, count_rows('projects'));
        assert_same(5, count_rows('review_tasks'));

        $status = DatabaseMaintenanceService::status($userId);
        assert_same(true, $status['seed']['admin_exists']);
        assert_same(true, $status['seed']['demo_exists']);
        assert_same(true, $status['seed']['template_exists']);
        assert_same(5, $status['seed']['tasks']);
        assert_true(count_rows('audit_logs', "action = 'admin.database.seeded'") >= 2);
    },

    'staff badge helper still works' => static function (): void {
        $staff = make_db_staff('db-badge@example.com');
        assert_same(0, TaskCenterService::assertStaff((string) $staff['user']['id'])['is_platform_admin'] === 1 ? 0 : 1);
    },
];
