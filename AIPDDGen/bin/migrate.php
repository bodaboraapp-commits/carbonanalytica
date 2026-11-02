<?php

declare(strict_types=1);

/**
 * Apply pending migrations to the configured database.
 *
 * Usage: php bin/migrate.php
 */
require __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Db;
use App\Migrator;

$pdo = Db::pdo();
$applied = Migrator::run($pdo, APP_ROOT . '/migrations');

if ($applied === []) {
    echo "No pending migrations for database '" . Config::get('db.name') . "'.\n";
    exit(0);
}

echo 'Applied to ' . Config::get('db.name') . ': ' . implode(', ', $applied) . "\n";
