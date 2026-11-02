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
        self::clearPending();
        Session::set('user_id', (string) $user['id']);
        Session::set('session_version', (int) $user['session_version']);
        Session::set('locale', (string) $user['locale']);
        self::$loaded = false;
        self::$cachedUser = null;
    }

    /**
     * The half-authenticated state between a correct password (or a new account)
     * and the emailed one-time code. It is deliberately not a session: no user id
     * is stored, so every signed-in route stays closed until the code is checked.
     *
     * @param array<string, mixed> $pending
     */
    public static function setPending(array $pending): void
    {
        Session::set('_pending_auth', $pending + ['issued_at' => time()]);
    }

    /** @return array<string, mixed>|null */
    public static function pending(): ?array
    {
        $pending = Session::get('_pending_auth');
        if (!is_array($pending) || !isset($pending['user_id'], $pending['purpose'])) {
            return null;
        }

        $expiresAt = (int) ($pending['expires_at'] ?? 0);
        if ($expiresAt > 0 && $expiresAt <= time()) {
            self::clearPending();
            return null;
        }

        return $pending;
    }

    public static function clearPending(): void
    {
        Session::forget('_pending_auth');
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
