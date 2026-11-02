<?php

declare(strict_types=1);

use App\Services\OnboardingService;
use App\Storage;

/**
 * Step 3: required verification documents, one current file per requirement.
 *
 * @var array<string, array<string, mixed>> $documents by kind
 * @var bool $canEdit
 * @var int $uploadMaxBytes
 * @var list<string> $missingRequired
 * @var int $maxStep
 */

$requiredCount = count(array_filter(OnboardingService::DOCUMENTS));
$uploadedRequired = $requiredCount - count($missingRequired);
$uploadLimitLabel = Storage::formatSize($uploadMaxBytes);
?>
<div class="onboarding-panel__head">
    <div>
        <p class="onboarding-panel__eyebrow"><?= e(t('org.step3_of_4')) ?></p>
        <h2><?= e(t('org.step3_title')) ?></h2>
        <p class="muted"><?= e(t('org.step3_body')) ?></p>
    </div>
    <p class="doc-progress" role="status">
        <strong><?= e((string) $uploadedRequired) ?> / <?= e((string) $requiredCount) ?></strong>
        <?= e(t('org.documents_progress_label')) ?>
    </p>
</div>

<div class="declaration-note">
    <div class="declaration-note__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="22" height="22" focusable="false"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M14 3v5h5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M9 13h6M9 17h4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
    </div>
    <div class="declaration-note__body">
        <strong><?= e(t('org.declaration_title')) ?></strong>
        <p><?= e(t('org.declaration_body')) ?></p>
    </div>
    <a class="btn btn--secondary btn--sm" href="<?= e(asset('templates/organization-declaration-template.docx')) ?>" target="_blank" rel="noopener">
        <?= e(t('org.declaration_button')) ?>
    </a>
</div>

<?php foreach (OnboardingService::DOCUMENTS as $kind => $required): ?>
    <?php
    $document = $documents[$kind] ?? null;
    $inputId = 'file-' . $kind;
    $isViewable = $document !== null && in_array((string) $document['mime_type'], ['application/pdf'], true);
    ?>
    <article class="doc-requirement <?= $document === null && $required ? 'doc-requirement--missing' : '' ?>" aria-labelledby="doc-title-<?= e($kind) ?>">
        <div class="doc-requirement__head">
            <h3 id="doc-title-<?= e($kind) ?>">
                <?= e(t('org.doc_' . $kind)) ?>
                <?php if ($required): ?><span class="req-star" aria-label="<?= e(t('org.required')) ?>">*</span><?php endif; ?>
            </h3>
            <span class="badge <?= $required ? '' : 'badge--neutral' ?>">
                <?= e($required ? t('org.required') : t('common.optional')) ?>
            </span>
        </div>
        <p class="doc-requirement__hint"><?= e(t('org.doc_' . $kind . '_hint')) ?></p>

        <?php if ($document !== null): ?>
            <div class="doc-file">
                <span class="doc-file__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="20" height="20" focusable="false"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M14 3v5h5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
                </span>
                <div class="doc-file__body">
                    <a class="doc-file__name" href="<?= e(url('/onboarding/documents/' . $document['id'] . '/download')) ?>">
                        <?= e((string) $document['original_filename']) ?>
                    </a>
                    <span class="doc-file__meta">
                        <?= e(Storage::formatSize((int) $document['size_bytes'])) ?>
                        · <?= e(t('org.uploaded_on', ['date' => format_date((string) $document['created_at'])])) ?>
                        <?php if (!$isViewable): ?>
                            · <?= e(t('org.downloads_only')) ?>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="doc-file__actions">
                    <a class="btn btn--ghost btn--sm" href="<?= e(url('/onboarding/documents/' . $document['id'])) ?>" target="_blank" rel="noopener">
                        <?= e(t('org.view_file')) ?>
                    </a>
                    <a class="btn btn--ghost btn--sm" href="<?= e(url('/onboarding/documents/' . $document['id'] . '/download')) ?>">
                        <?= e(t('org.download_file')) ?>
                    </a>
                    <?php if ($canEdit): ?>
                        <form class="inline-form" method="post" action="<?= e(url('/onboarding/documents/' . $document['id'] . '/delete')) ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn--danger btn--sm" type="submit"><?= e(t('org.remove_file')) ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif (!$canEdit): ?>
            <p class="small muted mb-0"><?= e(t('org.document_not_uploaded')) ?></p>
        <?php endif; ?>

        <?php if ($canEdit): ?>
            <form class="doc-upload" method="post" enctype="multipart/form-data" action="<?= e(url('/onboarding/documents')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="kind" value="<?= e($kind) ?>">
                <label class="dropzone" for="<?= e($inputId) ?>">
                    <span class="dropzone__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="22" height="22" focusable="false"><path d="M12 16V5m0 0-3.5 3.5M12 5l3.5 3.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 15v3a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    </span>
                    <span class="dropzone__text"><strong><?= e($document !== null ? t('org.replace_click') : t('org.upload_click')) ?></strong></span>
                    <span class="dropzone__hint"><?= e(t('org.upload_hint')) ?> PDF, DOC, DOCX (<?= e(t('org.upload_limit', ['size' => $uploadLimitLabel])) ?>)</span>
                    <input class="dropzone__input" id="<?= e($inputId) ?>" name="document" type="file" accept=".pdf,.doc,.docx" required>
                </label>
                <button class="btn btn--secondary btn--sm" type="submit"><?= e(t('org.upload_button')) ?></button>
            </form>
        <?php endif; ?>
    </article>
<?php endforeach; ?>

<?php if ($missingRequired !== []): ?>
    <div class="alert alert--warning" role="status">
        <?= e(t('org.missing_documents', ['count' => count($missingRequired)])) ?>
    </div>
<?php endif; ?>

<div class="form__actions">
    <a class="btn btn--secondary" href="<?= e(url('/onboarding?step=2')) ?>"><?= e(t('org.back')) ?></a>
    <?php if ($maxStep >= 4): ?>
        <a class="btn" href="<?= e(url('/onboarding?step=4')) ?>"><?= e(t('org.continue_review')) ?></a>
    <?php endif; ?>
</div>
