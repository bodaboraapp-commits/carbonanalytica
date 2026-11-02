<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $user
 * @var array{rows: list<array<string,mixed>>, total: int, page: int, pages: int} $users
 * @var int $taskBadge
 */

$filters = [
    'q' => (string) ($_GET['q'] ?? ''),
    'admin' => (string) ($_GET['admin'] ?? ''),
    'new' => (string) ($_GET['new'] ?? ''),
];
$pageUrl = static function (int $page) use ($filters): string {
    $params = array_filter([
        'q' => $filters['q'],
        'admin' => $filters['admin'],
        'new' => $filters['new'],
        'page' => $page > 1 ? $page : null,
    ]);
    return url('/admin/users') . ($params !== [] ? '?' . http_build_query($params) : '');
};
?>
<div class="admin-page-head">
    <div>
        <h1><?= e(t('console.users_title')) ?></h1>
        <p><?= e(t('console.users_sub')) ?></p>
    </div>
    <span class="admin-muted"><?= e(t('console.showing', ['from' => $users['total'] === 0 ? 0 : (($users['page'] - 1) * 20) + 1, 'to' => min($users['page'] * 20, $users['total']), 'total' => $users['total']])) ?></span>
</div>

<form class="admin-filters" method="get" action="<?= e(url('/admin/users')) ?>" role="search">
    <div class="admin-filters__field admin-filters__field--grow">
        <label for="user-q"><?= e(t('console.search_label')) ?></label>
        <input id="user-q" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= e(t('console.search_users_placeholder')) ?>">
    </div>
    <div class="admin-filters__field">
        <label for="user-admin"><?= e(t('console.table_role')) ?></label>
        <select id="user-admin" name="admin">
            <option value=""><?= e(t('console.filter_all_users')) ?></option>
            <option value="1" <?= $filters['admin'] === '1' ? 'selected' : '' ?>><?= e(t('console.filter_admin')) ?></option>
        </select>
    </div>
    <div class="admin-filters__field">
        <label for="user-new"><?= e(t('super.new_users')) ?></label>
        <select id="user-new" name="new">
            <option value=""><?= e(t('console.filter_all_users')) ?></option>
            <option value="1" <?= $filters['new'] === '1' ? 'selected' : '' ?>><?= e(t('super.last_7_days')) ?></option>
        </select>
    </div>
    <div class="admin-filters__actions">
        <button class="btn btn--sm" type="submit"><?= e(t('console.apply')) ?></button>
        <a class="btn btn--secondary btn--sm" href="<?= e(url('/admin/users')) ?>"><?= e(t('console.clear')) ?></a>
    </div>
</form>

<section class="panel-card">
    <?php if ($users['rows'] === []): ?>
        <div class="admin-empty"><p class="admin-empty__title"><?= e(t('console.no_users')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data admin-table">
                <caption class="visually-hidden"><?= e(t('console.users_title')) ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('console.table_user')) ?></th>
                        <th scope="col"><?= e(t('console.table_teams')) ?></th>
                        <th scope="col"><?= e(t('console.table_role')) ?></th>
                        <th scope="col"><?= e(t('console.table_created')) ?></th>
                        <th scope="col"><?= e(t('console.table_signin')) ?></th>
                        <th scope="col"><span class="visually-hidden"><?= e(t('console.table_actions')) ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users['rows'] as $row): ?>
                        <?php $isAdmin = (int) $row['is_platform_admin'] === 1; $isSelf = (string) $row['id'] === (string) $user['id']; ?>
                        <tr>
                            <td>
                                <strong class="admin-task-title"><?= e((string) $row['name']) ?></strong>
                                <span class="admin-task-meta"><?= e((string) $row['email']) ?></span>
                            </td>
                            <td><?= e((string) $row['teams']) ?></td>
                            <td>
                                <span class="badge <?= $isAdmin ? '' : 'badge--neutral' ?>">
                                    <?= e($isAdmin ? t('console.role_platform_admin') : t('console.role_user')) ?>
                                </span>
                                <?php if ($isSelf): ?>
                                    <span class="badge badge--neutral"><?= e(t('common.you')) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="admin-muted"><?= e(format_date((string) $row['created_at'])) ?></td>
                            <td class="admin-muted"><?= e($row['last_seen_at'] !== null ? format_date((string) $row['last_seen_at']) : t('console.never')) ?></td>
                            <td>
                                <form class="inline-form" method="post" action="<?= e(url('/admin/users/' . $row['id'] . '/platform-admin')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="enabled" value="<?= $isAdmin ? '0' : '1' ?>">
                                    <button class="btn btn--sm <?= $isAdmin ? 'btn--danger' : 'btn--secondary' ?>" type="submit"
                                        <?= ($isAdmin && $isSelf) ? 'disabled title="' . e(t('console.cannot_self_demote')) . '"' : '' ?>>
                                        <?= e($isAdmin ? t('console.remove_admin') : t('console.make_admin')) ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($users['pages'] > 1): ?>
            <nav class="admin-pagination" aria-label="<?= e(t('console.pagination')) ?>">
                <?php if ($users['page'] > 1): ?>
                    <a class="btn btn--secondary btn--sm" href="<?= e($pageUrl($users['page'] - 1)) ?>">&#8592; <?= e(t('console.previous')) ?></a>
                <?php endif; ?>
                <span class="admin-muted"><?= e(t('console.page_of', ['page' => $users['page'], 'pages' => $users['pages']])) ?></span>
                <?php if ($users['page'] < $users['pages']): ?>
                    <a class="btn btn--secondary btn--sm" href="<?= e($pageUrl($users['page'] + 1)) ?>"><?= e(t('console.next')) ?> &#8594;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
