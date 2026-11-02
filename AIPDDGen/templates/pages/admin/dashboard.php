<?php

declare(strict_types=1);

/**
 * Staff Action Center — operational overview modelled on the product owner's
 * reference console: attention tiles, work-type breakdown, SLA alert, pipeline
 * status cards and platform KPI tiles.
 *
 * @var array<string, mixed> $user
 * @var array{summary: array<string,int>, by_work_type: list<array{work_type: string, total: int}>, metrics: array<string,int>, synced: int} $dashboard
 * @var int $taskBadge
 */

use App\Services\TaskCenterService;

$summary = $dashboard['summary'];
$metrics = $dashboard['metrics'];
$workTypes = $dashboard['by_work_type'];
?>

<div class="admin-page-head">
    <div>
        <h1><?= e(t('admin.action_center')) ?></h1>
        <p><?= e(t('admin.action_center_sub')) ?></p>
    </div>
    <a class="admin-link-arrow" href="<?= e(url('/admin/tasks')) ?>">
        <?= e(t('admin.open_job_queue')) ?> <span aria-hidden="true">&#8594;</span>
    </a>
</div>

<section class="action-card" aria-labelledby="attention-title">
    <h2 class="visually-hidden" id="attention-title"><?= e(t('admin.action_center')) ?></h2>

    <div class="attention-grid">
        <a class="attention-tile attention-tile--mine" href="<?= e(url('/admin/tasks') . '?scope=mine') ?>">
            <span class="attention-tile__label"><?= e(t('admin.assigned_to_me')) ?></span>
            <span class="attention-tile__value"><?= e((string) $summary['assigned_to_me']) ?></span>
            <span class="attention-tile__hint"><?= e(t('admin.assigned_to_me_hint')) ?></span>
        </a>
        <a class="attention-tile attention-tile--unassigned" href="<?= e(url('/admin/tasks') . '?scope=unassigned') ?>">
            <span class="attention-tile__label"><?= e(t('admin.unassigned')) ?></span>
            <span class="attention-tile__value"><?= e((string) $summary['unassigned']) ?></span>
            <span class="attention-tile__hint"><?= e(t('admin.unassigned_hint')) ?></span>
        </a>
        <a class="attention-tile attention-tile--breached" href="<?= e(url('/admin/tasks') . '?scope=breached') ?>">
            <span class="attention-tile__label"><?= e(t('admin.sla_breached')) ?></span>
            <span class="attention-tile__value"><?= e((string) $summary['breached']) ?></span>
            <span class="attention-tile__hint"><?= e(t('admin.sla_breached_hint')) ?></span>
        </a>
    </div>

    <h2 class="panel-title"><?= e(t('admin.by_work_type')) ?></h2>
    <?php if ($workTypes === []): ?>
        <p class="admin-empty-line"><?= e(t('admin.no_work_types')) ?></p>
    <?php else: ?>
        <ul class="worktype-list">
            <?php foreach ($workTypes as $row): ?>
                <li>
                    <a class="worktype-row" href="<?= e(url('/admin/tasks') . '?type=' . rawurlencode($row['work_type'])) ?>">
                        <span class="worktype-row__name"><?= e(t(TaskCenterService::workTypeLabelKey($row['work_type']))) ?></span>
                        <span class="worktype-row__count"><?= e((string) $row['total']) ?></span>
                        <span class="worktype-row__arrow" aria-hidden="true">&#8594;</span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($summary['breached'] > 0): ?>
        <a class="sla-alert" href="<?= e(url('/admin/tasks') . '?scope=breached') ?>">
            <span class="sla-alert__icon" aria-hidden="true">&#9873;</span>
            <span><?= e(t('admin.sla_alert', ['count' => $summary['breached']])) ?></span>
            <span class="sla-alert__arrow" aria-hidden="true">&#8594;</span>
        </a>
    <?php endif; ?>
</section>

<section class="panel-card" aria-labelledby="pipeline-title">
    <div class="panel-card__head">
        <h2 id="pipeline-title"><?= e(t('admin.pipeline_title')) ?></h2>
        <a class="admin-link-arrow" href="<?= e(url('/admin/tasks')) ?>"><?= e(t('admin.pipeline_link')) ?> <span aria-hidden="true">&#8594;</span></a>
    </div>
    <div class="pipeline-cards">
        <article class="pipeline-card pipeline-card--blue">
            <span class="pipeline-card__icon" aria-hidden="true">&#9998;</span>
            <span class="pipeline-card__label"><?= e(t('admin.pipeline_fact_sheets')) ?></span>
            <span class="pipeline-card__value"><?= e((string) $metrics['fact_sheets_pending']) ?></span>
            <span class="pipeline-card__hint"><?= e(t('admin.pipeline_fact_sheets_hint')) ?></span>
        </article>
        <article class="pipeline-card pipeline-card--green">
            <span class="pipeline-card__icon" aria-hidden="true">&#9993;</span>
            <span class="pipeline-card__label"><?= e(t('admin.pipeline_drafts_in_review')) ?></span>
            <span class="pipeline-card__value"><?= e((string) $metrics['drafts_in_review']) ?></span>
            <span class="pipeline-card__hint"><?= e(t('admin.pipeline_drafts_in_review_hint')) ?></span>
        </article>
        <article class="pipeline-card pipeline-card--gold">
            <span class="pipeline-card__icon" aria-hidden="true">&#128196;</span>
            <span class="pipeline-card__label"><?= e(t('admin.pipeline_templates_live')) ?></span>
            <span class="pipeline-card__value"><?= e((string) $metrics['templates_published']) ?></span>
            <span class="pipeline-card__hint"><?= e(t('admin.pipeline_templates_live_hint')) ?></span>
        </article>
        <article class="pipeline-card pipeline-card--slate">
            <span class="pipeline-card__icon" aria-hidden="true">&#128193;</span>
            <span class="pipeline-card__label"><?= e(t('admin.pipeline_documents')) ?></span>
            <span class="pipeline-card__value"><?= e((string) $metrics['organisation_documents']) ?></span>
            <span class="pipeline-card__hint"><?= e(t('admin.pipeline_documents_hint')) ?></span>
        </article>
    </div>
</section>

<section class="kpi-grid" aria-labelledby="kpi-title">
    <h2 class="visually-hidden" id="kpi-title"><?= e(t('admin.kpi_title')) ?></h2>
    <article class="kpi-card">
        <span class="kpi-card__icon kpi-card__icon--green" aria-hidden="true">&#127793;</span>
        <span class="kpi-card__label"><?= e(t('admin.kpi_workspaces')) ?></span>
        <span class="kpi-card__value"><?= e((string) $metrics['workspaces']) ?></span>
    </article>
    <article class="kpi-card">
        <span class="kpi-card__icon kpi-card__icon--orange" aria-hidden="true">&#128200;</span>
        <span class="kpi-card__label"><?= e(t('admin.kpi_projects')) ?></span>
        <span class="kpi-card__value"><?= e((string) $metrics['projects']) ?></span>
    </article>
    <article class="kpi-card">
        <span class="kpi-card__icon kpi-card__icon--blue" aria-hidden="true">&#128101;</span>
        <span class="kpi-card__label"><?= e(t('admin.kpi_people')) ?></span>
        <span class="kpi-card__value"><?= e((string) $metrics['people']) ?></span>
    </article>
    <article class="kpi-card">
        <span class="kpi-card__icon kpi-card__icon--gold" aria-hidden="true">&#128203;</span>
        <span class="kpi-card__label"><?= e(t('admin.kpi_open_tasks')) ?></span>
        <span class="kpi-card__value"><?= e((string) $metrics['open_tasks']) ?></span>
    </article>
</section>
