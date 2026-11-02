<?php

declare(strict_types=1);

use App\Services\OnboardingService;

/**
 * Step 1: legal organization details.
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
        <p class="onboarding-panel__eyebrow"><?= e(t('org.step1_of_4')) ?></p>
        <h2><?= e(t('org.step1_title')) ?></h2>
        <p class="muted"><?= e(t('org.step1_body')) ?></p>
    </div>
</div>

<?php if (!$canEdit): ?>
    <dl class="review-grid">
        <div class="review-item"><dt><?= e(t('org.field_legal_name')) ?></dt><dd><?= e((string) ($profile['legal_name'] ?? t('project.not_set'))) ?></dd></div>
        <div class="review-item"><dt><?= e(t('org.field_trading_name')) ?></dt><dd><?= e((string) (($profile['trading_name'] ?? '') !== '' ? $profile['trading_name'] : t('project.not_set'))) ?></dd></div>
        <div class="review-item"><dt><?= e(t('org.field_registration_number')) ?></dt><dd><?= e((string) ($profile['registration_number'] ?? t('project.not_set'))) ?></dd></div>
        <div class="review-item"><dt><?= e(t('org.field_registration_country')) ?></dt><dd><?= e((string) ($profile['registration_country'] ?? t('project.not_set'))) ?></dd></div>
        <div class="review-item"><dt><?= e(t('org.field_organization_type')) ?></dt><dd><?= e((string) (($profile['organization_type'] ?? null) !== null ? t('org.type_' . $profile['organization_type']) : t('project.not_set'))) ?></dd></div>
        <div class="review-item"><dt><?= e(t('org.field_year_founded')) ?></dt><dd><?= e((string) (($profile['year_founded'] ?? null) !== null ? $profile['year_founded'] : t('project.not_set'))) ?></dd></div>
        <div class="review-item"><dt><?= e(t('org.field_website')) ?></dt><dd><?= e((string) (($profile['website'] ?? '') !== '' ? $profile['website'] : t('project.not_set'))) ?></dd></div>
        <div class="review-item"><dt><?= e(t('org.field_city')) ?></dt><dd><?= e((string) ($profile['city'] ?? t('project.not_set'))) ?></dd></div>
        <div class="review-item review-item--wide"><dt><?= e(t('org.field_registered_address')) ?></dt><dd><?= e((string) ($profile['registered_address'] ?? t('project.not_set'))) ?></dd></div>
    </dl>
    <div class="form__actions">
        <a class="btn btn--secondary" href="<?= e(url('/dashboard')) ?>"><?= e(t('org.back_to_projects')) ?></a>
    </div>
<?php else: ?>
    <form class="form" method="post" action="<?= e(url('/onboarding/organization')) ?>" novalidate>
        <?= csrf_field() ?>

        <div class="grid-2">
            <div class="field" data-invalid="<?= e($invalid('legal_name')) ?>">
                <label for="legal_name"><?= e(t('org.field_legal_name')) ?> *</label>
                <input id="legal_name" name="legal_name" type="text" required maxlength="200" autocomplete="organization" value="<?= e($value('legal_name')) ?>" <?= $aria('legal_name') ?>>
                <span class="hint"><?= e(t('org.field_legal_name_hint')) ?></span>
                <?php $errorFor('legal_name'); ?>
            </div>

            <div class="field" data-invalid="<?= e($invalid('trading_name')) ?>">
                <label for="trading_name"><?= e(t('org.field_trading_name')) ?> <span class="muted">(<?= e(t('common.optional')) ?>)</span></label>
                <input id="trading_name" name="trading_name" type="text" maxlength="200" value="<?= e($value('trading_name')) ?>" <?= $aria('trading_name') ?>>
                <?php $errorFor('trading_name'); ?>
            </div>

            <div class="field" data-invalid="<?= e($invalid('registration_number')) ?>">
                <label for="registration_number"><?= e(t('org.field_registration_number')) ?> *</label>
                <input id="registration_number" name="registration_number" type="text" required maxlength="80" value="<?= e($value('registration_number')) ?>" <?= $aria('registration_number') ?>>
                <span class="hint"><?= e(t('org.field_registration_number_hint')) ?></span>
                <?php $errorFor('registration_number'); ?>
            </div>

            <div class="field" data-invalid="<?= e($invalid('registration_country')) ?>">
                <label for="registration_country"><?= e(t('org.field_registration_country')) ?> *</label>
                <input id="registration_country" name="registration_country" type="text" required maxlength="80" autocomplete="country-name" value="<?= e($value('registration_country')) ?>" <?= $aria('registration_country') ?>>
                <?php $errorFor('registration_country'); ?>
            </div>

            <div class="field" data-invalid="<?= e($invalid('organization_type')) ?>">
                <label for="organization_type"><?= e(t('org.field_organization_type')) ?> *</label>
                <select id="organization_type" name="organization_type" required <?= $aria('organization_type') ?>>
                    <option value=""><?= e(t('org.field_organization_type_placeholder')) ?></option>
                    <?php foreach (OnboardingService::ORGANIZATION_TYPES as $type): ?>
                        <option value="<?= e($type) ?>" <?= $value('organization_type') === $type ? 'selected' : '' ?>>
                            <?= e(t('org.type_' . $type)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php $errorFor('organization_type'); ?>
            </div>

            <div class="field" data-invalid="<?= e($invalid('year_founded')) ?>">
                <label for="year_founded"><?= e(t('org.field_year_founded')) ?> <span class="muted">(<?= e(t('common.optional')) ?>)</span></label>
                <input id="year_founded" name="year_founded" type="number" inputmode="numeric" min="1900" max="<?= e(gmdate('Y')) ?>" step="1" value="<?= e($value('year_founded')) ?>" <?= $aria('year_founded') ?>>
                <?php $errorFor('year_founded'); ?>
            </div>
        </div>

        <div class="field" data-invalid="<?= e($invalid('website')) ?>">
            <label for="website"><?= e(t('org.field_website')) ?> <span class="muted">(<?= e(t('common.optional')) ?>)</span></label>
            <input id="website" name="website" type="url" maxlength="255" inputmode="url" placeholder="https://" value="<?= e($value('website')) ?>" <?= $aria('website') ?>>
            <?php $errorFor('website'); ?>
        </div>

        <div class="field" data-invalid="<?= e($invalid('registered_address')) ?>">
            <label for="registered_address"><?= e(t('org.field_registered_address')) ?> *</label>
            <textarea id="registered_address" name="registered_address" required maxlength="400" rows="3" <?= $aria('registered_address') ?>><?= e($value('registered_address')) ?></textarea>
            <span class="hint"><?= e(t('org.field_registered_address_hint')) ?></span>
            <?php $errorFor('registered_address'); ?>
        </div>

        <div class="field" data-invalid="<?= e($invalid('city')) ?>">
            <label for="city"><?= e(t('org.field_city')) ?> *</label>
            <input id="city" name="city" type="text" required maxlength="120" autocomplete="address-level2" value="<?= e($value('city')) ?>" <?= $aria('city') ?>>
            <?php $errorFor('city'); ?>
        </div>

        <div class="form__actions">
            <a class="btn btn--secondary" href="<?= e(url('/dashboard')) ?>"><?= e(t('common.cancel')) ?></a>
            <button class="btn" type="submit"><?= e(t('org.continue')) ?></button>
        </div>
    </form>
<?php endif; ?>
