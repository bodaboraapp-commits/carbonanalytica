<?php

declare(strict_types=1);

/**
 * Staff console layout: top bar (search, public portal, alerts, language,
 * greeting, sign-out) and a left side-panel menu grouping the console pages.
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

$isActive = static fn (string $path): bool => $adminPath === $path || str_starts_with($adminPath, $path . '/');
$navLink = static function (string $href, string $label, bool $active, int $badge = 0) use ($adminBadge): void {
    ?>
    <a class="admin-nav-link" href="<?= e(url($href)) ?>" <?= $active ? 'aria-current="page"' : '' ?>>
        <span><?= e($label) ?></span>
        <?php if ($badge > 0): ?>
            <span class="admin-nav-link__badge" aria-label="<?= e(t('console.menu_alerts_badge', ['count' => $badge])) ?>"><?= e((string) $badge) ?></span>
        <?php endif; ?>
    </a>
    <?php
};
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
    <link rel="stylesheet" href="<?= e(asset('motion.css')) ?>">
</head>
<body class="admin-body">
<a class="skip-link" href="#main"><?= e(t('app.skip_to_content')) ?></a>

<header class="admin-topbar">
    <div class="admin-topbar__inner">
        <label class="admin-nav-toggle" for="admin-nav-toggle">
            <span aria-hidden="true">&#9776;</span>
            <?= e(t('console.menu_toggle')) ?>
        </label>

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
</header>

<input type="checkbox" id="admin-nav-toggle" class="admin-nav-toggle-input" autocomplete="off">

<div class="admin-shell">
    <aside class="admin-sidebar" aria-label="<?= e(t('console.menu_label')) ?>">
        <nav class="admin-sidebar__nav">
            <div class="admin-nav-group">
                <p class="admin-nav-group__title"><?= e(t('console.menu_overview')) ?></p>
                <?php $navLink('/admin', t('console.nav_overview'), $adminPath === '/admin'); ?>
            </div>

            <div class="admin-nav-group">
                <p class="admin-nav-group__title"><?= e(t('console.menu_operations')) ?></p>
                <?php $navLink('/admin/tasks', t('console.nav_job_queue'), $isActive('/admin/tasks'), $adminBadge); ?>
                <?php $navLink('/admin/workspaces', t('console.nav_workspaces'), $isActive('/admin/workspaces')); ?>
                <?php $navLink('/admin/kyc', t('console.nav_kyc'), $isActive('/admin/kyc')); ?>
                <?php $navLink('/admin/users', t('console.nav_users'), $isActive('/admin/users')); ?>
            </div>

            <div class="admin-nav-group">
                <p class="admin-nav-group__title"><?= e(t('console.menu_content')) ?></p>
                <?php $navLink('/admin/templates', t('console.nav_templates'), $isActive('/admin/templates')); ?>
                <?php $navLink('/admin/audit', t('console.nav_audit'), $isActive('/admin/audit')); ?>
            </div>

            <div class="admin-nav-group">
                <p class="admin-nav-group__title"><?= e(t('console.menu_system')) ?></p>
                <?php $navLink('/admin/database', t('console.nav_database'), $isActive('/admin/database')); ?>
            </div>
        </nav>

        <div class="admin-sidebar__foot">
            <span><?= e(t('console.menu_signed_in')) ?></span>
            <strong><?= e((string) ($adminUser['name'] ?? '')) ?></strong>
            <span><?= e((string) ($adminUser['email'] ?? '')) ?></span>
            <div class="admin-sidebar__links">
                <a href="<?= e(url('/dashboard')) ?>"><?= e(t('admin.my_workspace')) ?></a>
                <a href="<?= e(url('/')) ?>"><?= e(t('admin.public_portal')) ?></a>
            </div>
        </div>
    </aside>

    <main id="main" class="admin-main">
        <div class="admin-container">
            <?php \App\View::partial('partials/flash', ['flashes' => $adminFlashes]); ?>
            <?= $content ?>
        </div>
    </main>
</div>

<footer class="admin-footer">
    <div class="admin-container admin-footer__inner">
        <span><?= e(t('app.name')) ?> · <?= e(t('admin.console_label')) ?></span>
        <span><?= e(t('landing.footer_note')) ?></span>
    </div>
</footer>
<script src="<?= e(asset('app.js')) ?>" defer></script>
</body>
</html>
