<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $user
 * @var array{rows: list<array<string,mixed>>, total: int, page: int, pages: int} $audit
 * @var int $taskBadge
 */

$action = (string) ($_GET['action'] ?? '');
$pageUrl = static function (int $page) use ($action): string {
    $params = array_filter([
        'action' => $action,
        'page' => $page > 1 ? $page : null,
    ]);
    return url('/admin/audit') . ($params !== [] ? '?' . http_build_query($params) : '');
};
?>
<div class="admin-page-head">
    <div>
        <h1><?= e(t('console.audit_title')) ?></h1>
        <p><?= e(t('console.audit_sub')) ?></p>
    </div>
    <span class="admin-muted"><?= e(t('console.showing', ['from' => $audit['total'] === 0 ? 0 : (($audit['page'] - 1) * 25) + 1, 'to' => min($audit['page'] * 25, $audit['total']), 'total' => $audit['total']])) ?></span>
</div>

<form class="admin-filters" method="get" action="<?= e(url('/admin/audit')) ?>" role="search">
    <div class="admin-filters__field admin-filters__field--grow">
        <label for="audit-action"><?= e(t('console.filter_action')) ?></label>
        <input id="audit-action" type="search" name="action" value="<?= e($action) ?>" placeholder="<?= e(t('console.filter_action_placeholder')) ?>">
    </div>
    <div class="admin-filters__actions">
        <button class="btn btn--sm" type="submit"><?= e(t('console.apply')) ?></button>
        <a class="btn btn--secondary btn--sm" href="<?= e(url('/admin/audit')) ?>"><?= e(t('console.clear')) ?></a>
    </div>
</form>

<section class="panel-card">
    <?php if ($audit['rows'] === []): ?>
        <div class="admin-empty"><p class="admin-empty__title"><?= e(t('console.no_audit')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data admin-table">
                <caption class="visually-hidden"><?= e(t('console.audit_title')) ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('console.table_time')) ?></th>
                        <th scope="col"><?= e(t('console.table_actor')) ?></th>
                        <th scope="col"><?= e(t('console.table_action')) ?></th>
                        <th scope="col"><?= e(t('console.table_workspace')) ?></th>
                        <th scope="col"><?= e(t('console.table_details')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($audit['rows'] as $row): ?>
                        <tr>
                            <td class="admin-muted"><?= e(format_date((string) $row['created_at'])) ?></td>
                            <td>
                                <?php if ($row['actor_name'] !== null): ?>
                                    <strong class="admin-task-title"><?= e((string) $row['actor_name']) ?></strong>
                                    <span class="admin-task-meta"><?= e((string) ($row['actor_email'] ?? '')) ?></span>
                                <?php else: ?>
                                    <span class="admin-muted"><?= e(t('console.system_actor')) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><code class="mono"><?= e((string) $row['action']) ?></code></td>
                            <td><?= e((string) ($row['team_name'] ?? t('console.no_workspace'))) ?></td>
                            <td class="admin-muted">
                                <?= e(mb_strimwidth((string) ($row['metadata'] ?? ''), 0, 80, '…')) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($audit['pages'] > 1): ?>
            <nav class="admin-pagination" aria-label="<?= e(t('console.pagination')) ?>">
                <?php if ($audit['page'] > 1): ?>
                    <a class="btn btn--secondary btn--sm" href="<?= e($pageUrl($audit['page'] - 1)) ?>">&#8592; <?= e(t('console.previous')) ?></a>
                <?php endif; ?>
                <span class="admin-muted"><?= e(t('console.page_of', ['page' => $audit['page'], 'pages' => $audit['pages']])) ?></span>
                <?php if ($audit['page'] < $audit['pages']): ?>
                    <a class="btn btn--secondary btn--sm" href="<?= e($pageUrl($audit['page'] + 1)) ?>"><?= e(t('console.next')) ?> &#8594;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
