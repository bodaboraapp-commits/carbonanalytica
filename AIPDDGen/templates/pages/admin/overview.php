<?php

declare(strict_types=1);

/**
 * Platform console overview — the fresh admin dashboard.
 *
 * @var array<string, mixed> $user
 * @var array<string, mixed> $overview
 * @var int $taskBadge
 */

$metrics = $overview['metrics'];
$plans = $overview['plans'];
$otpOn = (int) ($user['otp_enabled'] ?? 0) === 1;
?>
<div class="admin-page-head">
    <div>
        <h1><?= e(t('console.title')) ?></h1>
        <p><?= e(t('console.sub')) ?></p>
    </div>
    <a class="admin-link-arrow" href="<?= e(url('/admin/tasks')) ?>">
        <?= e(t('console.nav_job_queue')) ?> <span aria-hidden="true">&#8594;</span>
    </a>
</div>

<section class="kpi-grid" aria-labelledby="platform-kpi-title">
    <h2 class="visually-hidden" id="platform-kpi-title"><?= e(t('admin.kpi_title')) ?></h2>
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

<section class="panel-card" aria-labelledby="quick-title">
    <div class="panel-card__head">
        <h2 id="quick-title"><?= e(t('console.quick_links')) ?></h2>
    </div>
    <div class="quick-links">
        <a class="quick-link" href="<?= e(url('/admin/workspaces')) ?>">
            <strong><?= e(t('console.link_workspaces')) ?></strong>
            <span><?= e(t('console.link_workspaces_sub', ['count' => $metrics['workspaces']])) ?></span>
        </a>
        <a class="quick-link" href="<?= e(url('/admin/kyc')) ?>">
            <strong><?= e(t('console.link_kyc')) ?></strong>
            <span><?= e(t('console.link_kyc_sub', ['count' => $metrics['organisations_submitted']])) ?></span>
        </a>
        <a class="quick-link" href="<?= e(url('/admin/users')) ?>">
            <strong><?= e(t('console.link_users')) ?></strong>
            <span><?= e(t('console.link_users_sub', ['count' => $metrics['people']])) ?></span>
        </a>
        <a class="quick-link" href="<?= e(url('/admin/templates')) ?>">
            <strong><?= e(t('console.link_templates')) ?></strong>
            <span><?= e(t('console.link_templates_sub', ['count' => $metrics['templates_published']])) ?></span>
        </a>
        <a class="quick-link" href="<?= e(url('/admin/audit')) ?>">
            <strong><?= e(t('console.link_audit')) ?></strong>
            <span><?= e(t('console.link_audit_sub')) ?></span>
        </a>
        <a class="quick-link" href="<?= e(url('/admin/tasks')) ?>">
            <strong><?= e(t('console.link_tasks')) ?></strong>
            <span><?= e(t('console.link_tasks_sub', ['count' => $metrics['open_tasks']])) ?></span>
        </a>
    </div>
</section>

<div class="console-columns">
    <section class="panel-card" aria-labelledby="kyc-preview-title">
        <div class="panel-card__head">
            <h2 id="kyc-preview-title"><?= e(t('console.kyc_pending_title')) ?></h2>
            <a class="admin-link-arrow" href="<?= e(url('/admin/kyc')) ?>"><?= e(t('console.view_all')) ?> <span aria-hidden="true">&#8594;</span></a>
        </div>
        <?php if ($overview['kyc_preview'] === []): ?>
            <p class="admin-muted"><?= e(t('console.kyc_empty')) ?></p>
        <?php else: ?>
            <ul class="summary-list">
                <?php foreach (array_slice($overview['kyc_preview'], 0, 5) as $row): ?>
                    <li class="summary-list__item">
                        <span class="summary-list__main">
                            <strong><?= e((string) ($row['legal_name'] ?? $row['team_name'])) ?></strong>
                            <span class="admin-muted"><?= e((string) $row['team_name']) ?></span>
                        </span>
                        <span class="badge <?= $row['status'] === 'submitted' ? 'badge--high' : 'badge--neutral' ?>">
                            <?= e(t('console.kyc_status_' . (string) $row['status'])) ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="panel-card" aria-labelledby="recent-workspaces-title">
        <div class="panel-card__head">
            <h2 id="recent-workspaces-title"><?= e(t('console.recent_workspaces')) ?></h2>
            <a class="admin-link-arrow" href="<?= e(url('/admin/workspaces')) ?>"><?= e(t('console.view_all')) ?> <span aria-hidden="true">&#8594;</span></a>
        </div>
        <?php if ($overview['recent_workspaces'] === []): ?>
            <p class="admin-muted"><?= e(t('console.no_workspaces')) ?></p>
        <?php else: ?>
            <ul class="summary-list">
                <?php foreach ($overview['recent_workspaces'] as $row): ?>
                    <li class="summary-list__item">
                        <span class="summary-list__main">
                            <strong><?= e((string) $row['name']) ?></strong>
                            <span class="admin-muted"><?= e((string) $row['plan']) ?> · <?= e((string) $row['members']) ?> <?= e(t('team.members_label')) ?></span>
                        </span>
                        <span class="badge badge--neutral"><?= e(format_date((string) $row['created_at'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<div class="console-columns">
    <section class="panel-card" aria-labelledby="recent-audit-title">
        <div class="panel-card__head">
            <h2 id="recent-audit-title"><?= e(t('console.recent_audit')) ?></h2>
            <a class="admin-link-arrow" href="<?= e(url('/admin/audit')) ?>"><?= e(t('console.view_all')) ?> <span aria-hidden="true">&#8594;</span></a>
        </div>
        <?php if ($overview['recent_audit'] === []): ?>
            <p class="admin-muted"><?= e(t('console.no_audit')) ?></p>
        <?php else: ?>
            <ul class="summary-list">
                <?php foreach ($overview['recent_audit'] as $row): ?>
                    <li class="summary-list__item">
                        <span class="summary-list__main">
                            <strong><?= e((string) $row['action']) ?></strong>
                            <span class="admin-muted"><?= e((string) ($row['actor_name'] ?? t('console.system_actor'))) ?><?= $row['team_name'] !== null ? ' · ' . e((string) $row['team_name']) : '' ?></span>
                        </span>
                        <span class="admin-muted"><?= e(format_date((string) $row['created_at'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="panel-card" aria-labelledby="billing-title">
        <div class="panel-card__head">
            <h2 id="billing-title"><?= e(t('console.billing_title')) ?></h2>
        </div>
        <p class="admin-muted"><?= e(t('console.billing_note')) ?></p>
        <dl class="meta-grid">
            <div class="meta-item">
                <dt><?= e(t('console.plan_free')) ?></dt>
                <dd><?= e((string) ($plans['free'] ?? 0)) ?></dd>
            </div>
            <div class="meta-item">
                <dt><?= e(t('console.plan_payg')) ?></dt>
                <dd><?= e((string) ($plans['payg'] ?? 0)) ?></dd>
            </div>
            <div class="meta-item">
                <dt><?= e(t('console.plan_team')) ?></dt>
                <dd><?= e((string) ($plans['team'] ?? 0)) ?></dd>
            </div>
            <div class="meta-item">
                <dt><?= e(t('console.verified_orgs')) ?></dt>
                <dd><?= e((string) $metrics['verified_orgs']) ?></dd>
            </div>
        </dl>
    </section>
</div>

<section class="panel-card" id="security" aria-labelledby="console-security-title">
    <div class="panel-card__head">
        <h2 id="console-security-title"><?= e(t('admin.security_title')) ?></h2>
        <span class="badge <?= $otpOn ? '' : 'badge--neutral' ?>">
            <?= e($otpOn ? t('admin.security_2fa_on') : t('admin.security_2fa_off')) ?>
        </span>
    </div>
    <div class="security-row">
        <div class="security-row__text">
            <strong><?= e(t('admin.security_2fa_title')) ?></strong>
            <p class="admin-muted"><?= e(t('admin.security_2fa_body')) ?></p>
            <p class="admin-muted small"><?= e(t('admin.security_email_note')) ?></p>
        </div>
        <div class="security-row__action">
            <form class="inline-form" method="post" action="<?= e(url('/admin/security/otp')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn--sm <?= $otpOn ? 'btn--secondary' : '' ?>" type="submit">
                    <?= e($otpOn ? t('admin.security_disable') : t('admin.security_enable')) ?>
                </button>
            </form>
        </div>
    </div>
</section>
