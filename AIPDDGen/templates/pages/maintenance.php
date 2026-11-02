<?php

declare(strict_types=1);

/**
 * Maintenance page shown to non-staff visitors while the Super Admin
 * maintenance control is on (HTTP 503, no app layout).
 */

use App\Http;
?>
<!DOCTYPE html>
<html lang="<?= e(\App\I18n::locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(t('maintenance.title')) ?> Â· <?= e(t('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('motion.css')) ?>">
</head>
<body class="deploy-import">
<main class="deploy-import__wrap">
    <section class="card">
        <h1><?= e(t('maintenance.title')) ?></h1>
        <p class="muted"><?= e(t('maintenance.body')) ?></p>
        <p class="mt-2 mb-0">
            <a class="btn btn--secondary btn--sm" href="<?= e(url('/login')) ?>"><?= e(t('maintenance.staff')) ?></a>
            <a class="btn btn--ghost btn--sm" href="<?= e(Http::path()) ?>"><?= e(t('maintenance.retry')) ?></a>
        </p>
    </section>
</main>
</body>
</html>
