<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Thin PDO wrapper: one connection, prepared statements only, nested-safe
 * transactions via savepoints.
 *
 * All SQL in the application goes through this class or the repositories, and
 * every tenant-scoped query filters on `team_id` (see Repositories\*).
 */
final class Db
{
    private static ?PDO $pdo = null;
    private static int $transactionDepth = 0;

    /** Connect to the configured database, optionally without selecting one. */
    public static function connect(bool $withDatabase = true): PDO
    {
        $host = (string) Config::get('db.host');
        $port = (int) Config::get('db.port');
        $charset = (string) Config::get('db.charset');
        $dsn = "mysql:host={$host};port={$port};charset={$charset}";
        if ($withDatabase) {
            $dsn = "mysql:host={$host};port={$port};dbname=" . Config::get('db.name') . ";charset={$charset}";
        }

        $pdo = new PDO($dsn, (string) Config::get('db.user'), (string) Config::get('db.pass'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        // The application works in UTC. Pin the session time zone so NOW() in
        // SQL and gmdate() in PHP always mean the same instant.
        $pdo->exec("SET time_zone = '+00:00'");

        return $pdo;
    }

    public static function pdo(): PDO
    {
        return self::$pdo ??= self::connect();
    }

    /** @param array<string|int, mixed> $params */
    public static function select(string $sql, array $params = []): array
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    /** @param array<string|int, mixed> $params */
    public static function selectOne(string $sql, array $params = []): ?array
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string|int, mixed> $params */
    public static function execute(string $sql, array $params = []): int
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute($params);
        return $statement->rowCount();
    }

    /** @param array<string, mixed> $data */
    public static function insert(string $table, array $data): void
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`, `', $columns),
            implode(', ', $placeholders),
        );
        $params = [];
        foreach ($data as $column => $value) {
            $params[':' . $column] = self::normalize($value);
        }
        self::execute($sql, $params);
    }

    /**
     * Run a callback inside a transaction. Nested calls use savepoints, so
     * services can compose without breaking atomicity.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::pdo();
        $savepoint = null;

        if (self::$transactionDepth === 0) {
            $pdo->beginTransaction();
        } else {
            $savepoint = 'sp_' . self::$transactionDepth;
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }
        self::$transactionDepth++;

        try {
            $result = $callback();
            self::$transactionDepth--;
            if ($savepoint !== null) {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            } else {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $error) {
            self::$transactionDepth--;
            if ($savepoint !== null) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** Reset the connection (used by the test runner between suites). */
    public static function reset(): void
    {
        self::$pdo = null;
        self::$transactionDepth = 0;
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $value;
    }

    /** Human-readable database error for logs (never shown to users). */
    public static function describeError(PDOException $error): string
    {
        return $error->getMessage();
    }

    public static function assertConnected(): void
    {
        if (self::$pdo === null) {
            throw new RuntimeException('Database is not connected.');
        }
    }
}
