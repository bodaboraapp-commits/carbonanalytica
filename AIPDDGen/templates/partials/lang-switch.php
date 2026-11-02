<?php

declare(strict_types=1);

/**
 * Language switcher: two plain links, so it works without JavaScript and is
 * reachable by keyboard.
 *
 * @var string $next
 * @var string $locale
 */
?>
<nav class="lang-switch" aria-label="<?= e(t('nav.language')) ?>">
    <?php foreach (\App\I18n::available() as $available): ?>
        <a
            href="<?= e(url('/lang/' . $available) . '?next=' . rawurlencode($next)) ?>"
            hreflang="<?= e($available) ?>"
            lang="<?= e($available) ?>"
            <?= $locale === $available ? 'aria-current="true"' : '' ?>
        ><?= e(strtoupper($available)) ?></a>
    <?php endforeach; ?>
</nav>
