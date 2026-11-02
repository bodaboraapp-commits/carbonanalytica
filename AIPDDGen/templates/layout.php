<?php

declare(strict_types=1);

/**
 * Shared page layout. Renders the guest or signed-in navigation, flash
 * messages and the footer around $content.
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
$onboarding = $siteUser !== null && $siteUser['email_verified_at'] === null;

if (PHP_SAPI !== 'cli') {
    /* Resource timing is not needed; keep the page free of blocking requests. */
}
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
                <a class="nav__link" href="<?= e(url('/dashboard')) ?>" <?= is_current('/dashboard') || str_starts_with($sitePath, '/projects') ? 'aria-current="page"' : '' ?>>
                    <?= e(t('nav.projects')) ?>
                </a>
                <a class="nav__link" href="<?= e(url('/team')) ?>" <?= is_current('/team') ? 'aria-current="page"' : '' ?>>
                    <?= e(t('nav.team')) ?>
                </a>
                <a class="nav__link" href="<?= e(url('/onboarding')) ?>" <?= is_current('/onboarding') ? 'aria-current="page"' : '' ?>>
                    <?= e(t('nav.organization')) ?>
                </a>
                <?php if ((int) ($siteUser['is_platform_admin'] ?? 0) === 1): ?>
                    <a class="nav__link nav__link--admin" href="<?= e(url('/admin')) ?>" <?= is_current('/admin') ? 'aria-current="page"' : '' ?>>
                        <?= e(t('nav.admin_console')) ?>
                    </a>
                <?php endif; ?>
                <span class="nav__divider" aria-hidden="true"></span>
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

<main id="main" class="site-main">
    <div class="container">
        <?php \App\View::partial('partials/flash', ['flashes' => $flashes]); ?>

        <?php if ($onboarding && $sitePath !== '/verify-email'): ?>
            <div class="notice" role="status">
                <?= e(t('auth.banner_unverified')) ?>
                <form class="inline-form" method="post" action="<?= e(url('/verify-email/resend')) ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn--ghost btn--sm" type="submit"><?= e(t('auth.resend_link')) ?></button>
                </form>
            </div>
        <?php endif; ?>

        <?= $content ?>
    </div>
</main>

<footer class="site-footer">
    <div class="container site-footer__inner">
        <span><?= e(t('app.name')) ?> · <?= e(t('landing.footer_note')) ?></span>
        <nav class="site-footer__nav" aria-label="<?= e(t('app.name')) ?>">
            <?php if ($siteUser !== null): ?>
                <a href="<?= e(url('/dashboard')) ?>"><?= e(t('nav.projects')) ?></a>
                <a href="<?= e(url('/team')) ?>"><?= e(t('nav.team')) ?></a>
                <a href="<?= e(url('/onboarding')) ?>"><?= e(t('nav.organization')) ?></a>
            <?php else: ?>
                <a href="<?= e(url('/login')) ?>"><?= e(t('nav.sign_in')) ?></a>
                <a href="<?= e(url('/signup')) ?>"><?= e(t('nav.sign_up')) ?></a>
            <?php endif; ?>
            <span class="site-footer__lang"><?= e(t('landing.languages')) ?></span>
        </nav>
    </div>
</footer>
</body>
</html>
