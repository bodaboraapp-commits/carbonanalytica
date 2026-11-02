<?php

declare(strict_types=1);

/**
 * Workspace admin job queue: this workspace's review tasks with assign /
 * unassign / complete actions (POST → redirect → GET, so refresh-safe).
 *
 * @var array<string, mixed> $user
 * @var array<string, mixed> $team
 * @var array{rows: list<array<string,mixed>>, total: int, page: int, pages: int, filters: array<string,mixed>, summary: array<string,int>, by_work_type: list<array{work_type:string,total:int}>} $queue
 * @var int $taskBadge
 */

use App\Services\TaskCenterService;
use App\Services\WorkspaceTaskService;

$filters = $queue['filters'];
$currentQuery = $_SERVER['QUERY_STRING'] ?? '';
$returnPath = '/dashboard/tasks' . ($currentQuery !== '' ? '?' . $currentQuery : '');

/** Build a queue URL preserving the other filters. */
$queueUrl = static function (array $overrides) use ($filters): string {
    $params = [
        'scope' => $overrides['scope'] ?? ($filters['scope'] ?? 'open'),
        'type' => $overrides['type'] ?? ($filters['work_type'] ?? ''),
        'q' => $overrides['q'] ?? ($filters['q'] ?? ''),
        'page' => $overrides['page'] ?? 1,
    ];
    $params = array_filter(
        $params,
        static fn (mixed $value): bool => $value !== '' && $value !== null,
    );
    return url('/dashboard/tasks') . ($params !== [] ? '?' . http_build_query($params) : '');
};

$scopeLabels = [
    'open' => 'admin.scope_open',
    'mine' => 'admin.scope_mine',
    'unassigned' => 'admin.scope_unassigned',
    'breached' => 'admin.scope_breached',
    'completed' => 'admin.scope_completed',
    'all' => 'admin.scope_all',
];

$firstShown = $queue['total'] === 0 ? 0 : (($queue['page'] - 1) * 25) + 1;
$lastShown = min($queue['page'] * 25, $queue['total']);
?>
<div class="admin-page-head">
    <div>
        <h1><?= e(t('workspace.queue_title')) ?></h1>
        <p><?= e(t('workspace.queue_sub')) ?></p>
    </div>
    <a class="admin-link-arrow" href="<?= e(url('/dashboard')) ?>"><?= e(t('workspace.title')) ?> <span aria-hidden="true">&#8594;</span></a>
</div>

<form class="admin-filters" method="get" action="<?= e(url('/dashboard/tasks')) ?>" role="search">
    <div class="admin-filters__field">
        <label for="filter-scope"><?= e(t('admin.filter_scope')) ?></label>
        <select id="filter-scope" name="scope">
            <?php foreach ($scopeLabels as $value => $labelKey): ?>
                <option value="<?= e($value) ?>" <?= ($filters['scope'] ?? 'open') === $value ? 'selected' : '' ?>>
                    <?= e(t($labelKey)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="admin-filters__field">
        <label for="filter-type"><?= e(t('admin.filter_work_type')) ?></label>
        <select id="filter-type" name="type">
            <option value=""><?= e(t('admin.all_types')) ?></option>
            <?php foreach (WorkspaceTaskService::WORK_TYPES as $workType): ?>
                <option value="<?= e($workType) ?>" <?= ($filters['work_type'] ?? '') === $workType ? 'selected' : '' ?>>
                    <?= e(t(TaskCenterService::workTypeLabelKey($workType))) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="admin-filters__field admin-filters__field--grow">
        <label for="filter-q"><?= e(t('admin.search_label')) ?></label>
        <input id="filter-q" type="search" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="<?= e(t('admin.search_placeholder')) ?>">
    </div>

    <div class="admin-filters__actions">
        <button class="btn btn--sm" type="submit"><?= e(t('admin.apply_filters')) ?></button>
        <a class="btn btn--secondary btn--sm" href="<?= e(url('/dashboard/tasks')) ?>"><?= e(t('admin.clear_filters')) ?></a>
    </div>
</form>

<section class="panel-card" aria-labelledby="queue-title">
    <div class="panel-card__head">
        <h2 id="queue-title"><?= e(t('workspace.queue_title')) ?></h2>
        <span class="admin-muted">
            <?= e(t('admin.showing', ['from' => $firstShown, 'to' => $lastShown, 'total' => $queue['total']])) ?>
        </span>
    </div>

    <?php if ($queue['rows'] === []): ?>
        <div class="admin-empty">
            <p class="admin-empty__title"><?= e(t('workspace.queue_empty_title')) ?></p>
            <p class="admin-empty__body"><?= e(t('workspace.queue_empty_body')) ?></p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data admin-table">
                <caption class="visually-hidden"><?= e(t('workspace.queue_title')) ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('admin.task')) ?></th>
                        <th scope="col"><?= e(t('admin.work_type')) ?></th>
                        <th scope="col"><?= e(t('admin.priority')) ?></th>
                        <th scope="col"><?= e(t('admin.status')) ?></th>
                        <th scope="col"><?= e(t('admin.assignee')) ?></th>
                        <th scope="col"><?= e(t('admin.due')) ?></th>
                        <th scope="col"><?= e(t('admin.actions')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($queue['rows'] as $task): ?>
                        <?php
                        $status = (string) $task['status'];
                        $isOpen = in_array($status, ['unassigned', 'in_progress', 'blocked'], true);
                        $dueAt = $task['due_at'] !== null ? strtotime((string) $task['due_at']) : null;
                        $breached = $isOpen && $dueAt !== null && $dueAt < time();
                        $assignedToMe = (string) ($task['assigned_user_id'] ?? '') === (string) $user['id'];
                        ?>
                        <tr class="<?= $breached ? 'admin-row--breached' : '' ?>">
                            <td>
                                <strong class="admin-task-title"><?= e((string) $task['title']) ?></strong>
                                <span class="admin-task-meta">
                                    <?= e((string) ($task['team_name'] ?? '')) ?>
                                    · <?= e(format_date((string) $task['created_at'])) ?>
                                </span>
                            </td>
                            <td><?= e(t(TaskCenterService::workTypeLabelKey((string) $task['work_type']))) ?></td>
                            <td>
                                <span class="badge badge--<?= e((string) $task['priority']) ?>">
                                    <?= e(t(TaskCenterService::priorityLabelKey((string) $task['priority']))) ?>
                                </span>
                            </td>
                            <td><?= e(t(TaskCenterService::statusLabelKey($status))) ?></td>
                            <td><?= e((string) ($task['assignee_name'] ?? t('admin.unassigned_value'))) ?></td>
                            <td>
                                <?php if ($dueAt === null): ?>
                                    <span class="admin-muted"><?= e(t('admin.no_due')) ?></span>
                                <?php else: ?>
                                    <?= e(format_date((string) $task['due_at'])) ?>
                                    <?php if ($breached): ?>
                                        <span class="badge badge--urgent"><?= e(t('admin.breach_badge')) ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$isOpen): ?>
                                    <span class="admin-muted"><?= e(t('admin.status_completed')) ?></span>
                                <?php else: ?>
                                    <div class="table-actions">
                                        <?php if (!$assignedToMe): ?>
                                            <form class="inline-form" method="post" action="<?= e(url('/dashboard/tasks/' . $task['id'] . '/assign')) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="return" value="<?= e($returnPath) ?>">
                                                <button class="btn btn--secondary btn--sm" type="submit"><?= e(t('admin.assign_to_me')) ?></button>
                                            </form>
                                        <?php else: ?>
                                            <form class="inline-form" method="post" action="<?= e(url('/dashboard/tasks/' . $task['id'] . '/unassign')) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="return" value="<?= e($returnPath) ?>">
                                                <button class="btn btn--ghost btn--sm" type="submit"><?= e(t('admin.unassign')) ?></button>
                                            </form>
                                        <?php endif; ?>
                                        <form class="inline-form" method="post" action="<?= e(url('/dashboard/tasks/' . $task['id'] . '/complete')) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="return" value="<?= e($returnPath) ?>">
                                            <button class="btn btn--sm" type="submit"><?= e(t('admin.mark_complete')) ?></button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($queue['pages'] > 1): ?>
            <nav class="admin-pagination" aria-label="<?= e(t('admin.pagination')) ?>">
                <?php if ($queue['page'] > 1): ?>
                    <a class="btn btn--secondary btn--sm" href="<?= e($queueUrl(['page' => $queue['page'] - 1])) ?>">&#8592; <?= e(t('admin.previous')) ?></a>
                <?php endif; ?>
                <span class="admin-muted"><?= e(t('admin.page_of', ['page' => $queue['page'], 'pages' => $queue['pages']])) ?></span>
                <?php if ($queue['page'] < $queue['pages']): ?>
                    <a class="btn btn--secondary btn--sm" href="<?= e($queueUrl(['page' => $queue['page'] + 1])) ?>"><?= e(t('admin.next')) ?> &#8594;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
