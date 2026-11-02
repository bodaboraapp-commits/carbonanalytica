<?php

declare(strict_types=1);

/**
 * Import the committed deployment dump from the staff console.
 *
 * @var array<string, mixed> $user
 * @var array<string, mixed> $state
 * @var int $taskBadge
 */

$path = (string) $state['path'];
$unsafe = !$state['safe_to_import'];
?>
<div class="admin-page-head">
    <div>
        <h1><?= e(t('import.title')) ?></h1>
        <p><?= e(t('import.sub')) ?></p>
    </div>
    <a class="admin-link-arrow" href="<?= e(url('/admin/database')) ?>">&#8592; <?= e(t('import.back')) ?></a>
</div>

<?php if (!$state['exists']): ?>
    <div class="alert alert--error" role="alert"><?= e(t('import.missing')) ?></div>
<?php else: ?>
    <div class="console-columns">
        <section class="panel-card" aria-labelledby="import-file-title">
            <div class="panel-card__head">
                <h2 id="import-file-title"><?= e(t('import.file')) ?></h2>
            </div>
            <dl class="meta-grid">
                <div class="meta-item">
                    <dt><?= e(t('import.path')) ?></dt>
                    <dd><span class="mono"><?= e((string) $state['relative_path']) ?></span></dd>
                </div>
                <div class="meta-item">
                    <dt><?= e(t('import.size')) ?></dt>
                    <dd><?= e((string) number_format((int) $state['size'] / 1024, 1)) ?> KB</dd>
                </div>
                <div class="meta-item">
                    <dt><?= e(t('import.modified')) ?></dt>
                    <dd><?= e($state['modified'] !== null ? format_date(date('Y-m-d H:i:s', (int) $state['modified'])) : '—') ?></dd>
                </div>
                <div class="meta-item">
                    <dt><?= e(t('import.sha')) ?></dt>
                    <dd><span class="mono small"><?= e((string) $state['sha256']) ?></span></dd>
                </div>
                <div class="meta-item">
                    <dt><?= e(t('import.statements')) ?></dt>
                    <dd><?= e((string) $state['statements']) ?></dd>
                </div>
                <div class="meta-item">
                    <dt><?= e(t('import.dump_tables')) ?></dt>
                    <dd><?= e((string) $state['dump_tables']) ?></dd>
                </div>
            </dl>
        </section>

        <section class="panel-card" aria-labelledby="import-db-title">
            <div class="panel-card__head">
                <h2 id="import-db-title"><?= e(t('import.db_state')) ?></h2>
            </div>
            <dl class="meta-grid">
                <div class="meta-item">
                    <dt><?= e(t('import.database')) ?></dt>
                    <dd><?= e((string) $state['database']) ?></dd>
                </div>
                <div class="meta-item">
                    <dt><?= e(t('import.db_tables')) ?></dt>
                    <dd><?= e((string) $state['db_tables']) ?></dd>
                </div>
                <div class="meta-item">
                    <dt><?= e(t('import.db_users')) ?></dt>
                    <dd><?= e((string) $state['db_users']) ?></dd>
                </div>
                <div class="meta-item">
                    <dt><?= e(t('import.db_teams')) ?></dt>
                    <dd><?= e((string) $state['db_teams']) ?></dd>
                </div>
            </dl>

            <?php if ($unsafe): ?>
                <div class="alert alert--warning" role="status"><?= e(t('import.unsafe')) ?></div>
            <?php else: ?>
                <div class="alert alert--success" role="status"><?= e(t('import.safe')) ?></div>
            <?php endif; ?>

            <form class="form" method="post" action="<?= e(url('/admin/database/import')) ?>">
                <?= csrf_field() ?>
                <?php if ($unsafe): ?>
                    <div class="checkbox-row">
                        <input id="import-replace" type="checkbox" name="replace" value="1">
                        <label for="import-replace"><?= e(t('import.replace')) ?></label>
                    </div>
                <?php endif; ?>
                <p class="admin-muted small"><?= e(t('import.note')) ?></p>
                <div class="form__actions">
                    <button class="btn btn--secondary btn--sm" type="submit" name="action" value="dry_run">
                        <?= e(t('import.validate')) ?>
                    </button>
                    <button class="btn btn--sm" type="submit" name="action" value="import">
                        <?= e(t('import.run')) ?>
                    </button>
                </div>
            </form>
        </section>
    </div>
<?php endif; ?>
