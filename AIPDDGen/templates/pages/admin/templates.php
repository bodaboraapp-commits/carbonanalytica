<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $user
 * @var array{rows: list<array<string,mixed>>, counts: array<string,int>} $templates
 * @var int $taskBadge
 */

$counts = $templates['counts'];
$badge = static function (string $status): string {
    return match ($status) {
        'published' => '',
        'retired' => 'badge--urgent',
        default => 'badge--neutral',
    };
};
$registryLabel = static function (?string $registry): string {
    return match ($registry) {
        'gold_standard' => t('landing.registry_gs'),
        'verra_vcs' => t('landing.registry_vcs'),
        null => t('console.registry_any'),
        default => (string) $registry,
    };
};
?>
<div class="admin-page-head">
    <div>
        <h1><?= e(t('console.templates_title')) ?></h1>
        <p><?= e(t('console.templates_sub')) ?></p>
    </div>
</div>

<section class="stat-grid stat-grid--3" aria-labelledby="templates-kpi-title">
    <h2 class="visually-hidden" id="templates-kpi-title"><?= e(t('console.templates_title')) ?></h2>
    <?php \App\View::partial('partials/stat-card', ['label' => t('console.template_status_published'), 'value' => (string) ($counts['published'] ?? 0), 'icon' => 'facts', 'tone' => 'green']); ?>
    <?php \App\View::partial('partials/stat-card', ['label' => t('console.template_status_draft'), 'value' => (string) ($counts['draft'] ?? 0), 'icon' => 'drafts', 'tone' => 'gold']); ?>
    <?php \App\View::partial('partials/stat-card', ['label' => t('console.template_status_retired'), 'value' => (string) ($counts['retired'] ?? 0), 'icon' => 'archive', 'tone' => 'slate']); ?>
</section>

<section class="panel-card">
    <?php if ($templates['rows'] === []): ?>
        <div class="admin-empty"><p class="admin-empty__title"><?= e(t('console.no_templates')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data admin-table">
                <caption class="visually-hidden"><?= e(t('console.templates_title')) ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('console.table_template')) ?></th>
                        <th scope="col"><?= e(t('console.table_version')) ?></th>
                        <th scope="col"><?= e(t('console.table_registry')) ?></th>
                        <th scope="col"><?= e(t('console.table_language')) ?></th>
                        <th scope="col"><?= e(t('console.table_sections')) ?></th>
                        <th scope="col"><?= e(t('console.table_fields')) ?></th>
                        <th scope="col"><?= e(t('console.table_status')) ?></th>
                        <th scope="col"><?= e(t('console.table_manage')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($templates['rows'] as $row): ?>
                        <?php
                        $versionId = (string) $row['id'];
                        $status = (string) $row['status'];
                        $manageLabel = t('template.manage_button') . ' — ' . (string) $row['template_name'] . ' ' . (string) $row['version_label'];
                        ?>
                        <tr>
                            <td>
                                <strong class="admin-task-title"><?= e((string) $row['template_name']) ?></strong>
                                <span class="admin-task-meta"><?= e((string) $row['kind']) ?></span>
                            </td>
                            <td><?= e((string) $row['version_label']) ?></td>
                            <td><?= e($registryLabel($row['registry'] !== null ? (string) $row['registry'] : null)) ?></td>
                            <td><?= e(strtoupper((string) $row['language'])) ?></td>
                            <td><?= e((string) $row['sections']) ?></td>
                            <td><?= e((string) $row['fields']) ?></td>
                            <td><span class="badge <?= e($badge($status)) ?>"><?= e(t('console.template_status_' . $status)) ?></span></td>
                            <td>
                                <div class="template-manage">
                                    <details class="template-manage__disclosure" data-manage>
                                        <summary class="btn btn--secondary btn--sm" aria-label="<?= e($manageLabel) ?>"><?= e(t('template.manage_button')) ?></summary>
                                        <div class="template-manage__panel" data-manage-panel>
                                            <p class="template-manage__title"><?= e((string) $row['template_name']) ?> — <?= e((string) $row['version_label']) ?></p>

                                            <form class="template-manage__form" method="post" enctype="multipart/form-data"
                                                action="<?= e(url('/admin/templates/' . $versionId . '/source')) ?>">
                                                <?= csrf_field() ?>
                                                <label for="template-source-<?= e($versionId) ?>"><?= e(t('template.upload_label')) ?></label>
                                                <input id="template-source-<?= e($versionId) ?>" type="file" name="source"
                                                    accept=".pdf,.docx,.md,.txt" required>
                                                <p class="template-manage__hint"><?= e(t('template.upload_hint')) ?></p>
                                                <button class="btn btn--sm" type="submit"><?= e(t('template.upload_button')) ?></button>
                                            </form>

                                            <?php if ($status === 'draft' || $status === 'published'): ?>
                                                <form class="template-manage__form" method="post"
                                                    action="<?= e(url('/admin/templates/' . $versionId . '/status')) ?>">
                                                    <?= csrf_field() ?>
                                                    <label for="template-status-<?= e($versionId) ?>"><?= e(t('template.status_label')) ?></label>
                                                    <select id="template-status-<?= e($versionId) ?>" name="status" required>
                                                        <?php if ($status === 'draft'): ?>
                                                            <option value="published"><?= e(t('console.template_status_published')) ?></option>
                                                        <?php else: ?>
                                                            <option value="retired"><?= e(t('console.template_status_retired')) ?></option>
                                                        <?php endif; ?>
                                                    </select>
                                                    <button class="btn btn--sm" type="submit"><?= e(t('template.status_button')) ?></button>
                                                </form>
                                            <?php else: ?>
                                                <p class="template-manage__hint"><?= e(t('template.no_transition')) ?></p>
                                            <?php endif; ?>

                                            <?php if ($status === 'published'): ?>
                                                <p class="template-manage__hint"><?= e(t('template.delete_hint_published')) ?></p>
                                            <?php else: ?>
                                                <form class="template-manage__form" method="post"
                                                    action="<?= e(url('/admin/templates/' . $versionId . '/delete')) ?>">
                                                    <?= csrf_field() ?>
                                                    <label class="template-manage__check" for="template-confirm-<?= e($versionId) ?>">
                                                        <input id="template-confirm-<?= e($versionId) ?>" type="checkbox" name="confirm" value="1" required>
                                                        <span><?= e(t('template.delete_checkbox')) ?></span>
                                                    </label>
                                                    <p class="template-manage__hint"><?= e(t('template.delete_hint')) ?></p>
                                                    <button class="btn btn--danger btn--sm" type="submit"><?= e(t('template.delete_button')) ?></button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </details>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <dialog class="manage-dialog" id="template-manage-dialog" aria-labelledby="template-manage-dialog-title">
            <div class="manage-dialog__head">
                <h2 id="template-manage-dialog-title"><?= e(t('template.manage_title')) ?></h2>
                <button class="btn btn--ghost btn--sm" type="button" data-dialog-close><?= e(t('template.close')) ?></button>
            </div>
            <div class="manage-dialog__body" data-manage-host></div>
        </dialog>
    <?php endif; ?>
</section>
