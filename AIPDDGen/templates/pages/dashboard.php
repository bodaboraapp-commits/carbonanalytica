<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $user
 * @var array<string, mixed> $team
 * @var list<array<string, mixed>> $teams
 * @var list<array<string, mixed>> $projects
 * @var bool $canEdit
 * @var array<string, mixed> $organization
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
?>
<div class="app-head">
    <div>
        <h1><?= e(t('dashboard.title')) ?></h1>
        <p class="app-head__sub"><?= e(t('dashboard.sub')) ?></p>
    </div>
    <span class="workspace-pill">
        <strong><?= e(t('dashboard.workspace_label')) ?>:</strong>
        <?= e((string) $team['name']) ?>
        <span class="badge badge--neutral"><?= e(t('dashboard.plan_free')) ?></span>
    </span>
</div>

<div class="page-grid">
    <div class="stack">
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
                    <dd><?= e((string) ($team['member_count'] ?? '0')) ?></dd>
                </div>
                <div class="meta-item">
                    <dt><?= e(t('nav.projects')) ?></dt>
                    <dd><?= e((string) ($team['project_count'] ?? '0')) ?></dd>
                </div>
            </dl>
            <p class="mt-2 mb-0">
                <a class="btn btn--secondary btn--sm" href="<?= e(url('/team')) ?>"><?= e(t('nav.team')) ?></a>
            </p>
        </section>
    </aside>
</div>
