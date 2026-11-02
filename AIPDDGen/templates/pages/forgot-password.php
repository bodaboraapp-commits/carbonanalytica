<?php

declare(strict_types=1);

/** @var array<string, string> $errors */
?>
<section class="auth-shell">
    <div class="auth-card">
        <h1><?= e(t('auth.forgot_title')) ?></h1>
        <p class="auth-card__sub"><?= e(t('auth.forgot_sub')) ?></p>

        <?php if ($errors !== []): ?>
            <div class="alert alert--error" role="alert"><?= e(t('error.validation_error')) ?></div>
        <?php endif; ?>

        <form class="form" method="post" action="<?= e(url('/forgot-password')) ?>" novalidate>
            <?= csrf_field() ?>

            <div class="field" data-invalid="<?= isset($errors['email']) ? 'true' : 'false' ?>">
                <label for="email"><?= e(t('auth.email_label')) ?></label>
                <input
                    id="email"
                    name="email"
                    type="email"
                    value="<?= e(old('email')) ?>"
                    autocomplete="email"
                    inputmode="email"
                    required
                    <?= isset($errors['email']) ? 'aria-invalid="true" aria-describedby="email-error"' : '' ?>
                >
                <?php if (isset($errors['email'])): ?>
                    <span class="field__error" id="email-error"><?= e(form_error('email', $errors)) ?></span>
                <?php endif; ?>
            </div>

            <div class="form__actions">
                <button class="btn btn--block" type="submit"><?= e(t('auth.forgot_button')) ?></button>
            </div>
        </form>

        <p class="auth-alt">
            <a href="<?= e(url('/login')) ?>"><?= e(t('auth.back_to_sign_in')) ?></a>
        </p>
    </div>
</section>
