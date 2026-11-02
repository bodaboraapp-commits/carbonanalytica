<?php

declare(strict_types=1);

/**
 * One-time (and repeatable) database setup.
 *
 * Usage: php bin/setup.php
 *
 * Creates the application database and its test twin, then applies every
 * migration. Safe to run again: existing migrations are skipped.
 */
require __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Db;
use App\Migrator;

$database = (string) Config::get('db.name');
$testDatabase = $database . '_test';

if (!preg_match('/^[A-Za-z0-9_]+$/', $database) || !preg_match('/^[A-Za-z0-9_]+$/', $testDatabase)) {
    fwrite(STDERR, "Refusing: database name must be alphanumeric/underscore.\n");
    exit(1);
}

$server = Db::connect(false);
$charset = (string) Config::get('db.charset');

foreach ([$database, $testDatabase] as $name) {
    $server->exec(sprintf(
        'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s_unicode_ci',
        $name,
        $charset,
        $charset,
    ));
    echo "database ready: {$name}\n";
}

$migrations = APP_ROOT . '/migrations';

foreach ([[$database, false], [$testDatabase, true]] as [$name, $isTest]) {
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            Config::get('db.host'),
            Config::get('db.port'),
            $name,
            $charset,
        ),
        (string) Config::get('db.user'),
        (string) Config::get('db.pass'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    $applied = Migrator::run($pdo, $migrations);
    $label = $isTest ? 'test database' : 'application database';
    if ($applied === []) {
        echo "{$label}: up to date (no migrations applied)\n";
    } else {
        echo "{$label}: applied " . implode(', ', $applied) . "\n";
    }
}

echo "Setup complete.\n";
echo "Next: php bin/seed.php   (demo workspace and methodology catalogue)\n";
