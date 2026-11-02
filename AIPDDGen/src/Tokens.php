<?php

declare(strict_types=1);

namespace App;

/**
 * Opaque token helpers for sessions, email verification, password reset and
 * invitations. Tokens are generated with the CSPRNG and stored only as SHA-256
 * hashes, so database contents cannot be replayed.
 */
final class Tokens
{
    public static function generate(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function equals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }
}
