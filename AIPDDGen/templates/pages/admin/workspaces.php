<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $user
 * @var array{rows: list<array<string,mixed>>, total: int, page: int, pages: int} $workspaces
 * @var int $taskBadge
 */

$filters = ['q' => (string) ($_GET['q'] ?? ''), 'kyc' => (string) ($_GET['kyc'] ?? '')];
$pageUrl = static function (int $page) use ($filters): string {
    $params = array_filter([
        'q' => $filters['q'],
        'kyc' => $filters['kyc'],
        'page' => $page > 1 ? $page : null,
    ]);
    return url('/admin/workspaces') . ($params !== [] ? '?' . http_build_query($params) : '');
};
$kycBadge = static function (string $status): string {
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
        <h1><?= e(t('console.workspaces_title')) ?></h1>
        <p><?= e(t('console.workspaces_sub')) ?></p>
    </div>
    <span class="admin-muted"><?= e(t('console.showing', ['from' => $workspaces['total'] === 0 ? 0 : (($workspaces['page'] - 1) * 20) + 1, 'to' => min($workspaces['page'] * 20, $workspaces['total']), 'total' => $workspaces['total']])) ?></span>
</div>

<form class="admin-filters" method="get" action="<?= e(url('/admin/workspaces')) ?>" role="search">
    <div class="admin-filters__field admin-filters__field--grow">
        <label for="ws-q"><?= e(t('console.search_label')) ?></label>
        <input id="ws-q" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= e(t('console.search_placeholder')) ?>">
    </div>
    <div class="admin-filters__field">
        <label for="ws-kyc"><?= e(t('console.filter_kyc')) ?></label>
        <select id="ws-kyc" name="kyc">
            <option value=""><?= e(t('console.filter_all_kyc')) ?></option>
            <?php foreach (['draft', 'submitted', 'verified', 'rejected'] as $status): ?>
                <option value="<?= e($status) ?>" <?= $filters['kyc'] === $status ? 'selected' : '' ?>>
                    <?= e(t('console.kyc_status_' . $status)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="admin-filters__actions">
        <button class="btn btn--sm" type="submit"><?= e(t('console.apply')) ?></button>
        <a class="btn btn--secondary btn--sm" href="<?= e(url('/admin/workspaces')) ?>"><?= e(t('console.clear')) ?></a>
    </div>
</form>

<section class="panel-card">
    <?php if ($workspaces['rows'] === []): ?>
        <div class="admin-empty">
            <p class="admin-empty__title"><?= e(t('console.no_workspaces')) ?></p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data admin-table">
                <caption class="visually-hidden"><?= e(t('console.workspaces_title')) ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('console.table_workspace')) ?></th>
                        <th scope="col"><?= e(t('console.table_kyc')) ?></th>
                        <th scope="col"><?= e(t('console.table_creator')) ?></th>
                        <th scope="col"><?= e(t('console.table_staff')) ?></th>
                        <th scope="col"><?= e(t('console.table_projects')) ?></th>
                        <th scope="col"><?= e(t('console.table_created')) ?></th>
                        <th scope="col"><?= e(t('console.table_activity')) ?></th>
                        <th scope="col"><span class="visually-hidden"><?= e(t('console.table_actions')) ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($workspaces['rows'] as $row): ?>
                        <?php $kycStatus = (string) ($row['kyc_status'] ?? 'draft'); ?>
                        <tr>
                            <td>
                                <strong class="admin-task-title"><?= e((string) $row['name']) ?></strong>
                                <span class="admin-task-meta"><?= e((string) $row['slug']) ?><?= $row['legal_name'] !== null ? ' · ' . e((string) $row['legal_name']) : '' ?></span>
                            </td>
                            <td>
                                <span class="badge <?= e($kycBadge($kycStatus)) ?>"><?= e(t('console.kyc_status_' . $kycStatus)) ?></span>
                            </td>
                            <td>
                                <strong class="admin-task-title"><?= e((string) ($row['creator_name'] ?? t('console.no_creator'))) ?></strong>
                                <span class="admin-task-meta"><?= e((string) $row['creators']) ?> <?= e(t('console.admins_label')) ?></span>
                            </td>
                            <td><?= e((string) $row['staff']) ?></td>
                            <td><?= e((string) $row['projects']) ?></td>
                            <td class="admin-muted"><?= e(format_date((string) $row['created_at'])) ?></td>
                            <td class="admin-muted"><?= e($row['last_activity'] !== null ? format_date((string) $row['last_activity']) : t('console.never')) ?></td>
                            <td><a class="admin-link-arrow" href="<?= e(url('/admin/tasks') . '?q=' . rawurlencode((string) $row['name'])) ?>"><?= e(t('console.view_tasks')) ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($workspaces['pages'] > 1): ?>
            <nav class="admin-pagination" aria-label="<?= e(t('console.pagination')) ?>">
                <?php if ($workspaces['page'] > 1): ?>
                    <a class="btn btn--secondary btn--sm" href="<?= e($pageUrl($workspaces['page'] - 1)) ?>">&#8592; <?= e(t('console.previous')) ?></a>
                <?php endif; ?>
                <span class="admin-muted"><?= e(t('console.page_of', ['page' => $workspaces['page'], 'pages' => $workspaces['pages']])) ?></span>
                <?php if ($workspaces['page'] < $workspaces['pages']): ?>
                    <a class="btn btn--secondary btn--sm" href="<?= e($pageUrl($workspaces['page'] + 1)) ?>"><?= e(t('console.next')) ?> &#8594;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
