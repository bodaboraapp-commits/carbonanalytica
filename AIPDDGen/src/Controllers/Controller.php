<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AppError;
use App\Auth;
use App\Http;
use App\I18n;
use App\Session;
use App\Tenancy;

/**
 * Shared controller helpers: authentication, the current workspace and the
 * "remember the form, redirect back with messages" pattern used by every form.
 */
abstract class Controller
{
    /** @return array<string, mixed> */
    protected function requireUser(): array
    {
        $user = Auth::user();
        if ($user === null) {
            Http::redirect('/login?next=' . rawurlencode(Http::path()));
        }
        return $user;
    }

    /** @return array<string, mixed> the workspace for this request */
    protected function currentTeam(array $user): array
    {
        return Tenancy::currentTeam((string) $user['id'], Http::queryString('team'));
    }

    /** Remember what the user typed so the form can be repopulated. */
    protected function rememberInput(array $input): void
    {
        Session::old($input);
    }

    /** Redirect back to a form with a translated error and field errors. */
    protected function backWithError(AppError $error, string $backPath): never
    {
        if ($error->fields !== []) {
            Session::errors($error->fields);
        }
        $message = $error->errorCode === 'rate_limited'
            ? t('error.rate_limited')
            : I18n::error($error->errorCode);
        Session::flash('error', $message);
        Http::redirect($backPath);
    }

    protected function flash(string $type, string $message): void
    {
        Session::flash($type, $message);
    }

    /** Only allow same-app relative redirects (prevents open redirects). */
    protected function safeNext(?string $next, string $fallback = '/dashboard'): string
    {
        if (!is_string($next) || $next === '') {
            return $fallback;
        }
        if (!str_starts_with($next, '/') || str_starts_with($next, '//') || str_contains($next, '\\')) {
            return $fallback;
        }
        return $next;
    }

    protected function hasRole(string $role, string $minimum): bool
    {
        return Tenancy::roleAtLeast($role, $minimum);
    }
}
