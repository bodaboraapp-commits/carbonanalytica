<?php

declare(strict_types=1);

/** @var string $name */
/** @var string $email */
/** @var string $next */
/** @var array<string, string> $errors */
/** @var bool $showGoogle */
?>
<section class="auth-shell">
    <div class="auth-card">
        <h1><?= e(t('auth.sign_up_title')) ?></h1>
        <p class="auth-card__sub"><?= e(t('auth.sign_up_sub')) ?></p>

        <?php if ($errors !== []): ?>
            <div class="alert alert--error" role="alert"><?= e(t('error.validation_error')) ?></div>
        <?php endif; ?>

        <form class="form" method="post" action="<?= e(url('/signup')) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="<?= e($next) ?>">

            <div class="field" data-invalid="<?= isset($errors['name']) ? 'true' : 'false' ?>">
                <label for="name"><?= e(t('auth.name_label')) ?></label>
                <input
                    id="name"
                    name="name"
                    type="text"
                    value="<?= e(old('name', $name)) ?>"
                    autocomplete="name"
                    required
                    <?= isset($errors['name']) ? 'aria-invalid="true" aria-describedby="name-error"' : '' ?>
                >
                <?php if (isset($errors['name'])): ?>
                    <span class="field__error" id="name-error"><?= e(form_error('name', $errors)) ?></span>
                <?php endif; ?>
            </div>

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
                <button class="btn btn--block" type="submit"><?= e(t('auth.sign_up_button')) ?></button>
            </div>
            <p class="form__meta"><?= e(t('auth.terms_note')) ?></p>
        </form>

        <?php if ($showGoogle): ?>
            <div class="auth-divider"><?= e(t('auth.or')) ?></div>
            <a class="btn btn--secondary btn--block" href="<?= e(url('/auth/google')) ?>">
                <?= e(t('auth.google_button')) ?>
            </a>
        <?php endif; ?>

        <p class="auth-alt">
            <?= e(t('auth.have_account')) ?>
            <a href="<?= e(url('/login')) ?>"><?= e(t('auth.sign_in_link')) ?></a>
        </p>
    </div>
</section>
