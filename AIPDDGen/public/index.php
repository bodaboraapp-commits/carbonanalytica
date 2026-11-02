<?php

declare(strict_types=1);

/**
 * Front controller. Every request enters here; the router decides what runs.
 */
require dirname(__DIR__) . '/src/bootstrap.php';

use App\AppError;
use App\Config;
use App\Csrf;
use App\I18n;
use App\Http;
use App\Router;
use App\View;

// Security headers that cost nothing and close common browser-side holes.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header(
    "Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; "
    . "style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'"
);

try {
    // A body larger than post_max_size arrives with empty $_POST and $_FILES.
    // Report it as an oversized upload instead of a confusing CSRF failure.
    if (Http::isPost() && $_POST === [] && $_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        renderError(413, 'upload_too_large', I18n::t('error.upload_too_large'));
    }

    // Every state-changing request carries a CSRF token (defence in depth on top
    // of the SameSite=Lax session cookie).
    if (Http::isPost()) {
        $token = $_POST['_csrf'] ?? null;
        Csrf::validate(is_string($token) ? $token : null);
    }

    /** @var Router $router */
    $router = require APP_ROOT . '/src/routes.php';
    $router->dispatch(Http::method(), Http::path());
} catch (AppError $error) {
    renderError($error->status, $error->errorCode, $error->getMessage());
} catch (Throwable $error) {
    error_log('[pdd] ' . $error->getMessage() . "\n" . $error->getTraceAsString());
    renderError(500, 'internal_error', 'Something went wrong.');
}

/**
 * Render an error as JSON for API clients or as a page for people.
 */
function renderError(int $status, string $code, string $message): never
{
    if (Http::wantsJson()) {
        Http::json(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    http_response_code($status);

    $title = $status === 404
        ? I18n::t('error.not_found_title')
        : I18n::t('error.page_title');

    View::render('pages/error', [
        'status' => $status,
        'code' => $code,
        'message' => $status >= 500 ? I18n::t('error.internal_error') : $message,
        'title' => $title,
    ]);
}
