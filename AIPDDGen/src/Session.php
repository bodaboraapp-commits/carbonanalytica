<?php

declare(strict_types=1);

namespace App;

/**
 * Session helpers: values, flash messages and one-time old form input.
 *
 * PHP's native file-backed sessions keep the app free of a session service; the
 * user's `session_version` (checked on every request) provides server-side
 * revocation for password resets and "sign out everywhere".
 */
final class Session
{
    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function regenerate(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        if (PHP_SAPI === 'cli') {
            $_SESSION = [];
            return;
        }
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /** Queue a flash message: type is success | error | info. */
    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type: string, message: string}> */
    public static function takeFlashes(): array
    {
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return is_array($flashes) ? $flashes : [];
    }

    /** Remember validated input for the next page so forms stay filled. */
    public static function old(array $input): void
    {
        $_SESSION['_old'] = $input;
    }

    public static function errors(array $errors): void
    {
        $_SESSION['_errors'] = $errors;
    }

    /** @return array<string, string> */
    public static function takeErrors(): array
    {
        $errors = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_errors']);
        return is_array($errors) ? $errors : [];
    }
}
