<?php

declare(strict_types=1);

use App\Services\OnboardingService;
use App\View;

/**
 * Organization onboarding wizard. The stepper and the four step panels are
 * partials so each screen stays readable; all rules live in OnboardingService.
 *
 * @var array<string, mixed> $user
 * @var array<string, mixed> $team
 * @var array<string, mixed> $state
 * @var int $step
 * @var int $maxStep
 * @var array<string, string> $errors
 * @var int $uploadMaxBytes
 */

$profile = $state['profile'];
$status = (string) $state['status'];
$isLocked = (bool) $state['is_locked'];
$canEdit = (bool) $state['can_edit'];
$completion = $state['completion'];
$missingRequired = $state['missing_required'];

$statusClass = match ($status) {
    'submitted' => 'badge--gold',
    'verified' => 'badge',
    'rejected' => 'badge--draft',
    default => 'badge--neutral',
};

$statusKey = match ($status) {
    'submitted' => 'org.status_submitted',
    'verified' => 'org.status_verified',
    'rejected' => 'org.status_rejected',
    default => 'org.status_draft',
};
?>
<div class="app-head">
    <div>
        <h1><?= e(t('org.title')) ?></h1>
        <p class="app-head__sub"><?= e(t('org.sub')) ?></p>
    </div>
    <span class="workspace-pill">
        <strong><?= e(t('dashboard.workspace_label')) ?>:</strong>
        <?= e((string) $team['name']) ?>
        <span class="badge <?= e($statusClass) ?>"><?= e(t($statusKey)) ?></span>
    </span>
</div>

<?php if ($status === 'submitted'): ?>
    <div class="alert alert--info" role="status">
        <div>
            <strong><?= e(t('org.banner_submitted_title')) ?></strong>
            <?= e(t('org.banner_submitted_body', ['date' => format_date((string) ($profile['submitted_at'] ?? ''))])) ?>
        </div>
    </div>
<?php elseif ($status === 'verified'): ?>
    <div class="alert alert--success" role="status">
        <div>
            <strong><?= e(t('org.banner_verified_title')) ?></strong>
            <?= e(t('org.banner_verified_body')) ?>
        </div>
    </div>
<?php elseif ($status === 'rejected'): ?>
    <div class="alert alert--error" role="alert">
        <div>
            <strong><?= e(t('org.banner_rejected_title')) ?></strong>
            <?= e(t('org.banner_rejected_body')) ?>
            <?php if ((string) ($profile['review_note'] ?? '') !== ''): ?>
                <p class="mt-1 mb-0"><?= e((string) $profile['review_note']) ?></p>
            <?php endif; ?>
        </div>
    </div>
<?php elseif (!$canEdit): ?>
    <div class="notice" role="status">
        <?= e(t('org.read_only_note')) ?>
    </div>
<?php endif; ?>

<?php View::partial('partials/onboarding/stepper', [
    'step' => $step,
    'maxStep' => $maxStep,
    'completion' => $completion,
    'isLocked' => $isLocked,
]); ?>

<section class="card onboarding-panel">
    <?php if ($step === 1): ?>
        <?php View::partial('partials/onboarding/step-organization', [
            'profile' => $profile,
            'errors' => $errors,
            'canEdit' => $canEdit,
        ]); ?>
    <?php elseif ($step === 2): ?>
        <?php View::partial('partials/onboarding/step-contact', [
            'profile' => $profile,
            'errors' => $errors,
            'canEdit' => $canEdit,
        ]); ?>
    <?php elseif ($step === 3): ?>
        <?php View::partial('partials/onboarding/step-documents', [
            'documents' => $state['documents_by_kind'],
            'canEdit' => $canEdit,
            'uploadMaxBytes' => $uploadMaxBytes,
            'missingRequired' => $missingRequired,
            'maxStep' => $maxStep,
        ]); ?>
    <?php else: ?>
        <?php View::partial('partials/onboarding/step-review', [
            'profile' => $profile,
            'documents' => $state['documents_by_kind'],
            'completion' => $completion,
            'missingRequired' => $missingRequired,
            'status' => $status,
            'isLocked' => $isLocked,
            'canEdit' => $canEdit,
        ]); ?>
    <?php endif; ?>
</section>
