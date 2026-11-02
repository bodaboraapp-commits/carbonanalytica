<?php

declare(strict_types=1);

/**
 * Workspace dashboard — tenant-facing Action Center.
 *
 * The same attention/pipeline/KPI layout as the staff console, but every number
 * comes from WorkspaceDashboardService and is scoped to the current workspace.
 *
 * @var array<string, mixed> $user
 * @var array<string, mixed> $team
 * @var list<array<string, mixed>> $teams
 * @var list<array<string, mixed>> $projects
 * @var bool $canEdit
 * @var array<string, mixed> $organization
 * @var array<string, mixed> $workspace
 * @var array<string, string> $errors
 */

$orgStatus = (string) ($organization['status'] ?? 'draft');
$orgDone = in_array($orgStatus, ['submitted', 'verified'], true);
$orgStepsDone = 0;
foreach ([1, 2, 3] as $orgStep) {
    if (!empty($organization['completion'][$orgStep])) {
        $orgStepsDone++;
    }
}

$metrics = $workspace['metrics'];
$attention = $workspace['attention'];
$canManageMembers = (bool) $workspace['can_manage_members'];
?>
<div class="admin-page-head">
    <div>
        <h1><?= e(t('workspace.title')) ?></h1>
        <p><?= e(t('workspace.sub')) ?></p>
    </div>
    <span class="workspace-pill">
        <strong><?= e(t('dashboard.workspace_label')) ?>:</strong>
        <?= e((string) $team['name']) ?>
        <span class="badge badge--neutral"><?= e(t('dashboard.plan_free')) ?></span>
    </span>
</div>

<?php if (!$orgDone && !empty($organization['can_edit'])): ?>
    <section class="card org-prompt" aria-labelledby="org-prompt-title">
        <div class="org-prompt__body">
            <h2 class="org-prompt__title" id="org-prompt-title"><?= e(t('org.prompt_title')) ?></h2>
            <p class="muted mb-0"><?= e(t('org.prompt_body')) ?></p>
            <div class="org-prompt__progress">
                <progress class="progress" max="3" value="<?= e((string) $orgStepsDone) ?>">
                    <?= e((string) $orgStepsDone) ?> / 3
                </progress>
                <span class="small muted"><?= e(t('org.prompt_steps', ['done' => $orgStepsDone])) ?></span>
            </div>
        </div>
        <a class="btn btn--secondary btn--sm" href="<?= e(url('/onboarding')) ?>"><?= e(t('org.prompt_action')) ?></a>
    </section>
<?php elseif ($orgStatus === 'submitted'): ?>
    <div class="alert alert--info" role="status"><?= e(t('org.prompt_submitted')) ?></div>
<?php endif; ?>

<section class="action-card" aria-labelledby="workspace-attention-title">
    <h2 class="visually-hidden" id="workspace-attention-title"><?= e(t('workspace.action_title')) ?></h2>

    <div class="attention-grid">
        <a class="attention-tile attention-tile--mine" href="<?= e(url('/dashboard') . '#projects') ?>">
            <span class="attention-tile__label"><?= e(t('workspace.projects_setup')) ?></span>
            <span class="attention-tile__value"><?= e((string) $attention['setup']) ?></span>
            <span class="attention-tile__hint"><?= e(t('workspace.projects_setup_hint')) ?></span>
        </a>
        <a class="attention-tile attention-tile--unassigned" href="<?= e(url('/dashboard') . '#projects') ?>">
            <span class="attention-tile__label"><?= e(t('workspace.fact_sheets')) ?></span>
            <span class="attention-tile__value"><?= e((string) $attention['fact_sheets']) ?></span>
            <span class="attention-tile__hint"><?= e(t('workspace.fact_sheets_hint')) ?></span>
        </a>
        <a class="attention-tile attention-tile--breached" href="<?= e(url('/dashboard') . '#projects') ?>">
            <span class="attention-tile__label"><?= e(t('workspace.drafts_review')) ?></span>
            <span class="attention-tile__value"><?= e((string) $attention['drafts']) ?></span>
            <span class="attention-tile__hint"><?= e(t('workspace.drafts_review_hint')) ?></span>
        </a>
    </div>

    <h2 class="panel-title"><?= e(t('workspace.by_status')) ?></h2>
    <?php if ($workspace['by_status'] === []): ?>
        <p class="admin-empty-line"><?= e(t('workspace.no_projects')) ?></p>
    <?php else: ?>
        <ul class="worktype-list">
            <?php foreach ($workspace['by_status'] as $row): ?>
                <li>
                    <a class="worktype-row" href="<?= e(url('/dashboard') . '#projects') ?>">
                        <span class="worktype-row__name"><?= e(t(status_key($row['status']))) ?></span>
                        <span class="worktype-row__count"><?= e((string) $row['total']) ?></span>
                        <span class="worktype-row__arrow" aria-hidden="true">&#8594;</span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($attention['setup'] > 0): ?>
        <a class="sla-alert" href="<?= e(url('/dashboard') . '#projects') ?>">
            <span class="sla-alert__icon" aria-hidden="true">&#9873;</span>
            <span><?= e(t('workspace.alerts_setup', ['count' => $attention['setup']])) ?></span>
            <span class="sla-alert__arrow" aria-hidden="true">&#8594;</span>
        </a>
    <?php endif; ?>
</section>

<section class="panel-card" aria-labelledby="workspace-pipeline-title">
    <div class="panel-card__head">
        <h2 id="workspace-pipeline-title"><?= e(t('workspace.pipeline_title')) ?></h2>
        <a class="admin-link-arrow" href="<?= e(url('/dashboard') . '#projects') ?>"><?= e(t('workspace.link_projects')) ?> <span aria-hidden="true">&#8594;</span></a>
    </div>
    <div class="pipeline-cards">
        <article class="pipeline-card pipeline-card--blue">
            <span class="pipeline-card__icon" aria-hidden="true">&#9998;</span>
            <span class="pipeline-card__label"><?= e(t('workspace.pipeline_projects')) ?></span>
            <span class="pipeline-card__value"><?= e((string) $metrics['projects']) ?></span>
            <span class="pipeline-card__hint"><?= e(t('workspace.pipeline_projects_hint')) ?></span>
        </article>
        <article class="pipeline-card pipeline-card--green">
            <span class="pipeline-card__icon" aria-hidden="true">&#9993;</span>
            <span class="pipeline-card__label"><?= e(t('workspace.pipeline_fact_sheets')) ?></span>
            <span class="pipeline-card__value"><?= e((string) $metrics['fact_sheets_pending']) ?></span>
            <span class="pipeline-card__hint"><?= e(t('workspace.pipeline_fact_sheets_hint')) ?></span>
        </article>
        <article class="pipeline-card pipeline-card--gold">
            <span class="pipeline-card__icon" aria-hidden="true">&#128196;</span>
            <span class="pipeline-card__label"><?= e(t('workspace.pipeline_drafts')) ?></span>
            <span class="pipeline-card__value"><?= e((string) $metrics['drafts_in_review']) ?></span>
            <span class="pipeline-card__hint"><?= e(t('workspace.pipeline_drafts_hint')) ?></span>
        </article>
        <article class="pipeline-card pipeline-card--slate">
            <span class="pipeline-card__icon" aria-hidden="true">&#128193;</span>
            <span class="pipeline-card__label"><?= e(t('workspace.pipeline_documents')) ?></span>
            <span class="pipeline-card__value"><?= e((string) $metrics['documents']) ?></span>
            <span class="pipeline-card__hint"><?= e(t('workspace.pipeline_documents_hint')) ?></span>
        </article>
    </div>
</section>

<section class="kpi-grid" aria-labelledby="workspace-kpi-title">
    <h2 class="visually-hidden" id="workspace-kpi-title"><?= e(t('workspace.kpi_title')) ?></h2>
    <article class="kpi-card">
        <span class="kpi-card__icon kpi-card__icon--green" aria-hidden="true">&#128101;</span>
        <span class="kpi-card__label"><?= e(t('workspace.kpi_members')) ?></span>
        <span class="kpi-card__value"><?= e((string) $metrics['members']) ?></span>
    </article>
    <article class="kpi-card">
        <span class="kpi-card__icon kpi-card__icon--orange" aria-hidden="true">&#128200;</span>
        <span class="kpi-card__label"><?= e(t('workspace.kpi_projects')) ?></span>
        <span class="kpi-card__value"><?= e((string) $metrics['projects']) ?></span>
    </article>
    <article class="kpi-card">
        <span class="kpi-card__icon kpi-card__icon--blue" aria-hidden="true">&#128203;</span>
        <span class="kpi-card__label"><?= e(t('workspace.kpi_drafts')) ?></span>
        <span class="kpi-card__value"><?= e((string) $metrics['drafts_total']) ?></span>
    </article>
    <article class="kpi-card">
        <span class="kpi-card__icon kpi-card__icon--gold" aria-hidden="true">&#10003;</span>
        <span class="kpi-card__label"><?= e(t('workspace.kpi_facts')) ?></span>
        <span class="kpi-card__value"><?= e((string) $metrics['facts_confirmed']) ?></span>
    </article>
</section>

<div class="app-head" id="projects">
    <div>
        <h2 class="mb-0"><?= e(t('workspace.projects_title')) ?></h2>
        <p class="app-head__sub"><?= e(t('workspace.projects_sub')) ?></p>
    </div>
</div>

<div class="page-grid">
    <div class="stack">
        <?php if ($projects === []): ?>
            <div class="empty-state">
                <h2><?= e(t('dashboard.empty_title')) ?></h2>
                <p><?= e(t('dashboard.empty_body')) ?></p>
            </div>
        <?php else: ?>
            <div class="project-list">
                <?php foreach ($projects as $project): ?>
                    <a class="project-card" href="<?= e(url('/projects/' . $project['id'])) ?>">
                        <div>
                            <div class="project-card__title"><?= e((string) $project['name']) ?></div>
                            <div class="project-card__meta">
                                <span><?= e($project['description'] !== null && $project['description'] !== '' ? mb_strimwidth((string) $project['description'], 0, 90, '…') : t('project.not_set')) ?></span>
                            </div>
                        </div>
                        <div class="flex items-center gap-1 flex-wrap">
                            <span class="badge <?= $project['status'] === 'setup' ? 'badge--neutral' : '' ?>">
                                <?= e(t(status_key((string) $project['status']))) ?>
                            </span>
                            <span class="badge badge--neutral"><?= e(format_date((string) $project['created_at'])) ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <aside class="stack">
        <?php if ($canEdit): ?>
            <section class="card" aria-labelledby="new-project-title">
                <h2 class="panel-title" id="new-project-title"><?= e(t('dashboard.new_project')) ?></h2>

                <?php if ($errors !== []): ?>
                    <div class="alert alert--error" role="alert"><?= e(t('error.validation_error')) ?></div>
                <?php endif; ?>

                <form class="form" method="post" action="<?= e(url('/projects')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="team_id" value="<?= e((string) $team['id']) ?>">

                    <div class="field" data-invalid="<?= isset($errors['name']) ? 'true' : 'false' ?>">
                        <label for="project-name"><?= e(t('dashboard.project_name')) ?></label>
                        <input
                            id="project-name"
                            name="name"
                            type="text"
                            required
                            maxlength="200"
                            placeholder="<?= e(t('dashboard.project_name_placeholder')) ?>"
                            value="<?= e(old('name')) ?>"
                            <?= isset($errors['name']) ? 'aria-invalid="true" aria-describedby="project-name-error"' : '' ?>
                        >
                        <?php if (isset($errors['name'])): ?>
                            <span class="field__error" id="project-name-error"><?= e(form_error('name', $errors)) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="project-description">
                            <?= e(t('project.pipeline_inputs')) ?>
                            <span class="muted">(<?= e(t('common.optional')) ?>)</span>
                        </label>
                        <textarea id="project-description" name="description" maxlength="2000"><?= e(old('description')) ?></textarea>
                    </div>

                    <div class="form__actions">
                        <button class="btn btn--block" type="submit"><?= e(t('dashboard.create_button')) ?></button>
                    </div>
                    <p class="form__meta"><?= e(t('dashboard.wizard_coming')) ?></p>
                </form>
            </section>
        <?php else: ?>
            <section class="card">
                <h2 class="panel-title"><?= e(t('team.role_viewer')) ?></h2>
                <p class="small muted mb-0"><?= e(t('team.owner_note')) ?></p>
            </section>
        <?php endif; ?>

        <section class="card">
            <h2 class="panel-title"><?= e(t('dashboard.workspace_label')) ?></h2>
            <dl class="meta-grid">
                <div class="meta-item">
                    <dt><?= e(t('team.members_title')) ?></dt>
                    <dd><?= e((string) $metrics['members']) ?></dd>
                </div>
                <div class="meta-item">
                    <dt><?= e(t('nav.projects')) ?></dt>
                    <dd><?= e((string) $metrics['projects']) ?></dd>
                </div>
            </dl>
            <p class="mt-2 mb-0">
                <a class="btn btn--secondary btn--sm" href="<?= e(url('/team')) ?>"><?= e(t('nav.team')) ?></a>
                <?php if ($canManageMembers): ?>
                    <a class="btn btn--ghost btn--sm" href="<?= e(url('/onboarding')) ?>"><?= e(t('nav.organization')) ?></a>
                <?php endif; ?>
            </p>
        </section>
    </aside>
</div>
