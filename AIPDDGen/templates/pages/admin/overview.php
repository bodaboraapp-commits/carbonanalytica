<?php

declare(strict_types=1);

/**
 * Super Admin dashboard: platform stats, growth, system health and controls.
 *
 * @var array<string, mixed> $user
 * @var array<string, mixed> $overview
 * @var int $taskBadge
 */

$metrics = $overview['metrics'];
$system = $overview['system'];
$settings = $overview['settings'];
$plans = $overview['plans'];
$otpOn = (int) ($user['otp_enabled'] ?? 0) === 1;
$maintenanceOn = ($settings['maintenance_mode'] ?? '0') === '1';
$signupsOn = ($settings['signups_enabled'] ?? '1') === '1';
?>
<div class="admin-page-head">
    <div>
        <h1><?= e(t('super.title')) ?></h1>
        <p><?= e(t('super.sub')) ?></p>
    </div>
    <div class="super-actions">
        <a class="btn btn--secondary btn--sm" href="<?= e(url('/admin/tasks')) ?>"><?= e(t('super.open_queue')) ?></a>
        <a class="btn btn--secondary btn--sm" href="<?= e(url('/admin/kyc')) ?>"><?= e(t('super.review_kyc')) ?></a>
        <a class="btn btn--secondary btn--sm" href="<?= e(url('/admin/database')) ?>"><?= e(t('super.database')) ?></a>
    </div>
</div>

<section class="kpi-grid" aria-labelledby="super-kpi-title">
    <h2 class="visually-hidden" id="super-kpi-title"><?= e(t('super.stats')) ?></h2>
    <article class="kpi-card">
        <span class="kpi-card__icon kpi-card__icon--green" aria-hidden="true">&#127793;</span>
        <span class="kpi-card__label"><?= e(t('super.workspaces')) ?></span>
        <span class="kpi-card__value"><?= e((string) $metrics['workspaces']) ?></span>
    </article>
    <article class="kpi-card">
        <span class="kpi-card__icon kpi-card__icon--orange" aria-hidden="true">&#128200;</span>
        <span class="kpi-card__label"><?= e(t('super.projects')) ?></span>
        <span class="kpi-card__value"><?= e((string) $metrics['projects']) ?></span>
    </article>
    <article class="kpi-card">
        <span class="kpi-card__icon kpi-card__icon--blue" aria-hidden="true">&#128101;</span>
        <span class="kpi-card__label"><?= e(t('super.users')) ?></span>
        <span class="kpi-card__value"><?= e((string) $metrics['people']) ?></span>
    </article>
    <article class="kpi-card">
        <span class="kpi-card__icon kpi-card__icon--gold" aria-hidden="true">&#128203;</span>
        <span class="kpi-card__label"><?= e(t('super.open_tasks')) ?></span>
        <span class="kpi-card__value"><?= e((string) $metrics['open_tasks']) ?></span>
        <?php if ($metrics['review_breached'] > 0): ?>
            <span class="kpi-card__hint badge badge--urgent"><?= e(t('super.breached_count', ['count' => $metrics['review_breached']])) ?></span>
        <?php endif; ?>
    </article>
</section>

<section class="panel-card" aria-labelledby="super-growth-title">
    <div class="panel-card__head">
        <h2 id="super-growth-title"><?= e(t('super.growth')) ?></h2>
        <span class="admin-muted"><?= e(t('super.since')) ?></span>
    </div>
    <dl class="meta-grid meta-grid--4">
        <div class="meta-item">
            <dt><?= e(t('super.new_users')) ?></dt>
            <dd><?= e((string) $metrics['signups_7d']) ?></dd>
        </div>
        <div class="meta-item">
            <dt><?= e(t('super.active_users')) ?></dt>
            <dd><?= e((string) $metrics['active_7d']) ?></dd>
        </div>
        <div class="meta-item">
            <dt><?= e(t('super.new_projects')) ?></dt>
            <dd><?= e((string) $metrics['projects_7d']) ?></dd>
        </div>
        <div class="meta-item">
            <dt><?= e(t('super.new_drafts')) ?></dt>
            <dd><?= e((string) $metrics['drafts_7d']) ?></dd>
        </div>
        <div class="meta-item">
            <dt><?= e(t('super.signups_30d')) ?></dt>
            <dd><?= e((string) $metrics['signups_30d']) ?></dd>
        </div>
        <div class="meta-item">
            <dt><?= e(t('super.admins')) ?></dt>
            <dd><?= e((string) $metrics['admins']) ?></dd>
        </div>
        <div class="meta-item">
            <dt><?= e(t('super.creators_total')) ?></dt>
            <dd><?= e((string) $metrics['creators_total']) ?></dd>
        </div>
        <div class="meta-item">
            <dt><?= e(t('super.staff_total')) ?></dt>
            <dd><?= e((string) $metrics['staff_total']) ?></dd>
        </div>
        <div class="meta-item">
            <dt><?= e(t('super.facts_total')) ?></dt>
            <dd><?= e((string) $metrics['facts_total']) ?></dd>
        </div>
        <div class="meta-item">
            <dt><?= e(t('super.audit_24h')) ?></dt>
            <dd><?= e((string) $metrics['audit_24h']) ?></dd>
        </div>
    </dl>
</section>

<div class="console-columns">
    <section class="panel-card" aria-labelledby="super-system-title">
        <div class="panel-card__head">
            <h2 id="super-system-title"><?= e(t('super.system')) ?></h2>
            <a class="admin-link-arrow" href="<?= e(url('/admin/database')) ?>"><?= e(t('super.manage_db')) ?> <span aria-hidden="true">&#8594;</span></a>
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
        </ul>
    </section>

    <section class="panel-card" aria-labelledby="super-controls-title">
        <div class="panel-card__head">
            <h2 id="super-controls-title"><?= e(t('super.controls')) ?></h2>
        </div>

        <div class="security-row">
            <div class="security-row__text">
                <strong><?= e(t('super.maintenance_mode')) ?></strong>
                <p class="admin-muted"><?= e(t('super.maintenance_note')) ?></p>
            </div>
            <div class="security-row__action">
                <span class="badge <?= $maintenanceOn ? 'badge--urgent' : 'badge--neutral' ?>">
                    <?= e($maintenanceOn ? t('super.on') : t('super.off')) ?>
                </span>
                <form class="inline-form" method="post" action="<?= e(url('/admin/settings/maintenance_mode')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="value" value="<?= $maintenanceOn ? '0' : '1' ?>">
                    <button class="btn btn--sm <?= $maintenanceOn ? 'btn--secondary' : '' ?>" type="submit">
                        <?= e($maintenanceOn ? t('super.disable') : t('super.enable')) ?>
                    </button>
                </form>
            </div>
        </div>

        <div class="security-row">
            <div class="security-row__text">
                <strong><?= e(t('super.signups_enabled')) ?></strong>
                <p class="admin-muted"><?= e(t('super.signups_note')) ?></p>
            </div>
            <div class="security-row__action">
                <span class="badge <?= $signupsOn ? '' : 'badge--neutral' ?>">
                    <?= e($signupsOn ? t('super.on') : t('super.off')) ?>
                </span>
                <form class="inline-form" method="post" action="<?= e(url('/admin/settings/signups_enabled')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="value" value="<?= $signupsOn ? '0' : '1' ?>">
                    <button class="btn btn--sm <?= $signupsOn ? 'btn--secondary' : '' ?>" type="submit">
                        <?= e($signupsOn ? t('super.disable') : t('super.enable')) ?>
                    </button>
                </form>
            </div>
        </div>

        <div class="security-row" id="security">
            <div class="security-row__text">
                <strong><?= e(t('admin.security_2fa_title')) ?></strong>
                <p class="admin-muted"><?= e(t('admin.security_2fa_body')) ?></p>
            </div>
            <div class="security-row__action">
                <span class="badge <?= $otpOn ? '' : 'badge--neutral' ?>">
                    <?= e($otpOn ? t('admin.security_2fa_on') : t('admin.security_2fa_off')) ?>
                </span>
                <form class="inline-form" method="post" action="<?= e(url('/admin/security/otp')) ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn--sm <?= $otpOn ? 'btn--secondary' : '' ?>" type="submit">
                        <?= e($otpOn ? t('admin.security_disable') : t('admin.security_enable')) ?>
                    </button>
                </form>
            </div>
        </div>
    </section>
</div>

<div class="console-columns">
    <section class="panel-card" aria-labelledby="super-new-users-title">
        <div class="panel-card__head">
            <h2 id="super-new-users-title"><?= e(t('super.recent_users')) ?></h2>
            <a class="admin-link-arrow" href="<?= e(url('/admin/users')) ?>"><?= e(t('console.view_all')) ?> <span aria-hidden="true">&#8594;</span></a>
        </div>
        <?php if ($overview['recent_users'] === []): ?>
            <p class="admin-muted"><?= e(t('console.no_users')) ?></p>
        <?php else: ?>
            <ul class="summary-list">
                <?php foreach ($overview['recent_users'] as $row): ?>
                    <li class="summary-list__item">
                        <span class="summary-list__main">
                            <strong><?= e((string) $row['name']) ?></strong>
                            <span class="admin-muted"><?= e((string) $row['email']) ?></span>
                        </span>
                        <span class="badge <?= (int) $row['is_platform_admin'] === 1 ? '' : 'badge--neutral' ?>">
                            <?= e((int) $row['is_platform_admin'] === 1 ? t('console.role_platform_admin') : t('console.role_user')) ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="panel-card" aria-labelledby="super-balance-title">
        <div class="panel-card__head">
            <h2 id="super-balance-title"><?= e(t('super.ledger')) ?></h2>
        </div>
        <ul class="summary-list">
            <li class="summary-list__item">
                <span class="summary-list__main"><strong><?= e(t('super.kyc_waiting')) ?></strong></span>
                <span class="badge <?= $metrics['organisations_submitted'] > 0 ? 'badge--high' : 'badge--neutral' ?>">
                    <?= e((string) $metrics['organisations_submitted']) ?>
                </span>
            </li>
            <li class="summary-list__item">
                <span class="summary-list__main"><strong><?= e(t('super.verified_orgs')) ?></strong></span>
                <span class="badge badge--neutral"><?= e((string) $metrics['verified_orgs']) ?></span>
            </li>
            <li class="summary-list__item">
                <span class="summary-list__main"><strong><?= e(t('super.templates_live')) ?></strong></span>
                <span class="badge badge--neutral"><?= e((string) $metrics['templates_published']) ?></span>
            </li>
            <li class="summary-list__item">
                <span class="summary-list__main"><strong><?= e(t('super.docs_uploaded')) ?></strong></span>
                <span class="badge badge--neutral"><?= e((string) $metrics['organisation_documents']) ?></span>
            </li>
            <li class="summary-list__item">
                <span class="summary-list__main"><strong><?= e(t('console.plan_free')) ?> / <?= e(t('console.plan_payg')) ?> / <?= e(t('console.plan_team')) ?></strong></span>
                <span class="admin-muted"><?= e((string) ($plans['free'] ?? 0)) ?> / <?= e((string) ($plans['payg'] ?? 0)) ?> / <?= e((string) ($plans['team'] ?? 0)) ?></span>
            </li>
        </ul>
        <p class="admin-muted small"><?= e(t('console.billing_note')) ?></p>
    </section>
</div>

<div class="console-columns">
    <section class="panel-card" aria-labelledby="super-audit-title">
        <div class="panel-card__head">
            <h2 id="super-audit-title"><?= e(t('console.recent_audit')) ?></h2>
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
                            <span class="admin-muted"><?= e((string) ($row['actor_name'] ?? t('console.system_actor'))) ?></span>
                        </span>
                        <span class="admin-muted"><?= e(format_date((string) $row['created_at'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="panel-card" aria-labelledby="super-ws-title">
        <div class="panel-card__head">
            <h2 id="super-ws-title"><?= e(t('console.recent_workspaces')) ?></h2>
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
