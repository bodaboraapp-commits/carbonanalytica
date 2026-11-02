<?php

declare(strict_types=1);

/**
 * Staff console layout: top bar with search, public-portal link, alerts,
 * greeting and the signed-in user's menu — mirroring the operational console
 * the product owner asked for.
 *
 * @var string $content
 * @var int $taskBadge
 * @var string $title
 */

use App\Auth;
use App\Http;
use App\I18n;
use App\Session;

$adminUser = Auth::user();
$adminLocale = I18n::locale();
$adminPath = Http::path();
$adminQuery = (string) ($_SERVER['QUERY_STRING'] ?? '');
$adminNext = $adminPath . ($adminQuery !== '' ? '?' . $adminQuery : '');
$adminTitle = isset($title) && $title !== '' ? $title . ' · ' . t('admin.console_label') : t('admin.console_label');
$adminFlashes = Session::takeFlashes();
$adminFirstName = $adminUser !== null ? explode(' ', trim((string) $adminUser['name']))[0] : '';
$adminBadge = isset($taskBadge) ? (int) $taskBadge : 0;
?>
<!doctype html>
<html lang="<?= e($adminLocale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($adminTitle) ?></title>
    <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body class="admin-body">
<a class="skip-link" href="#main"><?= e(t('app.skip_to_content')) ?></a>

<header class="admin-topbar">
    <div class="admin-topbar__inner">
        <a class="admin-brand" href="<?= e(url('/admin')) ?>">
            <span class="brand__mark" aria-hidden="true">P</span>
            <span class="admin-brand__text">
                <strong><?= e(t('app.name')) ?></strong>
                <small><?= e(t('admin.console_label')) ?></small>
            </span>
        </a>

        <form class="admin-search" method="get" action="<?= e(url('/admin/tasks')) ?>" role="search">
            <label class="visually-hidden" for="admin-search"><?= e(t('admin.search_label')) ?></label>
            <input
                id="admin-search"
                type="search"
                name="q"
                value="<?= e((string) (Http::queryString('q') ?? '')) ?>"
                placeholder="<?= e(t('admin.search_placeholder')) ?>"
            >
            <button class="btn btn--secondary btn--sm" type="submit"><?= e(t('admin.search_button')) ?></button>
        </form>

        <nav class="admin-actions" aria-label="<?= e(t('admin.console_label')) ?>">
            <a class="admin-actions__link" href="<?= e(url('/')) ?>"><?= e(t('admin.public_portal')) ?></a>
            <?php \App\View::partial('partials/lang-switch', ['next' => $adminNext, 'locale' => $adminLocale]); ?>

            <a class="admin-alert" href="<?= e(url('/admin/tasks') . '?scope=breached') ?>">
                <span class="visually-hidden"><?= e(t('admin.alerts')) ?></span>
                <span aria-hidden="true">&#9873;</span>
                <?php if ($adminBadge > 0): ?>
                    <span class="admin-alert__badge"><?= e((string) $adminBadge) ?></span>
                <?php endif; ?>
            </a>

            <span class="admin-greeting"><?= e(t('admin.hello', ['name' => $adminFirstName])) ?></span>

            <form class="inline-form" method="post" action="<?= e(url('/logout')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn--ghost btn--sm" type="submit"><?= e(t('nav.sign_out')) ?></button>
            </form>
        </nav>
    </div>

    <nav class="admin-tabs" aria-label="<?= e(t('admin.console_label')) ?>">
        <a class="admin-tab" href="<?= e(url('/admin')) ?>" <?= $adminPath === '/admin' ? 'aria-current="page"' : '' ?>>
            <?= e(t('admin.action_center')) ?>
        </a>
        <a class="admin-tab" href="<?= e(url('/admin/tasks')) ?>" <?= str_starts_with($adminPath, '/admin/tasks') ? 'aria-current="page"' : '' ?>>
            <?= e(t('admin.job_queue')) ?>
        </a>
        <span class="admin-tab admin-tab--pending" aria-disabled="true"><?= e(t('admin.template_library_pending')) ?></span>
        <a class="admin-tab" href="<?= e(url('/dashboard')) ?>"><?= e(t('admin.my_workspace')) ?></a>
    </nav>
</header>

<main id="main" class="admin-main">
    <div class="admin-container">
        <?php \App\View::partial('partials/flash', ['flashes' => $adminFlashes]); ?>
        <?= $content ?>
    </div>
</main>

<footer class="admin-footer">
    <div class="admin-container admin-footer__inner">
        <span><?= e(t('app.name')) ?> · <?= e(t('admin.console_label')) ?></span>
        <span><?= e(t('landing.footer_note')) ?></span>
    </div>
</footer>
</body>
</html>
