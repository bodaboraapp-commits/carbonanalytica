<?php

declare(strict_types=1);

namespace App;

/**
 * HTTP request helpers and responses. Framework-free: plain superglobals in,
 * plain headers out, so every endpoint is easy to reason about.
 */
final class Http
{
    private static ?string $basePath = null;

    /** URL prefix when the app is hosted in a subdirectory (e.g. /xampp/htdocs/.../public). */
    public static function basePath(): string
    {
        if (self::$basePath !== null) {
            return self::$basePath;
        }
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $directory = rtrim(str_replace('\\', '/', dirname($script)), '/');
        self::$basePath = ($directory === '.' || $directory === '/') ? '' : $directory;
        return self::$basePath;
    }

    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    /** Request path with the subdirectory prefix removed, e.g. "/projects/abc". */
    public static function path(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $base = self::basePath();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $path = '/' . ltrim(rawurldecode($path), '/');
        return $path === '' ? '/' : $path;
    }

    /** @return array<string, mixed> */
    public static function query(): array
    {
        return $_GET;
    }

    public static function queryString(string $key): ?string
    {
        $value = $_GET[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    /** POST field, falling back to the query string. */
    public static function input(string $key, mixed $default = null): mixed
    {
        $value = $_POST[$key] ?? $_GET[$key] ?? $default;
        return is_string($value) ? trim($value) : $value;
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        $input = array_merge($_GET, $_POST);
        return array_map(static fn (mixed $value): mixed => is_string($value) ? trim($value) : $value, $input);
    }

    public static function ip(): string
    {
        $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return $remote !== '' ? $remote : 'unknown';
    }

    public static function userAgent(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    }

    public static function wantsJson(): bool
    {
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        $path = self::path();
        return str_contains($accept, 'application/json')
            || str_starts_with($path, '/api/')
            || (string) ($_SERVER['CONTENT_TYPE'] ?? '') === 'application/json';
    }

    /** 303 redirect (POST → GET), relative paths are prefixed with the base path. */
    public static function redirect(string $location, int $status = 303): never
    {
        if (!preg_match('#^https?://#i', $location)) {
            $location = self::basePath() . '/' . ltrim($location, '/');
        }
        header('Location: ' . $location, true, $status);
        exit;
    }

    /** @param array<string, string> $headers */
    public static function json(mixed $payload, int $status = 200, array $headers = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** @param array<string, string> $headers */
    public static function html(string $body, int $status = 200, array $headers = []): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $body;
        exit;
    }

    public static function previousPath(): string
    {
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($referer === '') {
            return '/';
        }
        $refererPath = parse_url($referer, PHP_URL_PATH) ?: '/';
        $base = self::basePath();
        if ($base !== '' && str_starts_with($refererPath, $base)) {
            $refererPath = substr($refererPath, strlen($base));
        }
        return '/' . ltrim($refererPath, '/');
    }
}
