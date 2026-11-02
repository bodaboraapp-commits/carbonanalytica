<?php

declare(strict_types=1);

use App\Db;
use App\Repositories\UserRepository;
use App\Services\DumpImportService;

/**
 * Browser import of database/pdd_generator_deploy.sql (staff console URL).
 */
function make_dump_staff(string $email = 'dump-staff@example.com'): array
{
    $account = make_user($email, 'Dump Staff');
    Db::execute('UPDATE users SET is_platform_admin = 1 WHERE id = ?', [(string) $account['user']['id']]);
    $account['user'] = UserRepository::findById((string) $account['user']['id']);
    return $account;
}

return [
    'dump import is staff-only' => static function (): void {
        $tenant = make_user('dump-tenant@example.com');
        $userId = (string) $tenant['user']['id'];

        assert_app_error('not_found', static function () use ($userId): void {
            DumpImportService::inspect($userId);
        });
        assert_app_error('not_found', static function () use ($userId): void {
            DumpImportService::import($userId, true, false);
        });

        // the file exists in the deployment even for the guard test
        assert_true(is_file(APP_ROOT . '/database/pdd_generator_deploy.sql'), 'deploy dump missing');
    },

    'inspect describes the committed dump and current database' => static function (): void {
        $staff = make_dump_staff('dump-inspect@example.com');
        $state = DumpImportService::inspect((string) $staff['user']['id']);

        assert_true($state['exists']);
        assert_true((int) $state['size'] > 10_000);
        assert_true((int) $state['statements'] > 50);
        assert_true((int) $state['dump_tables'] >= 20);
        assert_same(64, strlen((string) $state['sha256']));
        assert_same('pdd_generator_test', (string) $state['database']);
        assert_true($state['safe_to_import'], 'test database starts empty');
    },

    'dry run counts statements without touching data' => static function (): void {
        $staff = make_dump_staff('dump-dry@example.com');
        $result = DumpImportService::import((string) $staff['user']['id'], false, true);

        assert_true($result['statements'] > 50);
        assert_true($result['tables'] >= 20);
        assert_same(1, count_rows('users')); // the acting staff account
        assert_same(0, count_rows('templates'));
        assert_same(0, count_rows('audit_logs', "action = 'admin.database.dump_imported'"));
    },

    'imports the dump into an empty database and is audited' => static function (): void {
        $staff = make_dump_staff('dump-run@example.com');
        $result = DumpImportService::import((string) $staff['user']['id'], false, false);

        assert_true($result['statements'] > 50);
        assert_true($result['tables'] >= 20);
        $auditCount = (int) (Db::selectOne(
            "SELECT COUNT(*) AS total FROM audit_logs WHERE action IN ('admin.database.dump_imported','deploy.dump_imported')",
        )['total'] ?? 0);
        assert_same(1, $auditCount);

        assert_not_null(UserRepository::findByEmail('admin@pddgen.local'));
        assert_not_null(UserRepository::findByEmail('demo@pddgen.local'));
        assert_same(1, count_rows('templates', "name = 'Gold Standard Template Guide v1.4'"));
        assert_same(6, count_rows('methodologies'));
        assert_same(5, count_rows('review_tasks'));
        assert_same(2, count_rows('projects'));

        // The dump marks migrations 0001-0006 as applied, so the app sees a
        // fully migrated database.
        assert_same(6, count_rows('schema_migrations'));

        // The dump rebuilds the users table, so continuing as its admin account.
        $admin = UserRepository::findByEmail('admin@pddgen.local');
        $state = DumpImportService::inspect((string) $admin['id']);
        assert_false($state['safe_to_import'], 'after import the database has data');
    },

    'refuses to replace existing data without confirmation' => static function (): void {
        $staff = make_dump_staff('dump-guard@example.com');
        $userId = (string) $staff['user']['id'];

        DumpImportService::import($userId, false, false);
        $usersBefore = count_rows('users');

        $admin = UserRepository::findByEmail('admin@pddgen.local');
        $actor = (string) ($admin['id'] ?? '');

        assert_app_error('conflict', static function () use ($actor): void {
            DumpImportService::import($actor, false, false);
        });

        assert_same($usersBefore, count_rows('users'), 'blocked import must not change data');

        // Explicit confirmation imports again.
        $result = DumpImportService::import($actor, true, false);
        assert_true($result['backed_up']);
        assert_not_null(UserRepository::findByEmail('admin@pddgen.local'));
        assert_same(1, count_rows('audit_logs', "action = 'admin.database.dump_imported'")); // the dump rebuilds audit_logs
    },

    'token-gated import runs without a session, then requires replacement' => static function (): void {
        $state = \App\Services\DumpImportService::inspectData();
        assert_true($state['safe_to_import'], 'empty test database is safe');

        $result = \App\Services\DumpImportService::importAsDeployToken(false, false);
        assert_true($result['statements'] > 50);
        assert_same(1, count_rows('audit_logs', "action = 'deploy.dump_imported'"));
        assert_not_null(UserRepository::findByEmail('admin@pddgen.local'));

        // Second run needs explicit replacement, even through the token route.
        assert_app_error('conflict', static function (): void {
            \App\Services\DumpImportService::importAsDeployToken(false, false);
        });

        $dry = \App\Services\DumpImportService::importAsDeployToken(true, true);
        assert_true($dry['statements'] > 50);

        $replaced = \App\Services\DumpImportService::importAsDeployToken(true, false);
        assert_true($replaced['backed_up']);
        assert_same(1, count_rows('audit_logs', "action = 'deploy.dump_imported'")); // each import rebuilds audit_logs
    },
];