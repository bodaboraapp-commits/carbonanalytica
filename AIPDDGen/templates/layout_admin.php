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

/** Inline line icons for the console side panel (decorative: rendered aria-hidden). */
$navIcon = static function (string $name): string {
    $stroke = 'fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"';
    $paths = [
        'overview' => 'M3 3h7v9H3zM3 16h7v5H3zM14 3h7v5h-7zM14 12h7v9h-7z',
        'queue' => 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2M9 12h6M9 16h4',
        'workspaces' => 'M3 21h18M5 21V7l7-4 7 4v14M9.5 21v-5.5h5V21',
        'kyc' => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10zM9 12l2 2 4-4',
        'users' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
        'templates' => 'M12 2 2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5',
        'audit' => 'M3 3v5h5M3.05 13A9 9 0 1 0 6 5.3L3 8M12 7v5l4 2',
        'system' => 'M22 12h-4l-3 9L9 3l-3 9H2',
        'ai' => 'M12 3l1.6 4.4L18 9l-4.4 1.6L12 15l-1.6-4.4L6 9l4.4-1.6L12 3zM19 14l.8 2.2L22 17l-2.2.8L19 20l-.8-2.2L16 17l2.2-.8L19 14z',
        'database' => 'M12 2C8 2 4 3 4 6s4 4 8 4 8-1 8-4-4-4-8-4zM4 6v6c0 3 4 4 8 4s8-1 8-4V6M4 12v6c0 3 4 4 8 4s8-1 8-4v-6',
        'workspace' => 'M3 10.5 12 3l9 7.5M5 9.5V21h14V9.5M9 21v-6h6v6',
        'external' => 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zM2 12h20M12 2c2.5 3 2.5 17 0 20M12 2c-2.5 3-2.5 17 0 20',
        'logout' => 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9',
    ];
    $path = $paths[$name] ?? '';

    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' . $path . '" ' . $stroke . '/></svg>';
};

$navLink = static function (string $icon, string $href, string $label, bool $active, int $badge = 0) use ($navIcon): void {
    ?>
    <a class="admin-nav-link" href="<?= e(url($href)) ?>" <?= $active ? 'aria-current="page"' : '' ?>>
        <span class="admin-nav-link__icon" aria-hidden="true"><?= $navIcon($icon) ?></span>
        <span class="admin-nav-link__label"><?= e($label) ?></span>
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
                <?php $navLink('overview', '/admin', t('console.nav_overview'), $adminPath === '/admin'); ?>
            </div>

            <div class="admin-nav-group">
                <p class="admin-nav-group__title"><?= e(t('console.menu_operations')) ?></p>
                <?php $navLink('queue', '/admin/tasks', t('console.nav_job_queue'), $isActive('/admin/tasks'), $adminBadge); ?>
                <?php $navLink('workspaces', '/admin/workspaces', t('console.nav_workspaces'), $isActive('/admin/workspaces')); ?>
                <?php $navLink('kyc', '/admin/kyc', t('console.nav_kyc'), $isActive('/admin/kyc')); ?>
                <?php $navLink('users', '/admin/users', t('console.nav_users'), $isActive('/admin/users')); ?>
            </div>

            <div class="admin-nav-group">
                <p class="admin-nav-group__title"><?= e(t('console.menu_content')) ?></p>
                <?php $navLink('templates', '/admin/templates', t('console.nav_templates'), $isActive('/admin/templates')); ?>
            </div>

            <div class="admin-nav-group">
                <p class="admin-nav-group__title"><?= e(t('console.menu_system')) ?></p>
                <?php $navLink('system', '/admin/system', t('console.nav_system_health'), $isActive('/admin/system')); ?>
                <?php $navLink('ai', '/admin/ai', t('console.nav_ai_engine'), $isActive('/admin/ai')); ?>
                <?php $navLink('audit', '/admin/audit', t('console.nav_audit'), $isActive('/admin/audit')); ?>
                <?php $navLink('database', '/admin/database', t('console.nav_database'), $isActive('/admin/database')); ?>
            </div>
        </nav>

        <div class="admin-sidebar__foot">
            <div class="admin-sidebar__user">
                <span class="admin-sidebar__user-label"><?= e(t('console.menu_signed_in')) ?></span>
                <strong><?= e((string) ($adminUser['name'] ?? '')) ?></strong>
                <span class="admin-sidebar__user-email"><?= e((string) ($adminUser['email'] ?? '')) ?></span>
            </div>
            <div class="admin-sidebar__links">
                <a class="admin-sidebar__link" href="<?= e(url('/dashboard')) ?>">
                    <span class="admin-sidebar__link-icon" aria-hidden="true"><?= $navIcon('workspace') ?></span>
                    <span><?= e(t('admin.my_workspace')) ?></span>
                </a>
                <a class="admin-sidebar__link" href="<?= e(url('/')) ?>">
                    <span class="admin-sidebar__link-icon" aria-hidden="true"><?= $navIcon('external') ?></span>
                    <span><?= e(t('admin.public_portal')) ?></span>
                </a>
                <form class="admin-sidebar__signout" method="post" action="<?= e(url('/logout')) ?>">
                    <?= csrf_field() ?>
                    <button class="admin-sidebar__link admin-sidebar__link--danger" type="submit">
                        <span class="admin-sidebar__link-icon" aria-hidden="true"><?= $navIcon('logout') ?></span>
                        <span><?= e(t('nav.sign_out')) ?></span>
                    </button>
                </form>
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
