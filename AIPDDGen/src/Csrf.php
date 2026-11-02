<?php

declare(strict_types=1);

namespace App;

/**
 * Cross-site request forgery protection: a per-session token submitted with
 * every state-changing form and compared in constant time.
 *
 * Combined with SameSite=Lax session cookies this closes the classic CSRF hole
 * without any JavaScript.
 */
final class Csrf
{
    public static function token(): string
    {
        if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function validate(?string $token): void
    {
        $expected = $_SESSION['_csrf'] ?? null;
        if (!is_string($expected) || !is_string($token) || !hash_equals($expected, $token)) {
            throw new AppError('invalid_csrf', 419, 'Your form expired. Please try again.');
        }
    }
}
