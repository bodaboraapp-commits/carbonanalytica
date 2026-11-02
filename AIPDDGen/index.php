<?php

declare(strict_types=1);

/**
 * Subdirectory deployment shim.
 *
 * When the project root itself is the web directory (for example
 * https://example.com/AIPDDGen/), Apache routes requests here. Keeping the
 * front controller at public/index.php means the app's internals stay outside
 * the served paths; `SCRIPT_NAME` remains /AIPDDGen/index.php, so
 * Http::basePath() derives the correct prefix for links, redirects and assets.
 *
 * See docs/deployment.md ("Subdirectory hosting").
 */
require __DIR__ . '/public/index.php';
