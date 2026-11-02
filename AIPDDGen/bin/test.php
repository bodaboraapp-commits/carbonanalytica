<?php

declare(strict_types=1);

/**
 * Test runner.
 *
 * Usage: php bin/test.php [filter]
 *
 * Uses the separate `_test` database, truncates between tests and prints a
 * pass/fail summary. No Composer, no PHPUnit: the suite is part of the app.
 */

putenv('APP_ENV=testing');
putenv('DB_NAME=pdd_generator_test');
putenv('EMAIL_TRANSPORT=silent');
putenv('APP_URL=http://pdd.test');

require __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Db;
use App\Migrator;

// ---------------------------------------------------------------------------
// Prepare the test database.
// ---------------------------------------------------------------------------
$database = (string) Config::get('db.name');
if (!str_ends_with($database, '_test')) {
    fwrite(STDERR, "Refusing to run: test database name must end with _test.\n");
    exit(1);
}

$server = Db::connect(false);
$server->exec(sprintf(
    'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    $database,
));

$pdo = Db::pdo();

// The workspace can run more than one process. Serialize test runs so one cannot
// truncate tables under another (which surfaces as random foreign-key errors).
$lockDeadline = microtime(true) + 300;
$locked = 0;
while ($locked !== 1 && microtime(true) < $lockDeadline) {
    $row = $pdo->query("SELECT GET_LOCK('pdd_generator_test_suite', 5) AS locked")->fetch();
    $locked = (int) ($row['locked'] ?? 0);
}
if ($locked !== 1) {
    fwrite(STDERR, "Another test run is holding the test database lock; try again in a moment.\n");
    exit(1);
}
$releaseLock = static function () use ($pdo): void {
    $pdo->query("SELECT RELEASE_LOCK('pdd_generator_test_suite')")->fetch();
};
register_shutdown_function($releaseLock);

$applied = Migrator::run($pdo, APP_ROOT . '/migrations');
if ($applied !== []) {
    echo 'Applied test migrations: ' . implode(', ', $applied) . "\n";
}

require APP_ROOT . '/tests/helpers.php';

// ---------------------------------------------------------------------------
// Run every tests/*_test.php file.
// ---------------------------------------------------------------------------
$filter = $argv[1] ?? null;
$files = glob(APP_ROOT . '/tests/*_test.php') ?: [];
sort($files);

$passed = 0;
$failed = 0;
$failures = [];
$startedAt = microtime(true);

foreach ($files as $file) {
    $group = basename($file, '_test.php');
    /** @var array<string, callable> $tests */
    $tests = require $file;

    $groupFailed = 0;
    foreach ($tests as $name => $test) {
        if ($filter !== null && !str_contains($group . ' ' . $name, $filter)) {
            continue;
        }

        reset_database();
        $testStartedAt = microtime(true);

        try {
            $test();
            $passed++;
            fprintf(STDOUT, "  \u{2713} %s · %s (%dms)\n", $group, $name, (microtime(true) - $testStartedAt) * 1000);
        } catch (Throwable $error) {
            $failed++;
            $groupFailed++;
            $failures[] = ['group' => $group, 'name' => $name, 'error' => $error];
            fprintf(STDOUT, "  \u{2717} %s · %s\n      %s\n", $group, $name, str_replace("\n", "\n      ", $error->getMessage()));
        }
    }

    if ($groupFailed === 0) {
        fprintf(STDOUT, "%s\n", $group);
    }
}

$duration = microtime(true) - $startedAt;

fprintf(
    STDOUT,
    "\n%d passed, %d failed in %.2fs\n",
    $passed,
    $failed,
    $duration,
);

if ($failed > 0) {
    fprintf(STDOUT, "\nFailures:\n");
    foreach ($failures as $failure) {
        fprintf(STDOUT, " - %s · %s: %s\n", $failure['group'], $failure['name'], $failure['error']->getMessage());
    }
    exit(1);
}

exit(0);
