<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit;
use App\Config;
use App\Db;
use App\Migrator;
use App\Repositories\ReviewTaskRepository;
use App\Repositories\UserRepository;

/**
 * Deployment maintenance: check and apply migrations / seed data from the staff
 * console. Read-only status is safe; both actions are additive and idempotent.
 *
 * Live-server usage:
 *  1. import `database/pdd_generator_deploy.sql` (or run `php bin/setup.php`), then
 *  2. sign in as a platform admin and click "Update database" in `/admin/database`.
 */
final class DatabaseMaintenanceService
{
    public static function status(string $userId): array
    {
        TaskCenterService::assertStaff($userId);

        $pdo = Db::pdo();
        $ledger = Migrator::status($pdo, APP_ROOT . '/migrations');

        $tableRow = $pdo->query(
            "SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE()",
        )->fetch();
        $database = (string) Config::get('db.name');

        return [
            'database' => $database,
            'host' => (string) Config::get('db.host'),
            'port' => (int) Config::get('db.port'),
            'tables' => (int) ($tableRow['total'] ?? 0),
            'applied' => $ledger['applied'],
            'pending' => $ledger['pending'],
            'seed' => self::seedReadiness(),
            'php_version' => PHP_VERSION,
        ];
    }

    /** @return list<string> applied migration versions */
    public static function migrate(string $userId): array
    {
        TaskCenterService::assertStaff($userId);

        $applied = Migrator::run(Db::pdo(), APP_ROOT . '/migrations');

        if ($applied !== []) {
            Audit::record([
                'actor_user_id' => $userId,
                'action' => 'admin.database.migrated',
                'target_type' => 'database',
                'target_id' => (string) Config::get('db.name'),
                'metadata' => ['versions' => $applied],
            ]);
        }

        return $applied;
    }

    /** @return list<string> seed log lines */
    public static function seed(string $userId): array
    {
        TaskCenterService::assertStaff($userId);

        $result = SeedService::run(true);

        Audit::record([
            'actor_user_id' => $userId,
            'action' => 'admin.database.seeded',
            'target_type' => 'database',
            'target_id' => (string) Config::get('db.name'),
            'metadata' => ['lines' => count($result['lines'])],
        ]);

        return $result['lines'];
    }

    /** @return array{admin_exists: bool, demo_exists: bool, template_exists: bool, tasks: int} */
    private static function seedReadiness(): array
    {
        $template = Db::selectOne("SELECT id FROM templates WHERE name = 'Gold Standard Template Guide v1.4' LIMIT 1");

        return [
            'admin_exists' => UserRepository::findByEmail(SeedService::ADMIN_EMAIL) !== null,
            'demo_exists' => UserRepository::findByEmail(SeedService::DEMO_EMAIL) !== null,
            'template_exists' => $template !== null,
            'tasks' => ReviewTaskRepository::countOpen(),
        ];
    }
}
