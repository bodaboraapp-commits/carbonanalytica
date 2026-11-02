<?php

declare(strict_types=1);

namespace App;

/**
 * Fixed-window rate limiting stored in MySQL, so limits survive restarts and
 * work if the app is served by more than one process (e.g. Apache workers).
 */
final class RateLimit
{
    /** @return array{allowed: bool, remaining: int, retry_after: int} */
    public static function consume(string $key, int $limit, int $windowSeconds): array
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $windowSeconds);

        Db::execute(
            'INSERT INTO rate_limit_counters (counter_key, window_start, count)
             VALUES (?, NOW(3), 1)
             ON DUPLICATE KEY UPDATE
                count = IF(window_start <= ?, 1, count + 1),
                window_start = IF(window_start <= ?, NOW(3), window_start)',
            [$key, $cutoff, $cutoff],
        );

        $row = Db::selectOne('SELECT count, window_start FROM rate_limit_counters WHERE counter_key = ?', [$key]);
        $count = (int) ($row['count'] ?? 1);
        $windowStart = isset($row['window_start']) ? strtotime((string) $row['window_start']) : time();
        $retryAfter = max(1, ($windowStart ?: time()) + $windowSeconds - time());

        return [
            'allowed' => $count <= $limit,
            'remaining' => max(0, $limit - $count),
            'retry_after' => $retryAfter,
        ];
    }

    /** Clear a counter after a successful attempt (e.g. correct password). */
    public static function reset(string $key): void
    {
        Db::execute('DELETE FROM rate_limit_counters WHERE counter_key = ?', [$key]);
    }

    public static function purgeOlderThan(int $seconds = 86_400): void
    {
        Db::execute(
            'DELETE FROM rate_limit_counters WHERE window_start < ?',
            [gmdate('Y-m-d H:i:s', time() - $seconds)],
        );
    }
}
