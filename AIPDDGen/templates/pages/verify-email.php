<?php

declare(strict_types=1);

/** @var array<string, mixed>|null $user */
?>
<section class="auth-shell">
    <div class="auth-card">
        <h1><?= e(t('auth.verify_title')) ?></h1>
        <p class="auth-card__sub"><?= e(t('auth.verify_sub')) ?></p>

        <?php if ($user !== null && $user['email_verified_at'] !== null): ?>
            <div class="alert alert--success" role="status"><?= e(t('flash.already_verified')) ?></div>
        <?php elseif ($user !== null): ?>
            <form class="form" method="post" action="<?= e(url('/verify-email/resend')) ?>">
                <?= csrf_field() ?>
                <div class="form__actions">
                    <button class="btn btn--block" type="submit"><?= e(t('auth.resend_link')) ?></button>
                </div>
            </form>
        <?php endif; ?>

        <p class="auth-alt">
            <a href="<?= e(url($user !== null ? '/dashboard' : '/login')) ?>">
                <?= e($user !== null ? t('nav.projects') : t('auth.back_to_sign_in')) ?>
            </a>
        </p>
    </div>
</section>
