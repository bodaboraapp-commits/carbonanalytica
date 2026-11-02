<?php

declare(strict_types=1);

/**
 * Seed the application database.
 *
 * Usage: php bin/seed.php
 *
 * Creates (idempotently) the provisional methodology list, the Gold Standard
 * v1.4 template structure, a demo workspace and the staff console account.
 * The same code runs from the staff console button at /admin/database.
 */
require __DIR__ . '/../src/bootstrap.php';

use App\Services\SeedService;

$result = SeedService::run(getenv('SEED_DEMO') !== 'false');

foreach ($result['lines'] as $line) {
    echo $line . "\n";
}

echo "  URL:      " . absolute_url('/login') . "\n";
echo "  Email:    {$result['demo']['email']}\n";
echo "  Password: {$result['demo']['password']}\n";
echo "  Staff:    {$result['admin']['email']} / {$result['admin']['password']}\n";
