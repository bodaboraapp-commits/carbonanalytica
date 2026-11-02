<?php

declare(strict_types=1);

/**
 * Step 2: the primary contact for verification.
 *
 * @var array<string, mixed>|null $profile
 * @var array<string, string> $errors
 * @var bool $canEdit
 */

$value = static function (string $key) use ($profile): string {
    return old($key, (string) ($profile[$key] ?? ''));
};
$invalid = static fn (string $key): string => isset($errors[$key]) ? 'true' : 'false';
$errorFor = static function (string $key) use ($errors): void {
    if (isset($errors[$key])) {
        echo '<span class="field__error" id="' . e($key) . '-error">' . e(form_error($key, $errors)) . '</span>';
    }
};
$aria = static fn (string $key): string => isset($errors[$key])
    ? 'aria-invalid="true" aria-describedby="' . e($key) . '-error"'
    : '';
?>
<div class="onboarding-panel__head">
    <div>
        <p class="onboarding-panel__eyebrow"><?= e(t('org.step2_of_4')) ?></p>
        <h2><?= e(t('org.step2_title')) ?></h2>
        <p class="muted"><?= e(t('org.step2_body')) ?></p>
    </div>
</div>

<?php if (!$canEdit): ?>
    <dl class="review-grid">
        <div class="review-item"><dt><?= e(t('org.field_contact_name')) ?></dt><dd><?= e((string) ($profile['contact_name'] ?? t('project.not_set'))) ?></dd></div>
        <div class="review-item"><dt><?= e(t('org.field_contact_role')) ?></dt><dd><?= e((string) ($profile['contact_role'] ?? t('project.not_set'))) ?></dd></div>
        <div class="review-item"><dt><?= e(t('org.field_contact_email')) ?></dt><dd><?= e((string) ($profile['contact_email'] ?? t('project.not_set'))) ?></dd></div>
        <div class="review-item"><dt><?= e(t('org.field_contact_phone')) ?></dt><dd><?= e((string) ($profile['contact_phone'] ?? t('project.not_set'))) ?></dd></div>
    </dl>
    <div class="form__actions">
        <a class="btn btn--secondary" href="<?= e(url('/dashboard')) ?>"><?= e(t('org.back_to_projects')) ?></a>
    </div>
<?php else: ?>
    <form class="form" method="post" action="<?= e(url('/onboarding/contact')) ?>" novalidate>
        <?= csrf_field() ?>

        <div class="grid-2">
            <div class="field" data-invalid="<?= e($invalid('contact_name')) ?>">
                <label for="contact_name"><?= e(t('org.field_contact_name')) ?> *</label>
                <input id="contact_name" name="contact_name" type="text" required maxlength="160" autocomplete="name" value="<?= e($value('contact_name')) ?>" <?= $aria('contact_name') ?>>
                <?php $errorFor('contact_name'); ?>
            </div>

            <div class="field" data-invalid="<?= e($invalid('contact_role')) ?>">
                <label for="contact_role"><?= e(t('org.field_contact_role')) ?> *</label>
                <input id="contact_role" name="contact_role" type="text" required maxlength="120" autocomplete="organization-title" value="<?= e($value('contact_role')) ?>" <?= $aria('contact_role') ?>>
                <span class="hint"><?= e(t('org.field_contact_role_hint')) ?></span>
                <?php $errorFor('contact_role'); ?>
            </div>

            <div class="field" data-invalid="<?= e($invalid('contact_email')) ?>">
                <label for="contact_email"><?= e(t('org.field_contact_email')) ?> *</label>
                <input id="contact_email" name="contact_email" type="email" required maxlength="254" inputmode="email" autocomplete="email" value="<?= e($value('contact_email')) ?>" <?= $aria('contact_email') ?>>
                <?php $errorFor('contact_email'); ?>
            </div>

            <div class="field" data-invalid="<?= e($invalid('contact_phone')) ?>">
                <label for="contact_phone"><?= e(t('org.field_contact_phone')) ?> *</label>
                <input id="contact_phone" name="contact_phone" type="tel" required maxlength="40" inputmode="tel" autocomplete="tel" placeholder="+254 700 000 000" value="<?= e($value('contact_phone')) ?>" <?= $aria('contact_phone') ?>>
                <?php $errorFor('contact_phone'); ?>
            </div>
        </div>

        <div class="alert alert--info mb-0" role="note">
            <?= e(t('org.contact_note')) ?>
        </div>

        <div class="form__actions">
            <a class="btn btn--secondary" href="<?= e(url('/onboarding?step=1')) ?>"><?= e(t('org.back')) ?></a>
            <button class="btn" type="submit"><?= e(t('org.continue')) ?></button>
        </div>
    </form>
<?php endif; ?>
