<?php

declare(strict_types=1);

/**
 * Template library (staff only): every template version with its source,
 * publication state and the per-version SKILL.md requirement. New template
 * versions are created from the "Add template" panel; a version starts with a
 * PENDING SKILL.md, which blocks drafting until an admin uploads it.
 *
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
$skillBadge = static function (?string $skillStatus): string {
    return match ($skillStatus) {
        \App\Services\TemplateLibraryService::SKILL_STATUS_READY => 'badge--normal',
        \App\Services\TemplateLibraryService::SKILL_STATUS_PENDING => 'badge--high',
        default => 'badge--neutral',
    };
};
$skillLabel = static function (?string $skillStatus): string {
    return match ($skillStatus) {
        \App\Services\TemplateLibraryService::SKILL_STATUS_READY => t('template.skill_ready'),
        \App\Services\TemplateLibraryService::SKILL_STATUS_PENDING => t('template.skill_pending'),
        default => t('ai.skill_missing'),
    };
};
?>
<div class="admin-page-head">
    <div>
        <h1><?= e(t('console.templates_title')) ?></h1>
        <p><?= e(t('console.templates_sub')) ?></p>
    </div>
    <details class="template-add">
        <summary class="btn btn--secondary btn--sm"><?= e(t('template.add_title')) ?></summary>
        <div class="template-add__body">
            <h2 class="template-add__title"><?= e(t('template.add_title')) ?></h2>
            <p class="admin-muted small"><?= e(t('template.add_sub')) ?></p>
            <form class="form" method="post" action="<?= e(url('/admin/templates')) ?>" enctype="multipart/form-data" novalidate>
                <?= csrf_field() ?>
                <div class="template-add__grid">
                    <div class="field">
                        <label for="template-add-name"><?= e(t('template.add_name')) ?></label>
                        <input id="template-add-name" name="name" type="text" maxlength="200" required>
                    </div>
                    <div class="field">
                        <label for="template-add-registry"><?= e(t('template.add_registry')) ?></label>
                        <select id="template-add-registry" name="registry" required>
                            <?php foreach (\App\Services\TemplateLibraryService::TEMPLATE_REGISTRIES as $registrySlug): ?>
                                <option value="<?= e($registrySlug) ?>"><?= e($registryLabel($registrySlug)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="template-add-language"><?= e(t('template.add_language')) ?></label>
                        <select id="template-add-language" name="language">
                            <?php foreach (\App\Services\TemplateLibraryService::TEMPLATE_LANGUAGES as $languageSlug): ?>
                                <option value="<?= e($languageSlug) ?>"><?= e(strtoupper($languageSlug)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="template-add-version"><?= e(t('template.add_version_label')) ?></label>
                        <input id="template-add-version" name="version_label" type="text" maxlength="40" value="v1.0">
                    </div>
                    <div class="field">
                        <label for="template-add-methodology"><?= e(t('template.add_methodology')) ?></label>
                        <input id="template-add-methodology" name="methodology_code" type="text" maxlength="40">
                    </div>
                    <div class="field">
                        <label for="template-add-source"><?= e(t('template.add_source')) ?></label>
                        <input id="template-add-source" name="source" type="file" accept=".pdf,.docx,.md,.txt" required>
                    </div>
                    <div class="field template-add__wide">
                        <label for="template-add-description"><?= e(t('template.add_description')) ?></label>
                        <textarea id="template-add-description" name="description" rows="2" maxlength="2000"></textarea>
                    </div>
                </div>
                <div class="form__actions">
                    <button class="btn" type="submit"><?= e(t('template.add_submit')) ?></button>
                </div>
            </form>
        </div>
    </details>
</div>

<section class="stat-grid stat-grid--3" aria-labelledby="templates-kpi-title">
    <h2 class="visually-hidden" id="templates-kpi-title"><?= e(t('console.templates_title')) ?></h2>
    <?php \App\View::partial('partials/stat-card', ['label' => t('console.template_status_published'), 'value' => (string) ($counts['published'] ?? 0), 'icon' => 'facts', 'tone' => 'green']); ?>
    <?php \App\View::partial('partials/stat-card', ['label' => t('console.template_status_draft'), 'value' => (string) ($counts['draft'] ?? 0), 'icon' => 'drafts', 'tone' => 'gold']); ?>
    <?php \App\View::partial('partials/stat-card', ['label' => t('console.template_status_retired'), 'value' => (string) ($counts['retired'] ?? 0), 'icon' => 'archive', 'tone' => 'slate']); ?>
</section>

<section class="panel-card">
    <?php if ($templates['rows'] === []): ?>
        <div class="admin-empty">
            <p class="admin-empty__title"><?= e(t('console.no_templates')) ?></p>
            <p class="admin-empty__body"><?= e(t('template.add_sub')) ?></p>
        </div>
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
                        <th scope="col"><?= e(t('template.skill_title')) ?></th>
                        <th scope="col"><?= e(t('console.table_manage')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($templates['rows'] as $row): ?>
                        <?php
                        $versionId = (string) $row['id'];
                        $status = (string) $row['status'];
                        $skillStatus = \App\Services\TemplateLibraryService::skillStatusForVersion($versionId);
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
                            <td class="template-skill-cell">
                                <span class="badge <?= e($skillBadge($skillStatus)) ?>"><?= e($skillLabel($skillStatus)) ?></span>
                            </td>
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

                                            <div class="template-manage__skill">
                                                <p class="template-manage__title">
                                                    <?= e(t('template.skill_title')) ?> —
                                                    <span class="badge <?= e($skillBadge($skillStatus)) ?>"><?= e($skillLabel($skillStatus)) ?></span>
                                                </p>
                                                <form class="template-manage__form" method="post" enctype="multipart/form-data"
                                                    action="<?= e(url('/admin/templates/' . $versionId . '/skill')) ?>">
                                                    <?= csrf_field() ?>
                                                    <label for="template-skill-<?= e($versionId) ?>"><?= e(t('template.skill_upload_label')) ?></label>
                                                    <input id="template-skill-<?= e($versionId) ?>" type="file" name="skill"
                                                        accept=".md,text/markdown" required>
                                                    <p class="template-manage__hint"><?= e(t('template.skill_hint')) ?></p>
                                                    <button class="btn btn--sm" type="submit">
                                                        <?= e($skillStatus === \App\Services\TemplateLibraryService::SKILL_STATUS_READY ? t('ai.skill_upload_replace') : t('template.skill_upload_button')) ?>
                                                    </button>
                                                </form>
                                            </div>

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
