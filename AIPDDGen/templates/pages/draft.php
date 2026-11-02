<?php

declare(strict_types=1);

/**
 * Read-only draft view: sections, status, citations and needs-input markers.
 * The draft is always labelled; generated text never appears without a citation
 * because DraftService refuses uncited answers.
 *
 * @var array<string, mixed> $user
 * @var array<string, mixed> $draft
 * @var array<string, mixed> $project
 * @var list<array<string, mixed>> $sections
 * @var array<string, mixed> $template
 * @var array<string, mixed> $fact_sheet
 * @var array{generated: int, needs_input: int} $counts
 */

$registryLabel = match ($template['registry'] ?? ($project['registry'] ?? null)) {
    'gold_standard' => t('landing.registry_gs'),
    'verra_vcs' => t('landing.registry_vcs'),
    default => t('project.not_set'),
};

$statusLabel = static function (string $status): string {
    return match ($status) {
        'generated' => t('draft.status_generated'),
        'needs_input' => t('draft.status_needs_input'),
        'edited' => t('draft.status_edited'),
        'approved' => t('draft.status_approved'),
        'intentionally_blank' => t('draft.status_intentionally_blank'),
        default => $status,
    };
};

$reasonText = static function (array $content): string {
    return match ((string) ($content['reason'] ?? '')) {
        'no_facts' => t('draft.reason_no_facts'),
        'model_needs_input' => t('draft.reason_model_needs_input'),
        'invalid_json' => t('draft.reason_invalid_json'),
        'empty_content' => t('draft.reason_empty_content'),
        'missing_citation' => t('draft.reason_missing_citation'),
        'unsupported_number' => t('draft.reason_unsupported_number'),
        default => t('draft.reason_unknown'),
    };
};
?>
<p class="mb-2">
    <a class="btn btn--ghost btn--sm" href="<?= e(url('/projects/' . (string) $project['id'])) ?>">← <?= e(t('draft.back')) ?></a>
</p>

<div class="app-head">
    <div>
        <h1><?= e((string) $project['name']) ?></h1>
        <p class="app-head__sub"><?= e(t('draft.version_label', ['version' => (string) $draft['version']])) ?> · <?= e($registryLabel) ?></p>
    </div>
    <div class="flex items-center gap-1 flex-wrap">
        <span class="badge badge--draft"><?= e(t('common.draft_badge')) ?></span>
        <span class="badge badge--neutral"><?= e(t('draft.summary', [
            'generated' => (string) $counts['generated'],
            'needs_input' => (string) $counts['needs_input'],
        ])) ?></span>
    </div>
</div>

<div class="alert alert--warning" role="status">
    <strong><?= e(t('draft.banner_title')) ?></strong>
    <?= e(t('draft.banner_body')) ?>
</div>

<div class="page-grid">
    <div class="stack">
        <?php if ($sections === []): ?>
            <section class="card">
                <h2 class="panel-title"><?= e(t('draft.sections_title')) ?></h2>
                <p class="small muted mb-0"><?= e(t('draft.empty_body')) ?></p>
            </section>
        <?php else: ?>
            <?php foreach ($sections as $section): ?>
                <?php
                $status = (string) $section['status'];
                $isGenerated = $status === 'generated';
                $content = is_array($section['content'] ?? null) ? $section['content'] : [];
                $citations = is_array($section['citations_list'] ?? null) ? $section['citations_list'] : [];
                ?>
                <section class="card" aria-labelledby="section-<?= e((string) $section['id']) ?>">
                    <div class="flex items-center gap-1 flex-wrap">
                        <h2 id="section-<?= e((string) $section['id']) ?>" class="panel-title">
                            <?= e((string) $section['heading']) ?>
                        </h2>
                        <span class="badge <?= $isGenerated ? 'badge--normal' : 'badge--draft' ?>">
                            <?= e($statusLabel($status)) ?>
                        </span>
                    </div>

                    <?php if ($isGenerated): ?>
                        <div>
                            <?= nl2br(e((string) ($section['content_text'] ?? ''))) ?>
                        </div>

                        <?php if ($citations !== []): ?>
                            <?php
                            $labels = [];
                            foreach ($citations as $citation) {
                                $label = (string) ($citation['label'] ?? '');
                                $key = (string) ($citation['factKey'] ?? '');
                                $labels[] = $label !== ''
                                    ? $label . ($key !== '' ? ' (' . $key . ')' : '')
                                    : (string) ($citation['factId'] ?? '');
                            }
                            ?>
                            <p class="small muted mb-0">
                                <strong><?= e(t('draft.citations_title')) ?>:</strong>
                                <?= e(implode(' · ', $labels)) ?>
                            </p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="alert alert--warning mb-0" role="status">
                            <strong><?= e(t('draft.needs_input_prefix')) ?></strong>
                            <?= e($reasonText($content)) ?>
                            <?php if ((string) ($content['note'] ?? '') !== ''): ?>
                                <span class="small"><?= e((string) $content['note']) ?></span>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <aside class="stack">
        <section class="card" aria-labelledby="draft-meta-title">
            <h2 class="panel-title" id="draft-meta-title"><?= e(t('draft.meta_title')) ?></h2>
            <dl class="meta-grid">
                <div class="meta-item">
                    <dt><?= e(t('draft.summary_label')) ?></dt>
                    <dd><?= e(t('draft.summary', [
                        'generated' => (string) $counts['generated'],
                        'needs_input' => (string) $counts['needs_input'],
                    ])) ?></dd>
                </div>
                <div class="meta-item">
                    <dt><?= e(t('draft.template')) ?></dt>
                    <dd><?= e((string) ($template['template_name'] ?? t('project.not_set'))) ?></dd>
                </div>
                <div class="meta-item">
                    <dt><?= e(t('draft.template_version')) ?></dt>
                    <dd><?= e((string) ($template['version_label'] ?? t('project.not_set'))) ?></dd>
                </div>
                <div class="meta-item">
                    <dt><?= e(t('draft.fact_sheet')) ?></dt>
                    <dd>
                        <?= e(t('draft.fact_sheet_version', ['version' => (string) ($fact_sheet['version'] ?? '?')])) ?>
                        <?php if (($fact_sheet['approved_at'] ?? null) !== null): ?>
                            · <?= e(format_date((string) $fact_sheet['approved_at'])) ?>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="meta-item">
                    <dt><?= e(t('draft.language')) ?></dt>
                    <dd><?= e(strtoupper((string) $draft['language'])) ?></dd>
                </div>
                <div class="meta-item">
                    <dt><?= e(t('draft.generated_at')) ?></dt>
                    <dd><?= e(format_date((string) $draft['created_at'])) ?></dd>
                </div>
            </dl>
            <?php if ((string) $draft['language'] === 'fr'): ?>
                <p class="small muted mb-0"><?= e(t('draft.french_note')) ?></p>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2 class="panel-title"><?= e(t('project.pipeline_check')) ?></h2>
            <p class="small muted mb-0"><?= e(t('draft.review_note')) ?></p>
        </section>
    </aside>
</div>
