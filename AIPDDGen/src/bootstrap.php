<?php

declare(strict_types=1);

/**
 * Bootstrap: configuration, PSR-4 autoloading, sessions and locale.
 *
 * Deliberately small and dependency-free: no framework, no Composer runtime
 * packages. This is the whole application wiring.
 */

const APP_ROOT = __DIR__ . '/..';

// ---------------------------------------------------------------------------
// Environment (.env.local then .env), without a dotenv dependency.
// ---------------------------------------------------------------------------
foreach ([APP_ROOT . '/.env.local', APP_ROOT . '/.env'] as $envFile) {
    if (!is_readable($envFile)) {
        continue;
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
}

// ---------------------------------------------------------------------------
// Autoloading: App\Foo\Bar => src/Foo/Bar.php
// ---------------------------------------------------------------------------
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path = APP_ROOT . '/src/' . $relative . '.php';
    if (is_readable($path)) {
        require $path;
    }
});

// ---------------------------------------------------------------------------
// Configuration and helpers.
// ---------------------------------------------------------------------------
\App\Config::load(require APP_ROOT . '/config/config.php');
require APP_ROOT . '/src/helpers.php';

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

// ---------------------------------------------------------------------------
// Error handling: log everything, never leak internals to the page.
// ---------------------------------------------------------------------------
$debug = (bool) \App\Config::get('app.debug');
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

$logDir = APP_ROOT . '/' . \App\Config::get('storage.logs');
if (!is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}
ini_set('error_log', $logDir . '/php-error.log');

// ---------------------------------------------------------------------------
// Sessions (files stored inside the project, not the machine temp folder).
// ---------------------------------------------------------------------------
if (PHP_SAPI !== 'cli') {
    $sessionDir = APP_ROOT . '/' . \App\Config::get('storage.sessions');
    if (!is_dir($sessionDir)) {
        mkdir($sessionDir, 0775, true);
    }
    session_save_path($sessionDir);
    session_name((string) \App\Config::get('security.session_cookie'));
    session_set_cookie_params([
        'lifetime' => (int) \App\Config::get('auth.session_ttl'),
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (bool) \App\Config::get('security.secure_cookies'),
    ]);
    if (\App\Config::get('app.env') === 'testing') {
        // Keep test runs deterministic: new session id per request.
        ini_set('session.use_strict_mode', '1');
    }
    session_start();

    // Refresh the session cookie lifetime on activity (sliding expiry).
    setcookie(session_name(), session_id(), [
        'expires' => time() + (int) \App\Config::get('auth.session_ttl'),
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (bool) \App\Config::get('security.secure_cookies'),
    ]);
}

// ---------------------------------------------------------------------------
// Locale: explicit ?lang > cookie > signed-in user > default.
// ---------------------------------------------------------------------------
\App\I18n::boot();
