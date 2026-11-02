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
                        <th scope="col"><?= e(t('console.table_published')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($templates['rows'] as $row): ?>
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
                            <td><span class="badge <?= e($badge((string) $row['status'])) ?>"><?= e(t('console.template_status_' . (string) $row['status'])) ?></span></td>
                            <td class="admin-muted"><?= e($row['published_at'] !== null ? format_date((string) $row['published_at']) : t('console.not_published')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
