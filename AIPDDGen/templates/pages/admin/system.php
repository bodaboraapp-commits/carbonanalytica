<?php

declare(strict_types=1);

/**
 * System Health page (SYSTEM group): migrations, storage, server and mail
 * checks for this deployment.
 *
 * @var array<string, mixed> $user
 * @var array<string, int|string> $system
 * @var array{kyc_waiting: int, verified_orgs: int, templates_published: int, documents_uploaded: int, plans: array{free: int, payg: int, team: int}} $totals
 * @var int $taskBadge
 */
?>
<div class="admin-page-head">
    <div>
        <h1><?= e(t('console.nav_system_health')) ?></h1>
        <p><?= e(t('super.system_sub')) ?></p>
    </div>
    <a class="btn btn--secondary btn--sm" href="<?= e(url('/admin/database')) ?>"><?= e(t('super.manage_db')) ?></a>
</div>

<section class="stat-grid stat-grid--auto" aria-labelledby="system-totals-title">
    <h2 class="visually-hidden" id="system-totals-title"><?= e(t('super.ledger')) ?></h2>
    <?php \App\View::partial('partials/stat-card', ['label' => t('super.kyc_waiting'), 'value' => (string) $totals['kyc_waiting'], 'icon' => 'inbox', 'tone' => 'blue']); ?>
    <?php \App\View::partial('partials/stat-card', ['label' => t('super.verified_orgs'), 'value' => (string) $totals['verified_orgs'], 'icon' => 'shield', 'tone' => 'green']); ?>
    <?php \App\View::partial('partials/stat-card', ['label' => t('super.templates_live'), 'value' => (string) $totals['templates_published'], 'icon' => 'layers', 'tone' => 'gold']); ?>
    <?php \App\View::partial('partials/stat-card', ['label' => t('super.docs_uploaded'), 'value' => (string) $totals['documents_uploaded'], 'icon' => 'file', 'tone' => 'slate']); ?>
    <?php \App\View::partial('partials/stat-card', ['label' => t('console.plan_free') . ' / ' . t('console.plan_payg') . ' / ' . t('console.plan_team'), 'value' => $totals['plans']['free'] . ' / ' . $totals['plans']['payg'] . ' / ' . $totals['plans']['team'], 'icon' => 'tag', 'tone' => 'green']); ?>
</section>
<p class="admin-muted small"><?= e(t('console.billing_note')) ?></p>

<section class="panel-card" aria-labelledby="system-health-title">
    <div class="panel-card__head">
        <h2 id="system-health-title"><?= e(t('console.nav_system_health')) ?></h2>
        <?php if ($system['pending_migrations'] === 0): ?>
            <span class="badge"><?= e(t('super.up_to_date')) ?></span>
        <?php elseif ($system['pending_migrations'] < 0): ?>
            <span class="badge badge--neutral"><?= e(t('super.unknown')) ?></span>
        <?php else: ?>
            <span class="badge badge--high"><?= e(t('super.pending_migrations')) ?>: <?= e((string) $system['pending_migrations']) ?></span>
        <?php endif; ?>
    </div>

    <ul class="summary-list">
        <li class="summary-list__item">
            <span class="summary-list__main"><strong><?= e(t('super.pending_migrations')) ?></strong></span>
            <?php if ($system['pending_migrations'] === 0): ?>
                <span class="badge"><?= e(t('super.up_to_date')) ?></span>
            <?php elseif ($system['pending_migrations'] < 0): ?>
                <span class="badge badge--neutral"><?= e(t('super.unknown')) ?></span>
            <?php else: ?>
                <span class="badge badge--high"><?= e((string) $system['pending_migrations']) ?></span>
            <?php endif; ?>
        </li>
        <li class="summary-list__item">
            <span class="summary-list__main"><strong><?= e(t('super.db_size')) ?></strong></span>
            <span class="admin-muted"><?= e((string) $system['db_size_mb']) ?> MB</span>
        </li>
        <li class="summary-list__item">
            <span class="summary-list__main"><strong><?= e(t('super.storage')) ?></strong></span>
            <span class="admin-muted"><?= e((string) $system['storage_mb']) ?> MB</span>
        </li>
        <li class="summary-list__item">
            <span class="summary-list__main"><strong><?= e(t('super.disk_free')) ?></strong></span>
            <span class="admin-muted"><?= e($system['disk_free_mb'] >= 0 ? (string) $system['disk_free_mb'] . ' MB' : t('super.unknown')) ?></span>
        </li>
        <li class="summary-list__item">
            <span class="summary-list__main"><strong><?= e(t('super.mail')) ?></strong></span>
            <span class="admin-muted"><?= e((string) $system['mail']) ?></span>
        </li>
        <li class="summary-list__item">
            <span class="summary-list__main"><strong><?= e(t('super.env')) ?></strong></span>
            <span class="admin-muted"><?= e((string) $system['env']) ?> · PHP <?= e((string) $system['php']) ?></span>
        </li>
        <li class="summary-list__item">
            <span class="summary-list__main"><strong>APP_URL</strong></span>
            <span class="admin-muted"><?= e((string) $system['url']) ?></span>
        </li>
    </ul>

    <p class="admin-muted small">
        <?= e(t('super.system_sub')) ?>
        <a href="<?= e(url('/admin/database')) ?>"><?= e(t('super.manage_db')) ?></a>
    </p>
</section>
