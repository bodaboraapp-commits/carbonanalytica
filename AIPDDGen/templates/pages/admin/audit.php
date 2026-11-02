<?php

declare(strict_types=1);

/**
 * Audit & security: a leading "Recent activity" panel (latest unfiltered
 * entries, five per page) followed by the full filterable audit trail.
 *
 * @var array<string, mixed> $user
 * @var array{rows: list<array<string,mixed>>, total: int, page: int, pages: int} $audit
 * @var list<array<string,mixed>> $recent_audit
 * @var int $recent_page
 * @var int $recent_pages
 * @var int $recent_total
 * @var int $taskBadge
 */

$action = (string) ($_GET['action'] ?? '');
$tablePage = (int) $audit['page'];
$recentPage = max(1, (int) $recent_page);
// Keep these page sizes in sync with AdminConsoleController::audit().
$auditPerPage = 5;
$recentPerPage = 5;

// Each panel pages independently but keeps the other panel's state in the URL.
$auditPageUrl = static function (int $page) use ($action, $recentPage): string {
    $params = array_filter([
        'action' => $action,
        'page' => $page > 1 ? $page : null,
        'recent_page' => $recentPage > 1 ? $recentPage : null,
    ]);
    return url('/admin/audit') . ($params !== [] ? '?' . http_build_query($params) : '');
};
$recentPageUrl = static function (int $page) use ($action, $tablePage): string {
    $params = array_filter([
        'action' => $action,
        'page' => $tablePage > 1 ? $tablePage : null,
        'recent_page' => $page > 1 ? $page : null,
    ]);
    return url('/admin/audit') . ($params !== [] ? '?' . http_build_query($params) : '');
};
/** "Showing X–Y of Z" for a paginated panel. */
$showingLabel = static function (int $page, int $perPage, int $total): string {
    return t('console.showing', [
        'from' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
        'to' => min($page * $perPage, $total),
        'total' => $total,
    ]);
};
$showingAudit = $showingLabel($tablePage, $auditPerPage, (int) $audit['total']);
$showingRecent = $showingLabel($recentPage, $recentPerPage, (int) $recent_total);
?>
<div class="admin-page-head">
    <div>
        <h1><?= e(t('console.audit_title')) ?></h1>
        <p><?= e(t('console.audit_sub')) ?></p>
    </div>
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

<div class="audit-columns">
    <section class="panel-card" aria-labelledby="audit-recent-title">
        <div class="panel-card__head">
            <h2 id="audit-recent-title"><?= e(t('console.recent_audit')) ?></h2>
            <span class="admin-muted"><?= e($showingRecent) ?></span>
        </div>
        <?php if ($recent_audit === []): ?>
            <p class="admin-muted"><?= e(t('console.no_audit')) ?></p>
        <?php else: ?>
            <ul class="summary-list">
                <?php foreach ($recent_audit as $row): ?>
                    <li class="summary-list__item">
                        <span class="summary-list__main">
                            <strong><?= e((string) $row['action']) ?></strong>
                            <span class="admin-muted"><?= e((string) ($row['actor_name'] ?? t('console.system_actor'))) ?></span>
                        </span>
                        <span class="admin-muted"><?= e(format_date((string) $row['created_at'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ((int) $recent_pages > 1): ?>
                <nav class="admin-pagination" aria-label="<?= e(t('console.pagination')) ?>">
                    <?php if ($recentPage > 1): ?>
                        <a class="btn btn--secondary btn--sm" href="<?= e($recentPageUrl($recentPage - 1)) ?>">&#8592; <?= e(t('console.previous')) ?></a>
                    <?php endif; ?>
                    <span class="admin-muted"><?= e(t('console.page_of', ['page' => $recentPage, 'pages' => (int) $recent_pages])) ?></span>
                    <?php if ($recentPage < (int) $recent_pages): ?>
                        <a class="btn btn--secondary btn--sm" href="<?= e($recentPageUrl($recentPage + 1)) ?>"><?= e(t('console.next')) ?> &#8594;</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="panel-card" aria-labelledby="audit-log-title">
        <div class="panel-card__head">
            <h2 id="audit-log-title"><?= e(t('console.link_audit')) ?></h2>
            <span class="admin-muted"><?= e($showingAudit) ?></span>
        </div>
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
                        <a class="btn btn--secondary btn--sm" href="<?= e($auditPageUrl($audit['page'] - 1)) ?>">&#8592; <?= e(t('console.previous')) ?></a>
                    <?php endif; ?>
                    <span class="admin-muted"><?= e(t('console.page_of', ['page' => $audit['page'], 'pages' => $audit['pages']])) ?></span>
                    <?php if ($audit['page'] < $audit['pages']): ?>
                        <a class="btn btn--secondary btn--sm" href="<?= e($auditPageUrl($audit['page'] + 1)) ?>"><?= e(t('console.next')) ?> &#8594;</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>
