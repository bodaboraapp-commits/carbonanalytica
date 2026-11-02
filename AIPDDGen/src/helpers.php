<?php

declare(strict_types=1);

/**
 * Global template helpers. Kept deliberately small: escaping, translation,
 * URLs, CSRF and form repopulation are the only things templates need.
 */

use App\Config;
use App\Csrf;
use App\Http;
use App\I18n;
use App\Session;

if (!function_exists('e')) {
    /** Escape for HTML text and attribute contexts. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('t')) {
    /** @param array<string, string|int|float> $vars */
    function t(string $key, array $vars = []): string
    {
        return I18n::t($key, $vars);
    }
}

if (!function_exists('locale')) {
    function locale(): string
    {
        return I18n::locale();
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('url')) {
    /** Application-relative URL, honouring a subdirectory install. */
    function url(string $path = '/'): string
    {
        return Http::basePath() . '/' . ltrim($path, '/');
    }
}

if (!function_exists('absolute_url')) {
    function absolute_url(string $path = '/'): string
    {
        return rtrim((string) config('app.url'), '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    /** Cache-busted asset URL (file modification time). */
    function asset(string $path): string
    {
        $file = APP_ROOT . '/public/assets/' . ltrim($path, '/');
        $version = is_readable($file) ? (string) filemtime($file) : '1';
        return url('/assets/' . ltrim($path, '/')) . '?v=' . $version;
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (!function_exists('old')) {
    /** One-time repopulation of a form field after a validation error. */
    function old(string $key, string $default = ''): string
    {
        $old = Session::get('_old', []);
        $value = is_array($old) ? ($old[$key] ?? $default) : $default;
        return is_string($value) ? $value : $default;
    }
}

if (!function_exists('form_error')) {
    /** Field-level error message from the last failed submission. */
    function form_error(string $field, array $errors): ?string
    {
        $code = $errors[$field] ?? null;
        if (!is_string($code)) {
            return null;
        }
        return match ($code) {
            'required' => t('validation.required'),
            'invalid_email' => t('error.invalid_email'),
            'too_short' => t('validation.too_short'),
            'too_long' => t('validation.too_long'),
            'invalid_choice' => t('validation.invalid_choice'),
            'mismatch' => t('validation.mismatch'),
            'must_differ' => t('validation.must_differ'),
            'invalid_year' => t('validation.invalid_year'),
            'invalid_url' => t('validation.invalid_url'),
            'invalid_phone' => t('validation.invalid_phone'),
            'invalid_code' => t('validation.invalid_code'),
            default => t('error.validation_error'),
        };
    }
}

if (!function_exists('is_current')) {
    /** Active-state helper for navigation links. */
    function is_current(string $path): bool
    {
        return Http::path() === $path || str_starts_with(Http::path(), rtrim($path, '/') . '/');
    }
}

if (!function_exists('status_key')) {
    /** Translation key for a project status. */
    function status_key(string $status): string
    {
        return match ($status) {
            'setup' => 'dashboard.status_setup',
            'complete', 'archived' => 'dashboard.status_complete',
            default => 'dashboard.status_in_progress',
        };
    }
}

if (!function_exists('role_key')) {
    function role_key(string $role): string
    {
        return match ($role) {
            'owner' => 'team.role_owner',
            'admin' => 'team.role_admin',
            'editor' => 'team.role_editor',
            default => 'team.role_viewer',
        };
    }
}

if (!function_exists('mask_email')) {
    /** Partially hide an address for screens that mention it ("a***@example.com"). */
    function mask_email(string $email): string
    {
        $email = trim($email);
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return $email;
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at);
        $visible = mb_substr($local, 0, 1);

        return $visible . str_repeat('*', max(2, mb_strlen($local) - 1)) . $domain;
    }
}

if (!function_exists('format_date')) {
    /** Dates are rendered DD/MM/YYYY (registry template rule). */
    function format_date(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? '' : date('d/m/Y', $timestamp);
    }
}

if (!function_exists('redirect_to')) {
    function redirect_to(string $path, int $status = 303): never
    {
        Http::redirect($path, $status);
    }
}
