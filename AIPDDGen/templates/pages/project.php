<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $user
 * @var array<string, mixed> $project
 * @var array<string, mixed> $team
 * @var string $role
 * @var bool $canEdit
 * @var list<array<string, mixed>> $teams
 */

// Read-only AI drafting state + real pipeline progress. Tenant/role rules and the
// authoritative blocker (including the per-template SKILL.md requirement) live in
// DraftService::readiness(); ProjectAiService only reads public APIs.
$ai = \App\Services\ProjectAiService::overview((string) $user['id'], $project, (string) $role);
$conditions = $ai['conditions'];
$pipeline = $ai['pipeline'];
$latestDraft = $ai['latest_draft'];

$pipelineLabels = [
    'inputs' => t('project.pipeline_inputs'),
    'facts' => t('project.pipeline_facts'),
    'draft' => t('project.pipeline_draft'),
    'check' => t('project.pipeline_check'),
    'export' => t('project.pipeline_export'),
];

$pipelineHint = static function (array $step, string $currentKey): string {
    if (($step['future'] ?? false) === true) {
        return (string) $step['key'] === $currentKey
            ? t('project.ai_pipeline_current') . ' — ' . t('project.pipeline_coming')
            : t('project.pipeline_coming');
    }
    if ($step['done']) {
        return match ((string) $step['key']) {
            'inputs' => t('project.ai_pipeline_inputs_done', ['count' => (string) $step['detail']]),
            'facts' => t('project.ai_pipeline_facts_done', ['version' => (string) $step['detail']]),
            'draft' => t('project.ai_pipeline_draft_done', ['version' => (string) $step['detail']]),
            default => t('project.ai_pipeline_done'),
        };
    }

    return (string) $step['key'] === $currentKey
        ? t('project.ai_pipeline_current')
        : t('project.ai_pipeline_pending');
};

$registryLabel = match ($project['registry'] ?? null) {
    'gold_standard' => t('landing.registry_gs'),
    'verra_vcs' => t('landing.registry_vcs'),
    null => t('project.not_set'),
    default => (string) $project['registry'],
};

$typeLabel = $project['project_type'] !== null
    ? ucwords(str_replace('_', ' ', (string) $project['project_type']))
    : t('project.not_set');
?>
<p class="mb-2">
    <a class="btn btn--ghost btn--sm" href="<?= e(url('/dashboard')) ?>">← <?= e(t('project.back')) ?></a>
</p>

<div class="app-head">
    <div>
        <h1><?= e((string) $project['name']) ?></h1>
        <?php if (($project['description'] ?? '') !== '' && $project['description'] !== null): ?>
            <p class="app-head__sub"><?= e((string) $project['description']) ?></p>
        <?php endif; ?>
    </div>
    <div class="flex items-center gap-1 flex-wrap">
        <span class="badge <?= $project['status'] === 'setup' ? 'badge--neutral' : '' ?>">
            <?= e(t(status_key((string) $project['status']))) ?>
        </span>
        <span class="badge badge--draft"><?= e(t('common.draft_badge')) ?></span>
    </div>
</div>

<div class="page-grid">
    <div class="stack">
        <section class="card" aria-labelledby="project-ai-title">
            <h2 class="panel-title" id="project-ai-title"><?= e(t('project.ai_title')) ?></h2>
            <p class="small muted"><?= e(t('project.ai_intro')) ?></p>

            <ul class="summary-list ai-readiness" aria-label="<?= e(t('project.ai_readiness_title')) ?>">
                <li class="summary-list__item">
                    <span class="summary-list__main">
                        <strong><?= e(t('project.ai_condition_engine')) ?></strong>
                        <?php if (!$conditions['engine']['ok']): ?>
                            <span class="admin-muted"><?= e(t('project.ai_condition_engine_fix')) ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="badge <?= $conditions['engine']['ok'] ? '' : 'badge--neutral' ?>">
                        <?= e($conditions['engine']['ok'] ? t('project.ai_condition_ok') : t('project.ai_condition_blocked')) ?>
                    </span>
                </li>
                <li class="summary-list__item">
                    <span class="summary-list__main">
                        <strong><?= e(t('project.ai_condition_template')) ?></strong>
                        <?php if (!$conditions['template']['ok']): ?>
                            <span class="admin-muted"><?= e(t('project.ai_condition_template_fix')) ?></span>
                        <?php elseif ($conditions['template']['label'] !== null): ?>
                            <span class="admin-muted"><?= e((string) $conditions['template']['label']) ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="badge <?= $conditions['template']['ok'] ? '' : 'badge--neutral' ?>">
                        <?= e($conditions['template']['ok'] ? t('project.ai_condition_ok') : t('project.ai_condition_blocked')) ?>
                    </span>
                </li>
                <li class="summary-list__item">
                    <span class="summary-list__main">
                        <strong><?= e(t('project.ai_condition_fact_sheet')) ?></strong>
                        <?php if (!$conditions['fact_sheet']['ok']): ?>
                            <span class="admin-muted"><?= e(t('project.ai_condition_fact_sheet_fix')) ?></span>
                        <?php else: ?>
                            <span class="admin-muted">
                                <?= e(t('project.ai_condition_fact_sheet_version', ['version' => (string) $conditions['fact_sheet']['version']])) ?>
                            </span>
                        <?php endif; ?>
                    </span>
                    <span class="badge <?= $conditions['fact_sheet']['ok'] ? '' : 'badge--neutral' ?>">
                        <?= e($conditions['fact_sheet']['ok'] ? t('project.ai_condition_ok') : t('project.ai_condition_blocked')) ?>
                    </span>
                </li>
            </ul>

            <?php if ($ai['allowed']): ?>
                <div class="alert alert--success" role="status"><?= e(t('project.ai_ready')) ?></div>
            <?php else: ?>
                <div class="alert alert--warning" role="status" id="project-ai-blocked">
                    <strong><?= e(t('project.ai_blocked_title')) ?></strong>
                    <?php if ($ai['reason_key'] !== null): ?>
                        <?= e(t((string) $ai['reason_key'], $ai['reason_params'])) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($latestDraft !== null): ?>
                <p>
                    <a class="btn btn--secondary btn--sm" href="<?= e(url('/drafts/' . (string) $latestDraft['id'])) ?>">
                        <?= e(t('project.draft_latest', ['version' => (string) $latestDraft['version']])) ?>
                    </a>
                    <span class="badge badge--draft"><?= e(t('common.draft_badge')) ?></span>
                    <span class="small muted"><?= e(format_date((string) $latestDraft['created_at'])) ?></span>
                </p>
            <?php else: ?>
                <p class="small muted"><?= e(t('project.draft_none')) ?></p>
            <?php endif; ?>

            <?php if ($ai['can_edit']): ?>
                <form class="inline-form" method="post" action="<?= e(url('/projects/' . (string) $project['id'] . '/draft/generate')) ?>">
                    <?= csrf_field() ?>
                    <?php if ($ai['allowed']): ?>
                        <button class="btn" type="submit"><?= e(t('project.generate_draft')) ?></button>
                    <?php else: ?>
                        <button class="btn" type="submit" disabled aria-describedby="project-ai-blocked">
                            <?= e(t('project.generate_draft')) ?>
                        </button>
                    <?php endif; ?>
                </form>
                <p class="small muted"><?= e(t('project.ai_generate_note')) ?></p>
            <?php else: ?>
                <p class="small muted mb-0"><?= e(t('project.generate_viewer_note')) ?></p>
            <?php endif; ?>

            <?php if ($ai['can_edit']): ?>
                <h3 class="panel-title"><?= e(t('project.ai_inputs_title')) ?></h3>
                <p class="small muted"><?= e(t('project.ai_inputs_intro')) ?></p>
                <form class="form" method="post" enctype="multipart/form-data"
                    action="<?= e(url('/projects/' . (string) $project['id'] . '/inputs')) ?>">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="project-input-text"><?= e(t('project.ai_input_text_label')) ?></label>
                        <textarea id="project-input-text" name="text" rows="4" maxlength="20000"
                            placeholder="<?= e(t('project.ai_input_text_placeholder')) ?>"></textarea>
                    </div>
                    <div class="field">
                        <label for="project-input-file"><?= e(t('project.ai_input_file_label')) ?></label>
                        <input id="project-input-file" type="file" name="attachment" accept=".txt,.md">
                        <span class="form__meta"><?= e(t('project.ai_input_file_hint')) ?></span>
                    </div>
                    <div class="form__actions">
                        <button class="btn btn--secondary" type="submit"><?= e(t('project.ai_input_submit')) ?></button>
                    </div>
                </form>
            <?php endif; ?>

            <h3 class="panel-title">
                <?= e(t('project.ai_inputs_latest')) ?>
                <span class="small muted"><?= e(t('project.ai_inputs_count', ['count' => (string) $ai['inputs']['count']])) ?></span>
            </h3>
            <?php if ($ai['inputs']['rows'] === []): ?>
                <p class="small muted mb-0"><?= e(t('project.ai_inputs_empty')) ?></p>
            <?php else: ?>
                <ul class="summary-list">
                    <?php foreach ($ai['inputs']['rows'] as $input): ?>
                        <li class="summary-list__item">
                            <span class="summary-list__main">
                                <strong>
                                    <?= e($input['kind'] === 'document' ? t('project.ai_input_kind_document') : t('project.ai_input_kind_text')) ?>
                                    <?php if ($input['filename'] !== null): ?>
                                        · <?= e((string) $input['filename']) ?>
                                    <?php endif; ?>
                                </strong>
                                <?php if ($input['excerpt'] !== ''): ?>
                                    <span class="admin-muted"><?= e((string) $input['excerpt']) ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="admin-muted"><?= e(format_date((string) $input['created_at'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <h3 class="panel-title"><?= e(t('project.ai_drafts_title')) ?></h3>
            <?php if ($ai['drafts'] === []): ?>
                <p class="small muted mb-0"><?= e(t('project.ai_drafts_empty')) ?></p>
            <?php else: ?>
                <ul class="summary-list">
                    <?php foreach ($ai['drafts'] as $draft): ?>
                        <li class="summary-list__item">
                            <span class="summary-list__main">
                                <a href="<?= e(url('/drafts/' . (string) $draft['id'])) ?>">
                                    <?= e(t('project.ai_draft_version', ['version' => (string) $draft['version']])) ?>
                                </a>
                                <span class="admin-muted"><?= e(format_date((string) $draft['created_at'])) ?></span>
                            </span>
                            <span class="badge badge--draft"><?= e(t('common.draft_badge')) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="card" aria-labelledby="project-pipeline-title">
            <h2 class="panel-title" id="project-pipeline-title"><?= e(t('project.pipeline_title')) ?></h2>
            <p class="small muted">
                <?= e(t('project.ai_pipeline_progress', [
                    'done' => (string) $pipeline['done'],
                    'total' => (string) $pipeline['total'],
                ])) ?>
            </p>
            <progress class="pipeline-progress" max="<?= e((string) $pipeline['total']) ?>"
                value="<?= e((string) $pipeline['done']) ?>"
                aria-label="<?= e(t('project.ai_pipeline_progress', [
                    'done' => (string) $pipeline['done'],
                    'total' => (string) $pipeline['total'],
                ])) ?>">
                <?= e((string) $pipeline['done']) ?>/<?= e((string) $pipeline['total']) ?>
            </progress>

            <ol class="pipeline-steps">
                <?php foreach ($pipeline['steps'] as $step): ?>
                    <?php
                    $stepKey = (string) $step['key'];
                    $isDone = (bool) $step['done'];
                    $isCurrent = $pipeline['current'] === $stepKey;
                    $isFuture = (bool) ($step['future'] ?? false);
                    $classes = 'pipeline-step';
                    $classes .= $isDone ? ' is-done' : '';
                    $classes .= (!$isDone && $isCurrent) ? ' is-current' : '';
                    $classes .= $isFuture ? ' is-future' : '';
                    ?>
                    <li class="<?= e($classes) ?>"<?= $isCurrent ? ' aria-current="step"' : '' ?>>
                        <span class="pipeline-step__bubble" aria-hidden="true">
                            <?= $isDone ? '&#10003;' : e((string) $step['index']) ?>
                        </span>
                        <span class="pipeline-step__body">
                            <strong class="pipeline-step__label"><?= e($pipelineLabels[$stepKey] ?? $stepKey) ?></strong>
                            <span class="pipeline-step__hint"><?= e($pipelineHint($step, (string) $pipeline['current'])) ?></span>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>

        <section class="card">
            <h2 class="panel-title"><?= e(t('dashboard.wizard_coming')) ?></h2>
            <p class="mb-0 small muted"><?= e(t('landing.step1_body')) ?></p>
        </section>
    </div>

    <aside class="stack">
        <section class="card">
            <h2 class="panel-title"><?= e(t('project.status_label')) ?></h2>
            <dl class="meta-grid">
                <div class="meta-item">
                    <dt><?= e(t('project.registry_label')) ?></dt>
                    <dd><?= e($registryLabel) ?></dd>
                </div>
                <div class="meta-item">
                    <dt><?= e(t('project.country_label')) ?></dt>
                    <dd><?= e($project['country_code'] !== null ? (string) $project['country_code'] : t('project.not_set')) ?></dd>
                </div>
                <div class="meta-item">
                    <dt><?= e(t('project.type_label')) ?></dt>
                    <dd><?= e($typeLabel) ?></dd>
                </div>
                <div class="meta-item">
                    <dt><?= e(t('project.language_label')) ?></dt>
                    <dd><?= e(strtoupper((string) $project['language'])) ?></dd>
                </div>
                <div class="meta-item">
                    <dt><?= e(t('dashboard.created')) ?></dt>
                    <dd><?= e(format_date((string) $project['created_at'])) ?></dd>
                </div>
                <div class="meta-item">
                    <dt><?= e(t('team.title')) ?></dt>
                    <dd><?= e((string) $team['name']) ?></dd>
                </div>
            </dl>
        </section>

        <section class="card">
            <h2 class="panel-title"><?= e(t('project.pipeline_facts')) ?></h2>
            <p class="mb-0 small muted">
                <?= e(t('landing.trust_citations_body')) ?>
            </p>
        </section>
    </aside>
</div>
