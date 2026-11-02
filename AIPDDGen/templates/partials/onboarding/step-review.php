<?php

declare(strict_types=1);

use App\Services\OnboardingService;
use App\Storage;

/**
 * Step 4: review every detail, attest, and submit for verification.
 *
 * @var array<string, mixed>|null $profile
 * @var array<string, array<string, mixed>> $documents by kind
 * @var array<int, bool> $completion
 * @var list<string> $missingRequired
 * @var string $status
 * @var bool $isLocked
 * @var bool $canEdit
 */

$typeLabel = ($profile['organization_type'] ?? null) !== null
    ? t('org.type_' . $profile['organization_type'])
    : t('project.not_set');
?>
<div class="onboarding-panel__head">
    <div>
        <p class="onboarding-panel__eyebrow"><?= e(t('org.step4_of_4')) ?></p>
        <h2><?= e(t('org.step4_title')) ?></h2>
        <p class="muted"><?= e(t('org.step4_body')) ?></p>
    </div>
</div>

<section class="review-section" aria-labelledby="review-org-title">
    <div class="review-section__head">
        <h3 id="review-org-title"><?= e(t('org.step1_title')) ?></h3>
        <?php if ($canEdit): ?>
            <a class="btn btn--ghost btn--sm" href="<?= e(url('/onboarding?step=1')) ?>"><?= e(t('common.edit')) ?></a>
        <?php endif; ?>
    </div>
    <dl class="review-grid">
        <div class="review-item"><dt><?= e(t('org.field_legal_name')) ?></dt><dd><?= e((string) ($profile['legal_name'] ?? t('project.not_set'))) ?></dd></div>
        <div class="review-item"><dt><?= e(t('org.field_trading_name')) ?></dt><dd><?= e((string) (($profile['trading_name'] ?? '') !== '' ? $profile['trading_name'] : t('project.not_set'))) ?></dd></div>
        <div class="review-item"><dt><?= e(t('org.field_registration_number')) ?></dt><dd><?= e((string) ($profile['registration_number'] ?? t('project.not_set'))) ?></dd></div>
        <div class="review-item"><dt><?= e(t('org.field_registration_country')) ?></dt><dd><?= e((string) ($profile['registration_country'] ?? t('project.not_set'))) ?></dd></div>
        <div class="review-item"><dt><?= e(t('org.field_organization_type')) ?></dt><dd><?= e((string) $typeLabel) ?></dd></div>
        <div class="review-item"><dt><?= e(t('org.field_year_founded')) ?></dt><dd><?= e((string) (($profile['year_founded'] ?? null) !== null ? $profile['year_founded'] : t('project.not_set'))) ?></dd></div>
        <div class="review-item"><dt><?= e(t('org.field_website')) ?></dt><dd><?= e((string) (($profile['website'] ?? '') !== '' ? $profile['website'] : t('project.not_set'))) ?></dd></div>
        <div class="review-item"><dt><?= e(t('org.field_city')) ?></dt><dd><?= e((string) ($profile['city'] ?? t('project.not_set'))) ?></dd></div>
        <div class="review-item review-item--wide"><dt><?= e(t('org.field_registered_address')) ?></dt><dd><?= e((string) ($profile['registered_address'] ?? t('project.not_set'))) ?></dd></div>
    </dl>
</section>

<section class="review-section" aria-labelledby="review-contact-title">
    <div class="review-section__head">
        <h3 id="review-contact-title"><?= e(t('org.step2_title')) ?></h3>
        <?php if ($canEdit): ?>
            <a class="btn btn--ghost btn--sm" href="<?= e(url('/onboarding?step=2')) ?>"><?= e(t('common.edit')) ?></a>
        <?php endif; ?>
    </div>
    <dl class="review-grid">
        <div class="review-item"><dt><?= e(t('org.field_contact_name')) ?></dt><dd><?= e((string) ($profile['contact_name'] ?? t('project.not_set'))) ?></dd></div>
        <div class="review-item"><dt><?= e(t('org.field_contact_role')) ?></dt><dd><?= e((string) ($profile['contact_role'] ?? t('project.not_set'))) ?></dd></div>
        <div class="review-item"><dt><?= e(t('org.field_contact_email')) ?></dt><dd><?= e((string) ($profile['contact_email'] ?? t('project.not_set'))) ?></dd></div>
        <div class="review-item"><dt><?= e(t('org.field_contact_phone')) ?></dt><dd><?= e((string) ($profile['contact_phone'] ?? t('project.not_set'))) ?></dd></div>
    </dl>
</section>

<section class="review-section" aria-labelledby="review-docs-title">
    <div class="review-section__head">
        <h3 id="review-docs-title"><?= e(t('org.step3_title')) ?></h3>
        <?php if ($canEdit): ?>
            <a class="btn btn--ghost btn--sm" href="<?= e(url('/onboarding?step=3')) ?>"><?= e(t('common.edit')) ?></a>
        <?php endif; ?>
    </div>
    <ul class="review-docs">
        <?php foreach (OnboardingService::DOCUMENTS as $kind => $required): ?>
            <?php $document = $documents[$kind] ?? null; ?>
            <li class="review-docs__item">
                <span class="badge <?= $document !== null ? '' : 'badge--neutral' ?>" aria-hidden="true">
                    <?= e($document !== null ? '✓' : '!') ?>
                </span>
                <span class="review-docs__text">
                    <?= e(t('org.doc_' . $kind)) ?>
                    <?php if ($required): ?><span class="req-star" aria-label="<?= e(t('org.required')) ?>">*</span><?php endif; ?>
                </span>
                <?php if ($document !== null): ?>
                    <a class="review-docs__action" href="<?= e(url('/onboarding/documents/' . $document['id'] . '/download')) ?>">
                        <?= e((string) $document['original_filename']) ?>
                        <span class="muted">(<?= e(Storage::formatSize((int) $document['size_bytes'])) ?>)</span>
                    </a>
                <?php elseif ($required): ?>
                    <span class="review-docs__action muted"><?= e(t('org.document_not_uploaded')) ?></span>
                <?php else: ?>
                    <span class="review-docs__action muted"><?= e(t('common.optional')) ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</section>

<?php if ($isLocked): ?>
    <div class="alert alert--info mb-0" role="status">
        <?= e(t('org.submitted_on', ['date' => format_date((string) ($profile['submitted_at'] ?? ''))])) ?>
    </div>
    <div class="form__actions">
        <a class="btn btn--secondary" href="<?= e(url('/onboarding?step=3')) ?>"><?= e(t('org.back')) ?></a>
        <a class="btn" href="<?= e(url('/dashboard')) ?>"><?= e(t('org.back_to_projects')) ?></a>
    </div>
<?php elseif ($canEdit): ?>
    <form class="form" method="post" action="<?= e(url('/onboarding/submit')) ?>">
        <?= csrf_field() ?>
        <label class="checkline" for="attestation">
            <input id="attestation" name="attestation" type="checkbox" value="1" required>
            <span><?= e(t('org.attestation')) ?></span>
        </label>

        <?php if ($missingRequired !== [] || !$completion[1] || !$completion[2]): ?>
            <div class="alert alert--warning mb-0" role="status">
                <?= e(t('org.submit_incomplete')) ?>
            </div>
        <?php endif; ?>

        <div class="form__actions">
            <a class="btn btn--secondary" href="<?= e(url('/onboarding?step=3')) ?>"><?= e(t('org.back')) ?></a>
            <button class="btn" type="submit" <?= ($missingRequired !== [] || !$completion[1] || !$completion[2]) ? 'disabled' : '' ?>>
                <?= e(t('org.submit_button')) ?>
            </button>
        </div>
        <p class="form__meta"><?= e(t('org.submit_meta')) ?></p>
    </form>
<?php else: ?>
    <p class="notice mb-0"><?= e(t('org.submit_read_only_note')) ?></p>
    <div class="form__actions">
        <a class="btn btn--secondary" href="<?= e(url('/onboarding?step=3')) ?>"><?= e(t('org.back')) ?></a>
    </div>
<?php endif; ?>
