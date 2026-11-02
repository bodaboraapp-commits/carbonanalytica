<?php

declare(strict_types=1);

/**
 * Reset the development database.
 *
 * Usage: php bin/reset.php --confirm
 *
 * Drops every table in the configured database and re-runs the migrations.
 * Refuses to run against a database whose name does not end with `_test` unless
 * --confirm is passed, so it cannot silently wipe production data.
 */
require __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Db;
use App\Migrator;

$database = (string) Config::get('db.name');
$confirmed = in_array('--confirm', $argv, true);

if (!$confirmed && !str_ends_with($database, '_test')) {
    fwrite(STDERR, "Refusing to drop '{$database}' without --confirm.\n");
    exit(1);
}

$pdo = Db::pdo();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$tables = $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchAll();
foreach ($tables as $table) {
    $name = (string) $table['table_name'];
    $pdo->exec('DROP TABLE IF EXISTS `' . $name . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$applied = Migrator::run($pdo, APP_ROOT . '/migrations');

echo "Reset '{$database}'.";
echo $applied === [] ? " No migrations applied.\n" : ' Applied: ' . implode(', ', $applied) . "\n";
echo "Next: php bin/seed.php\n";
