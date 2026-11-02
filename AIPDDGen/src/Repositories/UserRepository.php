<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Db;
use App\Uuid;

/**
 * User rows. Authentication logic lives in Services\AuthService; this class only
 * reads and writes data.
 */
final class UserRepository
{
    public static function findById(string $id): ?array
    {
        return Db::selectOne('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$id]);
    }

    public static function findByEmail(string $email): ?array
    {
        return Db::selectOne(
            'SELECT * FROM users WHERE email_lower = ? AND deleted_at IS NULL LIMIT 1',
            [mb_strtolower(trim($email))],
        );
    }

    /** @param array<string, mixed> $data */
    public static function create(array $data): array
    {
        $id = Uuid::v4();
        Db::insert('users', [
            'id' => $id,
            'email' => $data['email'],
            'password_hash' => $data['password_hash'] ?? null,
            'name' => $data['name'],
            'image_url' => $data['image_url'] ?? null,
            'locale' => $data['locale'] ?? 'en',
            'email_verified_at' => $data['email_verified_at'] ?? null,
            'default_team_id' => $data['default_team_id'] ?? null,
        ]);

        $user = self::findById($id);
        if ($user === null) {
            throw new \RuntimeException('Failed to create user');
        }
        return $user;
    }

    /** @param array<string, mixed> $fields */
    public static function update(string $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $assignments = implode(', ', array_map(static fn (string $column): string => "`{$column}` = :{$column}", array_keys($fields)));
        $statement = Db::pdo()->prepare("UPDATE users SET {$assignments} WHERE id = :id");
        $statement->bindValue(':id', $id);
        foreach ($fields as $column => $value) {
            $statement->bindValue(':' . $column, is_array($value) ? json_encode($value) : $value);
        }
        $statement->execute();
    }

    public static function markEmailVerified(string $id): void
    {
        Db::execute(
            'UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW(3)) WHERE id = ?',
            [$id],
        );
    }

    public static function touchLastSeen(string $id): void
    {
        Db::execute('UPDATE users SET last_seen_at = NOW(3) WHERE id = ?', [$id]);
    }

    /** Invalidate every existing session for this user. */
    public static function bumpSessionVersion(string $id): void
    {
        Db::execute('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$id]);
    }

    public static function setDefaultTeam(string $id, string $teamId): void
    {
        Db::execute('UPDATE users SET default_team_id = ? WHERE id = ?', [$teamId, $id]);
    }
}
