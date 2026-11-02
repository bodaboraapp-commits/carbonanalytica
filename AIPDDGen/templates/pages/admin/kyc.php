<?php

declare(strict_types=1);

/**
 * KYC review screen (staff only): filterable organisation queue with inline
 * verify / reject decisions. Decisions POST to /admin/kyc/{profileId}/verify or
 * /reject, redirect back here (PRG), and never need JavaScript.
 *
 * @var array<string, mixed> $user
 * @var array{rows: list<array<string,mixed>>, counts: array<string,int>, status: string} $kyc
 * @var array<string, string> $errors
 * @var string $focus_id
 * @var int $taskBadge
 */

$counts = $kyc['counts'];
$activeStatus = (string) ($kyc['status'] ?? 'submitted');
$badge = static function (string $status): string {
    return match ($status) {
        'submitted' => 'badge--high',
        'verified' => '',
        'rejected' => 'badge--urgent',
        default => 'badge--neutral',
    };
};
$filterUrl = static fn (string $status): string => url('/admin/kyc') . '?status=' . rawurlencode($status);
$totalDecided = (int) ($counts['submitted'] ?? 0) + (int) ($counts['verified'] ?? 0) + (int) ($counts['rejected'] ?? 0);
$statusFilters = [
    'submitted' => ['label' => t('console.kyc_status_submitted'), 'count' => (int) ($counts['submitted'] ?? 0)],
    'verified' => ['label' => t('console.kyc_status_verified'), 'count' => (int) ($counts['verified'] ?? 0)],
    'rejected' => ['label' => t('console.kyc_status_rejected'), 'count' => (int) ($counts['rejected'] ?? 0)],
    'all' => ['label' => t('admin.scope_all'), 'count' => $totalDecided],
];
$noteMax = \App\Services\OrganizationReviewService::NOTE_MAX;
$oldNote = (string) old('review_note');

/**
 * Inline error copy for the decision form (the service returns validation
 * codes; this maps them to actionable text instead of the generic message).
 *
 * @param array<string, string> $errors
 */
$reviewError = static function (string $profileId, array $errors, string $focusId): string {
    if ($profileId !== $focusId || !isset($errors['review_note'])) {
        return '';
    }

    return match ((string) $errors['review_note']) {
        'required' => t('console.kyc_reject_required'),
        'too_long' => t('console.kyc_reject_too_long', ['max' => \App\Services\OrganizationReviewService::NOTE_MAX]),
        default => form_error('review_note', $errors),
    };
};
?>
<div class="admin-page-head">
    <div>
        <h1><?= e(t('console.kyc_title')) ?></h1>
        <p><?= e(t('console.kyc_sub')) ?></p>
    </div>
    <a class="admin-link-arrow" href="<?= e(url('/admin/tasks') . '?type=organisation_kyb') ?>"><?= e(t('console.link_tasks')) ?> <span aria-hidden="true">&#8594;</span></a>
</div>

<section class="stat-grid stat-grid--3" aria-labelledby="kyc-kpi-title">
    <h2 class="visually-hidden" id="kyc-kpi-title"><?= e(t('console.kyc_title')) ?></h2>
    <?php \App\View::partial('partials/stat-card', ['label' => t('console.kyc_status_submitted'), 'value' => (string) ($counts['submitted'] ?? 0), 'icon' => 'inbox', 'tone' => 'blue']); ?>
    <?php \App\View::partial('partials/stat-card', ['label' => t('console.kyc_status_verified'), 'value' => (string) ($counts['verified'] ?? 0), 'icon' => 'shield', 'tone' => 'green']); ?>
    <?php \App\View::partial('partials/stat-card', ['label' => t('console.kyc_status_rejected'), 'value' => (string) ($counts['rejected'] ?? 0), 'icon' => 'reject', 'tone' => 'danger']); ?>
</section>

<nav class="kyc-filters" aria-label="<?= e(t('console.kyc_filters_label')) ?>">
    <?php foreach ($statusFilters as $statusSlug => $filter): ?>
        <a class="kyc-filter<?= $activeStatus === $statusSlug ? ' kyc-filter--active' : '' ?>"
           href="<?= e($filterUrl($statusSlug)) ?>"
           <?= $activeStatus === $statusSlug ? 'aria-current="page"' : '' ?>>
            <?= e($filter['label']) ?>
            <span class="kyc-filter__count"><?= e((string) $filter['count']) ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<?php if ($errors !== []): ?>
    <div class="alert alert--error" role="alert"><?= e(t('error.validation_error')) ?></div>
<?php endif; ?>

<section class="panel-card">
    <?php if ($kyc['rows'] === []): ?>
        <div class="admin-empty">
            <p class="admin-empty__title"><?= e(t('console.no_kyc')) ?></p>
            <p class="admin-empty__body"><?= e(t('console.kyc_sub')) ?></p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data admin-table kyc-table">
                <caption class="visually-hidden"><?= e(t('console.kyc_title')) ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('console.table_org')) ?></th>
                        <th scope="col"><?= e(t('console.table_workspace')) ?></th>
                        <th scope="col"><?= e(t('console.table_status')) ?></th>
                        <th scope="col"><?= e(t('console.table_documents')) ?></th>
                        <th scope="col"><?= e(t('console.table_submitted')) ?></th>
                        <th scope="col"><?= e(t('console.table_decided')) ?></th>
                        <th scope="col"><?= e(t('console.table_actions')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($kyc['rows'] as $row): ?>
                        <?php
                        $profileId = (string) $row['id'];
                        $rowStatus = (string) $row['status'];
                        $isWaiting = $rowStatus === 'submitted';
                        $isFocused = $focus_id === $profileId;
                        $rowError = $reviewError($profileId, $errors, $focus_id);
                        ?>
                        <tr id="kyc-<?= e($profileId) ?>" class="kyc-row<?= $isFocused ? ' kyc-row--focus' : '' ?>">
                            <td>
                                <strong><?= e((string) ($row['legal_name'] ?? t('console.no_company_name'))) ?></strong>
                                <?php if ((string) ($row['trading_name'] ?? '') !== ''): ?>
                                    <span class="admin-task-meta"><?= e((string) $row['trading_name']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= e((string) $row['team_name']) ?>
                                <?php if ($row['open_task_id'] !== null && $isWaiting): ?>
                                    <div class="kyc-open-task">
                                        <a class="admin-link-arrow" href="<?= e(url('/admin/tasks') . '?type=organisation_kyb') ?>"><?= e(t('console.open_task')) ?></a>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= e($badge($rowStatus)) ?>"><?= e(t('console.kyc_status_' . $rowStatus)) ?></span>
                            </td>
                            <td><?= e((string) $row['documents']) ?></td>
                            <td class="admin-muted"><?= e($row['submitted_at'] !== null ? format_date((string) $row['submitted_at']) : t('console.not_submitted')) ?></td>
                            <td class="kyc-decided">
                                <?php if ($row['reviewed_at'] !== null): ?>
                                    <span class="kyc-decided__date"><?= e(format_date((string) $row['reviewed_at'])) ?></span>
                                    <?php if ((string) ($row['review_note'] ?? '') !== ''): ?>
                                        <span class="kyc-decided__note"><?= e((string) $row['review_note']) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="admin-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isWaiting): ?>
                                    <details class="kyc-review"<?= $isFocused ? ' open' : '' ?>>
                                        <summary><?= e(t('console.kyc_review_action')) ?></summary>
                                        <p class="admin-muted small"><?= e(t('console.kyc_review_hint')) ?></p>

                                        <div class="kyc-review__forms">
                                            <form class="form kyc-review__form" method="post" action="<?= e(url('/admin/kyc/' . $profileId . '/verify')) ?>">
                                                <?= csrf_field() ?>
                                                <span class="kyc-review__legend"><?= e(t('console.kyc_verify_legend')) ?></span>
                                                <div class="field">
                                                    <label for="kyc-verify-note-<?= e($profileId) ?>">
                                                        <?= e(t('console.kyc_verify_note_label', ['max' => $noteMax])) ?>
                                                    </label>
                                                    <textarea id="kyc-verify-note-<?= e($profileId) ?>" name="review_note" rows="2"
                                                              maxlength="<?= e((string) $noteMax) ?>"
                                                              placeholder="<?= e(t('console.kyc_verify_note_placeholder')) ?>"><?= $isFocused ? e($oldNote) : '' ?></textarea>
                                                </div>
                                                <button class="btn btn--sm" type="submit"><?= e(t('console.kyc_verify_button')) ?></button>
                                            </form>

                                            <form class="form kyc-review__form kyc-review__form--danger" method="post" action="<?= e(url('/admin/kyc/' . $profileId . '/reject')) ?>">
                                                <?= csrf_field() ?>
                                                <span class="kyc-review__legend"><?= e(t('console.kyc_reject_legend')) ?></span>
                                                <div class="field"<?= $rowError !== '' ? ' data-invalid="true"' : '' ?>>
                                                    <label for="kyc-reject-note-<?= e($profileId) ?>">
                                                        <?= e(t('console.kyc_reject_note_label', ['max' => $noteMax])) ?>
                                                    </label>
                                                    <textarea id="kyc-reject-note-<?= e($profileId) ?>" name="review_note" rows="2"
                                                              maxlength="<?= e((string) $noteMax) ?>" required
                                                              <?= $rowError !== '' ? 'aria-invalid="true" aria-describedby="kyc-reject-error-' . e($profileId) . '"' : '' ?>
                                                              placeholder="<?= e(t('console.kyc_reject_note_placeholder')) ?>"><?= $isFocused ? e($oldNote) : '' ?></textarea>
                                                    <?php if ($rowError !== ''): ?>
                                                        <span class="field__error" id="kyc-reject-error-<?= e($profileId) ?>"><?= e($rowError) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <button class="btn btn--danger btn--sm" type="submit"><?= e(t('console.kyc_reject_button')) ?></button>
                                            </form>
                                        </div>
                                    </details>
                                <?php else: ?>
                                    <span class="admin-muted"><?= e(t('console.kyc_already_reviewed')) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
