<?php

declare(strict_types=1);

/** @var array<string, string> $errors field => error code */
?>
<section class="auth-shell">
    <div class="auth-card">
        <h1><?= e(t('auth.sign_in_title')) ?></h1>
        <p class="auth-card__sub"><?= e(t('auth.sign_in_sub')) ?></p>

        <?php if ($errors !== []): ?>
            <div class="alert alert--error" role="alert"><?= e(t('error.validation_error')) ?></div>
        <?php endif; ?>

        <form class="form" method="post" action="<?= e(url('/login')) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="<?= e($next) ?>">

            <div class="field" data-invalid="<?= isset($errors['email']) ? 'true' : 'false' ?>">
                <label for="email"><?= e(t('auth.email_label')) ?></label>
                <input
                    id="email"
                    name="email"
                    type="email"
                    value="<?= e(old('email', $email)) ?>"
                    autocomplete="email"
                    inputmode="email"
                    required
                    <?= isset($errors['email']) ? 'aria-invalid="true" aria-describedby="email-error"' : '' ?>
                >
                <?php if (isset($errors['email'])): ?>
                    <span class="field__error" id="email-error"><?= e(form_error('email', $errors)) ?></span>
                <?php endif; ?>
            </div>

            <div class="field" data-invalid="<?= isset($errors['password']) ? 'true' : 'false' ?>">
                <label for="password"><?= e(t('auth.password_label')) ?></label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    required
                    <?= isset($errors['password']) ? 'aria-invalid="true" aria-describedby="password-error"' : '' ?>
                >
                <?php if (isset($errors['password'])): ?>
                    <span class="field__error" id="password-error"><?= e(form_error('password', $errors)) ?></span>
                <?php endif; ?>
            </div>

            <div class="auth-links">
                <a href="<?= e(url('/forgot-password')) ?>"><?= e(t('auth.forgot_password')) ?></a>
            </div>

            <div class="form__actions">
                <button class="btn btn--block" type="submit"><?= e(t('auth.sign_in_button')) ?></button>
            </div>
        </form>

        <?php if ($showGoogle): ?>
            <div class="auth-divider"><?= e(t('auth.or')) ?></div>
            <a class="btn btn--secondary btn--block" href="<?= e(url('/auth/google')) ?>">
                <?= e(t('auth.google_button')) ?>
            </a>
        <?php endif; ?>

        <p class="auth-alt">
            <?= e(t('auth.no_account')) ?>
            <a href="<?= e(url('/signup') . ($next !== '/dashboard' ? '?next=' . rawurlencode($next) : '')) ?>"><?= e(t('auth.create_one')) ?></a>
        </p>
    </div>
</section>
