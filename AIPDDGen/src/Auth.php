<?php

declare(strict_types=1);

namespace App;

/**
 * Authentication state helpers backed by PHP's native session.
 *
 * Revocation: each user row has a `session_version`. The session stores the
 * version it signed in with; a mismatch (password reset, "sign out everywhere")
 * invalidates the session on the next request even though PHP sessions cannot be
 * deleted server-side.
 */
final class Auth
{
    private static ?array $cachedUser = null;

    private static bool $loaded = false;

    /** The signed-in user row, or null. */
    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$cachedUser;
        }
        self::$loaded = true;
        self::$cachedUser = null;

        $userId = Session::get('user_id');
        $sessionVersion = Session::get('session_version');
        if (!is_string($userId) || $userId === '') {
            return null;
        }

        $user = Db::selectOne(
            'SELECT * FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1',
            [$userId],
        );

        if ($user === null || (int) $user['session_version'] !== (int) $sessionVersion) {
            self::clearSession();
            return null;
        }

        self::$cachedUser = $user;
        return $user;
    }

    public static function id(): ?string
    {
        $user = self::user();
        return $user === null ? null : (string) $user['id'];
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** Establish a session for a user who has just proven who they are. */
    public static function login(array $user): void
    {
        Session::regenerate();
        Session::set('user_id', (string) $user['id']);
        Session::set('session_version', (int) $user['session_version']);
        Session::set('locale', (string) $user['locale']);
        self::$loaded = false;
        self::$cachedUser = null;
    }

    public static function logout(): void
    {
        self::clearSession();
        Session::destroy();
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => $params['path'],
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => (bool) $params['secure'],
            ]);
        }
        self::$loaded = true;
        self::$cachedUser = null;
    }

    /** Require a signed-in user or redirect to the sign-in page and come back. */
    public static function requireUser(?string $next = null): array
    {
        $user = self::user();
        if ($user !== null) {
            return $user;
        }

        $target = $next ?? Http::path();
        Http::redirect('/login?next=' . rawurlencode($target));
    }

    /** Forget the cached row after an in-request update. */
    public static function refresh(): void
    {
        self::$loaded = false;
        self::$cachedUser = null;
    }

    private static function clearSession(): void
    {
        Session::forget('user_id');
        Session::forget('session_version');
    }
}
