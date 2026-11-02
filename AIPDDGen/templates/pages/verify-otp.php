<?php

declare(strict_types=1);

/**
 * One-time-code screen: shared by sign-up email verification and the sign-in
 * second factor. No session exists at this point, only the pending challenge.
 *
 * @var array<string, mixed> $pending
 * @var string $maskedEmail
 * @var int $minutes
 * @var array<string, string> $errors
 */

$delivered = !empty($pending['delivered']);
$deliveryError = (string) ($pending['delivery_error'] ?? '');
$purpose = (string) ($pending['purpose'] ?? 'signin');
$titleKey = $purpose === 'signup' ? 'auth.verify_otp_title_signup' : 'auth.verify_otp_title';
?>
<section class="auth-shell">
    <div class="auth-card">
        <h1><?= e(t($titleKey)) ?></h1>
        <p class="auth-card__sub">
            <?= e(t('auth.verify_otp_sub', ['email' => $maskedEmail, 'minutes' => $minutes])) ?>
        </p>

        <?php if (!$delivered): ?>
            <div class="alert alert--warning" role="status">
                <div>
                    <?= e(t('auth.otp_delivery_warning')) ?>
                    <?php if ($deliveryError !== ''): ?>
                        <p class="small mb-0"><?= e($deliveryError) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="alert alert--error" role="alert"><?= e(t('error.validation_error')) ?></div>
        <?php endif; ?>

        <form class="form" method="post" action="<?= e(url('/verify-otp')) ?>" novalidate>
            <?= csrf_field() ?>

            <?php $codeInvalid = isset($errors['code']); ?>
            <fieldset
                class="field otp-field"
                data-invalid="<?= $codeInvalid ? 'true' : 'false' ?>"
                <?= $codeInvalid ? 'aria-describedby="otp-code-hint otp-code-error"' : 'aria-describedby="otp-code-hint"' ?>
            >
                <legend><?= e(t('auth.otp_code_label')) ?></legend>
                <div class="otp-boxes" data-otp-boxes>
                    <?php for ($digit = 1; $digit <= 6; $digit++): ?>
                        <input
                            class="otp-box"
                            id="otp-digit-<?= e((string) $digit) ?>"
                            name="code[]"
                            type="text"
                            inputmode="numeric"
                            pattern="[0-9]"
                            maxlength="1"
                            required
                            <?= $digit === 1 ? 'autocomplete="one-time-code" autofocus' : 'autocomplete="off"' ?>
                            aria-label="<?= e(t('auth.otp_digit_label', ['n' => $digit])) ?>"
                            <?= $digit === 1 && $codeInvalid ? 'aria-invalid="true"' : '' ?>
                        >
                    <?php endfor; ?>
                </div>
                <span class="hint" id="otp-code-hint"><?= e(t('auth.otp_code_hint')) ?></span>
                <?php if ($codeInvalid): ?>
                    <span class="field__error" id="otp-code-error"><?= e(form_error('code', $errors)) ?></span>
                <?php endif; ?>
            </fieldset>

            <div class="form__actions">
                <button class="btn btn--block" type="submit"><?= e(t('auth.verify_otp_button')) ?></button>
            </div>
        </form>

        <form class="form form--resend" method="post" action="<?= e(url('/verify-otp/resend')) ?>">
            <?= csrf_field() ?>
            <div class="form__actions">
                <button class="btn btn--secondary btn--block" type="submit"><?= e(t('auth.otp_resend_button')) ?></button>
            </div>
        </form>

        <div class="auth-alt">
            <?= e(t('auth.otp_wrong_account')) ?>
            <form class="inline-form" method="post" action="<?= e(url('/logout')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn--ghost btn--sm" type="submit"><?= e(t('auth.otp_start_over')) ?></button>
            </form>
        </div>
    </div>
</section>

<?php /* Progressive enhancement: auto-advance, paste and arrow keys across the six boxes. */ ?>
<script src="<?= e(asset('otp.js')) ?>" defer></script>
