<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $user
 * @var array{rows: list<array<string,mixed>>, counts: array<string,int>} $kyc
 * @var int $taskBadge
 */

$counts = $kyc['counts'];
$badge = static function (string $status): string {
    return match ($status) {
        'submitted' => 'badge--high',
        'verified' => '',
        'rejected' => 'badge--urgent',
        default => 'badge--neutral',
    };
};
?>
<div class="admin-page-head">
    <div>
        <h1><?= e(t('console.kyc_title')) ?></h1>
        <p><?= e(t('console.kyc_sub')) ?></p>
    </div>
</div>

<section class="kpi-grid kpi-grid--3">
    <article class="kpi-card">
        <span class="kpi-card__label"><?= e(t('console.kyc_status_submitted')) ?></span>
        <span class="kpi-card__value"><?= e((string) ($counts['submitted'] ?? 0)) ?></span>
    </article>
    <article class="kpi-card">
        <span class="kpi-card__label"><?= e(t('console.kyc_status_verified')) ?></span>
        <span class="kpi-card__value"><?= e((string) ($counts['verified'] ?? 0)) ?></span>
    </article>
    <article class="kpi-card">
        <span class="kpi-card__label"><?= e(t('console.kyc_status_rejected')) ?></span>
        <span class="kpi-card__value"><?= e((string) ($counts['rejected'] ?? 0)) ?></span>
    </article>
</section>

<section class="panel-card">
    <?php if ($kyc['rows'] === []): ?>
        <div class="admin-empty">
            <p class="admin-empty__title"><?= e(t('console.no_kyc')) ?></p>
            <p class="admin-empty__body"><?= e(t('console.kyc_sub')) ?></p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data admin-table">
                <caption class="visually-hidden"><?= e(t('console.kyc_title')) ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('console.table_org')) ?></th>
                        <th scope="col"><?= e(t('console.table_workspace')) ?></th>
                        <th scope="col"><?= e(t('console.table_status')) ?></th>
                        <th scope="col"><?= e(t('console.table_documents')) ?></th>
                        <th scope="col"><?= e(t('console.table_submitted')) ?></th>
                        <th scope="col"><?= e(t('console.table_task')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($kyc['rows'] as $row): ?>
                        <tr>
                            <td><strong><?= e((string) ($row['legal_name'] ?? t('console.no_company_name'))) ?></strong></td>
                            <td><?= e((string) $row['team_name']) ?></td>
                            <td><span class="badge <?= e($badge((string) $row['status'])) ?>"><?= e(t('console.kyc_status_' . (string) $row['status'])) ?></span></td>
                            <td><?= e((string) $row['documents']) ?></td>
                            <td class="admin-muted"><?= e($row['submitted_at'] !== null ? format_date((string) $row['submitted_at']) : t('console.not_submitted')) ?></td>
                            <td>
                                <?php if ($row['open_task_id'] !== null): ?>
                                    <a class="admin-link-arrow" href="<?= e(url('/admin/tasks') . '?type=organisation_kyb') ?>"><?= e(t('console.open_task')) ?></a>
                                <?php else: ?>
                                    <span class="admin-muted"><?= e(t('console.no_task')) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
