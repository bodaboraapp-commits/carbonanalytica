<?php

declare(strict_types=1);

/** @var string $token */
/** @var array<string, string> $errors */
?>
<section class="auth-shell">
    <div class="auth-card">
        <h1><?= e(t('auth.reset_title')) ?></h1>
        <p class="auth-card__sub"><?= e(t('auth.reset_sub')) ?></p>

        <?php if ($errors !== []): ?>
            <div class="alert alert--error" role="alert"><?= e(t('error.validation_error')) ?></div>
        <?php endif; ?>

        <form class="form" method="post" action="<?= e(url('/reset-password')) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">

            <div class="field" data-invalid="<?= isset($errors['password']) ? 'true' : 'false' ?>">
                <label for="password"><?= e(t('auth.password_label')) ?></label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="new-password"
                    minlength="12"
                    required
                    aria-describedby="password-hint<?= isset($errors['password']) ? ' password-error' : '' ?>"
                    <?= isset($errors['password']) ? 'aria-invalid="true"' : '' ?>
                >
                <span class="hint" id="password-hint"><?= e(t('auth.password_hint')) ?></span>
                <?php if (isset($errors['password'])): ?>
                    <span class="field__error" id="password-error"><?= e(form_error('password', $errors)) ?></span>
                <?php endif; ?>
            </div>

            <div class="form__actions">
                <button class="btn btn--block" type="submit"><?= e(t('auth.reset_button')) ?></button>
            </div>
        </form>

        <p class="auth-alt">
            <a href="<?= e(url('/login')) ?>"><?= e(t('auth.back_to_sign_in')) ?></a>
        </p>
    </div>
</section>
