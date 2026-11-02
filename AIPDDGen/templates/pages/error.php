<?php

declare(strict_types=1);

/**
 * @var int $status
 * @var string $code
 * @var string $message
 * @var string $title
 */
?>
<section class="error-page">
    <p class="error-page__code" aria-hidden="true"><?= e((string) $status) ?></p>
    <h1><?= e($title) ?></h1>
    <p><?= e($message) ?></p>
    <p class="mt-3">
        <a class="btn" href="<?= e(url('/dashboard')) ?>"><?= e(t('nav.projects')) ?></a>
        <a class="btn btn--secondary" href="<?= e(url('/')) ?>"><?= e(t('common.back_home')) ?></a>
    </p>
</section>
