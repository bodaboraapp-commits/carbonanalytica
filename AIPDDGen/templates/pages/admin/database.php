<?php

declare(strict_types=1);

/**
 * Staff-only database maintenance / deployment page.
 *
 * @var array<string, mixed> $user
 * @var array<string, mixed> $status
 * @var int $taskBadge
 */
$seed = $status['seed'];
?>
<div class="admin-page-head">
    <div>
        <h1><?= e(t('db.title')) ?></h1>
        <p><?= e(t('db.sub')) ?></p>
    </div>
    <span class="admin-muted"><?= e(t('db.staff_only')) ?></span>
</div>

<div class="console-columns">
    <section class="panel-card" aria-labelledby="db-conn-title">
        <div class="panel-card__head">
            <h2 id="db-conn-title"><?= e(t('db.connection')) ?></h2>
        </div>
        <dl class="meta-grid">
            <div class="meta-item">
                <dt><?= e(t('db.database')) ?></dt>
                <dd><?= e((string) $status['database']) ?></dd>
            </div>
            <div class="meta-item">
                <dt><?= e(t('db.host')) ?></dt>
                <dd><?= e((string) $status['host']) ?>:<?= e((string) $status['port']) ?></dd>
            </div>
            <div class="meta-item">
                <dt><?= e(t('db.tables')) ?></dt>
                <dd><?= e((string) $status['tables']) ?></dd>
            </div>
            <div class="meta-item">
                <dt><?= e(t('db.php')) ?></dt>
                <dd><?= e((string) $status['php_version']) ?></dd>
            </div>
        </dl>
    </section>

    <section class="panel-card" aria-labelledby="db-seed-title">
        <div class="panel-card__head">
            <h2 id="db-seed-title"><?= e(t('db.seed_title')) ?></h2>
        </div>
        <p class="admin-muted"><?= e(t('db.seed_note')) ?></p>
        <ul class="summary-list">
            <li class="summary-list__item">
                <span class="summary-list__main"><strong><?= e(t('db.seed_admin')) ?></strong></span>
                <span class="badge <?= $seed['admin_exists'] ? '' : 'badge--urgent' ?>">
                    <?= e($seed['admin_exists'] ? t('db.present') : t('db.missing')) ?>
                </span>
            </li>
            <li class="summary-list__item">
                <span class="summary-list__main"><strong><?= e(t('db.seed_demo')) ?></strong></span>
                <span class="badge <?= $seed['demo_exists'] ? '' : 'badge--neutral' ?>">
                    <?= e($seed['demo_exists'] ? t('db.present') : t('db.missing')) ?>
                </span>
            </li>
            <li class="summary-list__item">
                <span class="summary-list__main"><strong><?= e(t('db.seed_template')) ?></strong></span>
                <span class="badge <?= $seed['template_exists'] ? '' : 'badge--neutral' ?>">
                    <?= e($seed['template_exists'] ? t('db.present') : t('db.missing')) ?>
                </span>
            </li>
            <li class="summary-list__item">
                <span class="summary-list__main"><strong><?= e(t('db.seed_tasks')) ?></strong></span>
                <span class="admin-muted"><?= e((string) $seed['tasks']) ?></span>
            </li>
        </ul>
        <form class="inline-form" method="post" action="<?= e(url('/admin/database/seed')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn--secondary btn--sm" type="submit"><?= e(t('db.run_seed')) ?></button>
        </form>
    </section>
</div>

<section class="panel-card" aria-labelledby="db-migrations-title">
    <div class="panel-card__head">
        <h2 id="db-migrations-title"><?= e(t('db.migrations_title')) ?></h2>
        <?php if ($status['pending'] !== []): ?>
            <form class="inline-form" method="post" action="<?= e(url('/admin/database/migrate')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn--sm" type="submit"><?= e(t('db.apply')) ?></button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($status['pending'] === []): ?>
        <div class="alert alert--success" role="status"><?= e(t('db.nothing_pending')) ?></div>
    <?php else: ?>
        <div class="alert alert--warning" role="status">
            <?= e(t('db.pending_count', ['count' => count($status['pending'])])) ?>
        </div>
    <?php endif; ?>

    <div class="table-wrap">
        <table class="data admin-table">
            <caption class="visually-hidden"><?= e(t('db.migrations_title')) ?></caption>
            <thead>
                <tr>
                    <th scope="col"><?= e(t('db.version')) ?></th>
                    <th scope="col"><?= e(t('db.state')) ?></th>
                    <th scope="col"><?= e(t('db.when')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($status['applied'] as $migration): ?>
                    <tr>
                        <td><span class="mono"><?= e((string) $migration['version']) ?></span></td>
                        <td><span class="badge"><?= e(t('db.applied')) ?></span></td>
                        <td class="admin-muted"><?= e($migration['applied_at'] !== null ? format_date((string) $migration['applied_at']) : '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($status['pending'] as $pendingVersion): ?>
                    <tr>
                        <td><span class="mono"><?= e($pendingVersion) ?></span></td>
                        <td><span class="badge badge--high"><?= e(t('db.pending')) ?></span></td>
                        <td class="admin-muted">—</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="admin-muted small"><?= e(t('db.migration_note')) ?></p>
</section>

<section class="panel-card" aria-labelledby="db-deploy-title">
    <div class="panel-card__head">
        <h2 id="db-deploy-title"><?= e(t('db.deploy_title')) ?></h2>
    </div>
    <p class="admin-muted"><?= e(t('db.deploy_body')) ?></p>
</section>
