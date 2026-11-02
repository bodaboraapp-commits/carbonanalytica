<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit;
use App\AppError;
use App\Config;
use App\Db;

/**
 * Import the committed deployment dump (`database/pdd_generator_deploy.sql`)
 * from the staff console — a browser alternative to phpMyAdmin.
 *
 * Safety:
 *  - staff-only (callers must pass the platform-admin id);
 *  - refuses when the database already holds data unless `replace` is explicit;
 *  - dry run parses and counts statements without touching the database;
 *  - the path is fixed (no user-supplied filesystem paths);
 *  - every real import is audited.
 */
final class DumpImportService
{
    public const DUMP_RELATIVE_PATH = 'database/pdd_generator_deploy.sql';

    /** @return array<string, mixed> */
    public static function inspect(string $userId): array
    {
        TaskCenterService::assertStaff($userId);
        return self::inspectData();
    }

    /**
     * Unauthenticated read of the same facts — used by the token-gated deploy
     * URL (`/deploy/import?token=...`), which must work before any account exists.
     *
     * @return array<string, mixed>
     */
    public static function inspectData(): array
    {
        $path = APP_ROOT . '/' . self::DUMP_RELATIVE_PATH;
        $exists = is_file($path);
        $size = $exists ? (int) filesize($path) : 0;
        $modified = $exists ? (int) filemtime($path) : null;
        $hash = $exists ? hash_file('sha256', $path) : null;
        $sql = $exists ? (string) file_get_contents($path) : '';

        $statements = $sql !== '' ? self::splitStatements($sql) : [];
        preg_match_all('/CREATE TABLE/i', $sql, $tableMatches);
        preg_match_all('/INSERT INTO `users`/i', $sql, $userMatches);

        $pdo = Db::pdo();
        $tableCount = (int) ($pdo->query(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()',
        )->fetchColumn() ?: 0);
        $userCount = self::safeCount('users');
        $teamCount = self::safeCount('teams');
        $projectCount = self::safeCount('projects');
        $draftCount = self::safeCount('drafts');

        // Empty install (no accounts, or just the one console account created
        // for this session) means nothing meaningful is lost by importing.
        $safe = $projectCount === 0
            && $draftCount === 0
            && $teamCount <= 1
            && $userCount <= 1;

        return [
            'path' => $path,
            'relative_path' => self::DUMP_RELATIVE_PATH,
            'exists' => $exists,
            'size' => $size,
            'modified' => $modified,
            'sha256' => $hash,
            'statements' => count($statements),
            'dump_tables' => count($tableMatches[0]),
            'dump_user_inserts' => count($userMatches[0]),
            'database' => (string) Config::get('db.name'),
            'db_tables' => $tableCount,
            'db_users' => $userCount,
            'db_teams' => $teamCount,
            'db_projects' => $projectCount,
            'db_drafts' => $draftCount,
            'safe_to_import' => $safe,
        ];
    }

    /**
     * @return array{statements: int, tables: int, backed_up: bool}
     */
    public static function import(string $userId, bool $replaceExisting, bool $dryRun): array
    {
        TaskCenterService::assertStaff($userId);

        return self::importCore($userId, $replaceExisting, $dryRun);
    }

    /**
     * Import via the one-click deploy URL: authorisation comes from the
     * `DEPLOY_TOKEN` comparison in the controller, not from a session.
     *
     * @return array{statements: int, tables: int, backed_up: bool}
     */
    public static function importAsDeployToken(bool $replaceExisting, bool $dryRun): array
    {
        return self::importCore(null, $replaceExisting, $dryRun);
    }

    /**
     * @return array{statements: int, tables: int, backed_up: bool}
     */
    private static function importCore(?string $actorUserId, bool $replaceExisting, bool $dryRun): array
    {
        $path = APP_ROOT . '/' . self::DUMP_RELATIVE_PATH;
        if (!is_file($path)) {
            throw AppError::notFound('Deployment dump');
        }

        $sql = (string) file_get_contents($path);
        if (trim($sql) === '') {
            throw new \RuntimeException('Deployment dump is empty.');
        }

        $state = self::inspectData();
        if (!$state['safe_to_import'] && !$replaceExisting && !$dryRun) {
            throw AppError::conflict('The database already contains data. Confirm replacement to import the dump.');
        }

        $statements = self::splitStatements($sql);
        $tableCount = 0;
        foreach ($statements as $statement) {
            if (stripos($statement, 'CREATE TABLE') !== false) {
                $tableCount++;
            }
        }

        if ($dryRun) {
            return ['statements' => count($statements), 'tables' => $tableCount, 'backed_up' => false];
        }

        $pdo = Db::pdo();
        $executed = 0;
        foreach ($statements as $index => $statement) {
            try {
                $pdo->exec($statement);
                $executed++;
            } catch (\PDOException $error) {
                error_log(sprintf(
                    '[pdd] dump import failed at statement %d/%d: %s (sql head: %s)',
                    $index + 1,
                    count($statements),
                    $error->getMessage(),
                    mb_substr($statement, 0, 160),
                ));
                throw new \RuntimeException('Dump import failed at statement ' . ($index + 1) . '.', 0, $error);
            }
        }

        // The dump rebuilds `users`, so a staff actor may no longer exist
        // afterwards; keep the id in metadata and store a null actor then.
        $metadata = [
            'file' => self::DUMP_RELATIVE_PATH,
            'statements' => $executed,
            'replaced' => !$state['safe_to_import'],
            'actor_user_id' => $actorUserId,
        ];
        if ($actorUserId !== null) {
            $stillExists = Db::selectOne('SELECT id FROM users WHERE id = ?', [$actorUserId]);
            if ($stillExists === null) {
                $actorUserId = null;
            }
        }

        Audit::record([
            'actor_user_id' => $actorUserId,
            'action' => $actorUserId === null ? 'deploy.dump_imported' : 'admin.database.dump_imported',
            'target_type' => 'database',
            'target_id' => (string) Config::get('db.name'),
            'metadata' => $metadata,
        ]);

        return ['statements' => $executed, 'tables' => $tableCount, 'backed_up' => !$state['safe_to_import']];
    }

    /**
     * Quote-aware splitter for a mysqldump file: semicolons inside strings and
     * comments do not end a statement.
     *
     * @return list<string>
     */
    private static function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $single = false;
        $double = false;
        $backtick = false;
        $lineComment = false;
        $blockComment = false;
        $escape = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($lineComment) {
                if ($char === "\n") {
                    $lineComment = false;
                }
                if ($char !== "\r") {
                    $buffer .= $char;
                }
                continue;
            }

            if ($blockComment) {
                if ($char === '*' && $next === '/') {
                    $blockComment = false;
                    $buffer .= '*/';
                    $i++;
                    continue;
                }
                $buffer .= $char;
                continue;
            }

            if ($single || $double) {
                $buffer .= $char;
                if ($escape) {
                    $escape = false;
                } elseif ($char === '\\') {
                    $escape = true;
                } elseif (($single && $char === "'") || ($double && $char === '"')) {
                    $single = false;
                    $double = false;
                }
                continue;
            }

            if ($backtick) {
                $buffer .= $char;
                if ($char === '`') {
                    $backtick = false;
                }
                continue;
            }

            if ($char === '-' && $next === '-') {
                $lineComment = true;
                $buffer .= '--';
                $i++;
                continue;
            }

            if ($char === '/' && $next === '*') {
                $blockComment = true;
                $buffer .= '/*';
                $i++;
                continue;
            }

            if ($char === "'") {
                $single = true;
                $buffer .= $char;
                continue;
            }

            if ($char === '"') {
                $double = true;
                $buffer .= $char;
                continue;
            }

            if ($char === '`') {
                $backtick = true;
                $buffer .= $char;
                continue;
            }

            if ($char === ';') {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $statement = trim($buffer);
        if ($statement !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }

    private static function safeCount(string $table): int
    {
        try {
            $exists = Db::selectOne(
                "SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?",
                [$table],
            );
            if ((int) ($exists['total'] ?? 0) === 0) {
                return 0;
            }

            return (int) (Db::selectOne('SELECT COUNT(*) AS total FROM ' . $table)['total'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }
}
