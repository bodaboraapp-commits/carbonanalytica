<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Sequential SQL migrations.
 *
 * A row in `schema_migrations` records every applied file, so `bin/setup.php`
 * can be re-run safely and new steps just add numbered files.
 */
final class Migrator
{
    public static function ensureTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(120) NOT NULL PRIMARY KEY,
                applied_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return list<string> versions applied during this run */
    public static function run(PDO $pdo, string $directory): array
    {
        self::ensureTable($pdo);

        $applied = array_column($pdo->query('SELECT version FROM schema_migrations')->fetchAll(), 'version');
        $files = glob(rtrim($directory, '/\\') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $newlyApplied = [];

        foreach ($files as $file) {
            $version = basename($file);
            if (in_array($version, $applied, true)) {
                continue;
            }

            $sql = (string) file_get_contents($file);
            foreach (self::splitStatements($sql) as $statement) {
                $pdo->exec($statement);
            }

            $insert = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
            $insert->execute([$version]);
            $newlyApplied[] = $version;
        }

        return $newlyApplied;
    }

    /** @return list<string> */
    private static function splitStatements(string $sql): array
    {
        // Strip -- comments (our migrations have no strings containing them).
        $lines = preg_split('/\r?\n/', $sql) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '--')) {
                continue;
            }
            $clean[] = $line;
        }

        $statements = [];
        foreach (preg_split('/;\s*(?:\r?\n|$)/', implode("\n", $clean)) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }
}
