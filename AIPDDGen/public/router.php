<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in development server:
 *   php -S 127.0.0.1:8000 -t public public/router.php
 *
 * Existing files (CSS, images) are served as-is; everything else goes through
 * the front controller.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
