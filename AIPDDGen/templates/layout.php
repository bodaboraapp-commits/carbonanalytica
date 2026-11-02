<?php

declare(strict_types=1);

/**
 * Shared page layout. Renders guest or signed-in chrome around $content.
 *
 * Signed-in users get a workspace side panel (Projects / Team / Organization /
 * Action Center) next to the main column; the panel collapses behind a CSS-only
 * drawer on narrow screens. Guest pages keep the simple top navigation. Flash
 * messages and the footer are shared.
 *
 * Variables: $content (set by View), $title (optional).
 */

use App\Auth;
use App\Http;
use App\I18n;
use App\Session;

$siteUser = Auth::user();
$sitePath = Http::path();
$siteQuery = (string) ($_SERVER['QUERY_STRING'] ?? '');
$siteNext = $sitePath . ($siteQuery !== '' ? '?' . $siteQuery : '');
$siteLocale = I18n::locale();
$siteTitle = isset($title) && $title !== '' ? $title . ' · ' . t('app.name') : t('app.name') . ' — ' . t('app.tagline');
$flashes = Session::takeFlashes();
$siteIsStaff = $siteUser !== null && (int) ($siteUser['is_platform_admin'] ?? 0) === 1;
?>
<!doctype html>
<html lang="<?= e($siteLocale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#0f5132">
    <meta name="description" content="<?= e(t('app.tagline')) ?>">
    <title><?= e($siteTitle) ?></title>
    <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('motion.css')) ?>">
    <?php if ($siteUser !== null): ?>
        <link rel="stylesheet" href="<?= e(asset('tenant-nav.css')) ?>">
    <?php endif; ?>
</head>
<body>
<a class="skip-link" href="#main"><?= e(t('app.skip_to_content')) ?></a>

<header class="site-header">
    <div class="container site-header__inner">
        <a class="brand" href="<?= e(url($siteUser !== null ? '/dashboard' : '/')) ?>">
            <span class="brand__mark" aria-hidden="true">P</span>
            <span class="brand__text">
                <?= e(t('app.name')) ?>
                <small><?= e(t('common.language_name')) ?></small>
            </span>
        </a>

        <nav class="nav" aria-label="<?= e(t('nav.language')) ?>">
            <?php if ($siteUser !== null): ?>
                <?php \App\View::partial('partials/lang-switch', ['next' => $siteNext, 'locale' => $siteLocale]); ?>
                <form class="inline-form" method="post" action="<?= e(url('/logout')) ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn--ghost btn--sm" type="submit"><?= e(t('nav.sign_out')) ?></button>
                </form>
            <?php else: ?>
                <?php \App\View::partial('partials/lang-switch', ['next' => $siteNext, 'locale' => $siteLocale]); ?>
                <a class="nav__link" href="<?= e(url('/login')) ?>"><?= e(t('nav.sign_in')) ?></a>
                <a class="btn btn--sm" href="<?= e(url('/signup')) ?>"><?= e(t('nav.sign_up')) ?></a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<?php if ($siteUser !== null): ?>
    <div class="container tenant-shell">
        <aside class="tenant-sidebar">
            <input type="checkbox" id="tenant-nav-toggle" class="tenant-nav-toggle-input" aria-controls="tenant-nav">
            <label for="tenant-nav-toggle" class="tenant-nav-toggle">
                <span class="tenant-nav-toggle__icon" aria-hidden="true">&#9776;</span>
                <span><?= e(t('nav.menu')) ?></span>
            </label>
            <nav class="tenant-nav" id="tenant-nav" aria-label="<?= e(t('nav.workspace_menu')) ?>">
                <a class="tenant-nav__link" href="<?= e(url('/dashboard')) ?>" <?= is_current('/dashboard') || str_starts_with($sitePath, '/projects') ? 'aria-current="page"' : '' ?>>
                    <?= e(t('nav.projects')) ?>
                </a>
                <a class="tenant-nav__link" href="<?= e(url('/team')) ?>" <?= is_current('/team') ? 'aria-current="page"' : '' ?>>
                    <?= e(t('nav.team')) ?>
                </a>
                <a class="tenant-nav__link" href="<?= e(url('/onboarding')) ?>" <?= is_current('/onboarding') ? 'aria-current="page"' : '' ?>>
                    <?= e(t('nav.organization')) ?>
                </a>
                <?php if ($siteIsStaff): ?>
                    <a class="tenant-nav__link tenant-nav__link--admin" href="<?= e(url('/admin')) ?>" <?= is_current('/admin') ? 'aria-current="page"' : '' ?>>
                        <?= e(t('nav.admin_console')) ?>
                    </a>
                <?php endif; ?>
            </nav>
        </aside>

        <main id="main" class="site-main tenant-main">
            <?php \App\View::partial('partials/flash', ['flashes' => $flashes]); ?>

            <?= $content ?>
        </main>
    </div>
<?php else: ?>
    <main id="main" class="site-main">
        <div class="container">
            <?php \App\View::partial('partials/flash', ['flashes' => $flashes]); ?>

            <?= $content ?>
        </div>
    </main>
<?php endif; ?>

<footer class="site-footer">
    <div class="container site-footer__inner">
        <span><?= e(t('app.name')) ?> · <?= e(t('landing.footer_note')) ?></span>
        <nav class="site-footer__nav" aria-label="<?= e(t('app.name')) ?>">
            <?php if ($siteUser === null): ?>
                <a href="<?= e(url('/login')) ?>"><?= e(t('nav.sign_in')) ?></a>
                <a href="<?= e(url('/signup')) ?>"><?= e(t('nav.sign_up')) ?></a>
            <?php endif; ?>
            <span class="site-footer__lang"><?= e(t('landing.languages')) ?></span>
        </nav>
    </div>
</footer>
<script src="<?= e(asset('app.js')) ?>" defer></script>
</body>
</html>
