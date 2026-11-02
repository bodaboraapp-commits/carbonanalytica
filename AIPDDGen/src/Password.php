<?php

declare(strict_types=1);

namespace App;

/**
 * Password hashing on top of PHP's built-in password API (Argon2id when the
 * build supports it, otherwise bcrypt). The algorithm is stored inside the hash
 * so hashes can be upgraded transparently on the next sign-in.
 */
final class Password
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 200;

    public static function algorithm(): string | int
    {
        $available = password_algos();
        if (in_array(PASSWORD_ARGON2ID, $available, true)) {
            return PASSWORD_ARGON2ID;
        }
        if (in_array(PASSWORD_ARGON2I, $available, true)) {
            return PASSWORD_ARGON2I;
        }
        return PASSWORD_BCRYPT;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, self::algorithm());
    }

    public static function verify(string $password, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            return false;
        }
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm());
    }
}
