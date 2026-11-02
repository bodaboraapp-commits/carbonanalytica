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

$pipeline = [
    ['key' => 'inputs', 'label' => t('project.pipeline_inputs'), 'done' => false],
    ['key' => 'facts', 'label' => t('project.pipeline_facts'), 'done' => false],
    ['key' => 'draft', 'label' => t('project.pipeline_draft'), 'done' => false],
    ['key' => 'check', 'label' => t('project.pipeline_check'), 'done' => false],
    ['key' => 'export', 'label' => t('project.pipeline_export'), 'done' => false],
];

$registryLabel = match ($project['registry'] ?? null) {
    'gold_standard' => t('landing.registry_gs'),
    'verra_vcs' => t('landing.registry_vcs'),
    null => t('project.not_set'),
    default => (string) $project['registry'],
};

$typeLabel = $project['project_type'] !== null
    ? ucwords(str_replace('_', ' ', (string) $project['project_type']))
    : t('project.not_set');

// Read-only drafting state: whether generation is allowed right now, why not,
// and the project's latest draft. The rules live in DraftService; the platform's
// PageController only passes the project and the caller's role.
$draftState = \App\Services\DraftService::uiState((string) $user['id'], $project, (string) $role);
$latestDraft = $draftState['latest_draft'];
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
        <section class="card" aria-labelledby="project-draft-title">
            <h2 class="panel-title" id="project-draft-title"><?= e(t('project.draft_title')) ?></h2>
            <p class="small muted"><?= e(t('project.draft_intro')) ?></p>

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

            <?php if ($draftState['can_edit']): ?>
                <form class="inline-form" method="post" action="<?= e(url('/projects/' . (string) $project['id'] . '/draft/generate')) ?>">
                    <?= csrf_field() ?>
                    <?php if ($draftState['allowed']): ?>
                        <button class="btn" type="submit"><?= e(t('project.generate_draft')) ?></button>
                    <?php else: ?>
                        <button class="btn" type="submit" disabled aria-describedby="draft-generate-reason">
                            <?= e(t('project.generate_draft')) ?>
                        </button>
                    <?php endif; ?>
                </form>
                <?php if (!$draftState['allowed'] && $draftState['reason_key'] !== null): ?>
                    <p class="small muted" id="draft-generate-reason">
                        <?= e(t((string) $draftState['reason_key'], $draftState['reason_params'])) ?>
                    </p>
                <?php endif; ?>
            <?php else: ?>
                <p class="small muted mb-0"><?= e(t('project.generate_viewer_note')) ?></p>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2 class="panel-title"><?= e(t('project.pipeline_title')) ?></h2>
            <div class="pipeline">
                <?php foreach ($pipeline as $index => $step): ?>
                    <div class="pipeline__item">
                        <span class="pipeline__num" aria-hidden="true"><?= e((string) ($index + 1)) ?></span>
                        <div class="pipeline__body">
                            <strong><?= e($step['label']) ?></strong>
                            <span><?= e(t('project.pipeline_coming')) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
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
