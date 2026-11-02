<?php

declare(strict_types=1);

/**
 * Standalone one-click deployment import page (token URL, no app layout).
 *
 * @var string $token
 * @var array<string, mixed> $state
 * @var array{statements: int, tables: int, backed_up: bool}|null $result
 * @var bool $dry_run
 * @var bool $replace
 * @var string|null $error
 * @var bool $executed
 */
$base = url('/deploy/import') . '?token=' . rawurlencode($token);
$loginUrl = url('/login');
?>
<!DOCTYPE html>
<html lang="<?= e(\App\I18n::locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(t('deploy.title')) ?> · <?= e(t('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(url('/assets/app.css')) ?>">
</head>
<body class="deploy-import">
<main class="deploy-import__wrap">
    <h1><?= e(t('deploy.title')) ?></h1>
    <p class="muted"><?= e(t('deploy.sub')) ?></p>

    <?php if ($error === 'failed'): ?>
        <div class="alert alert--error" role="alert"><?= e(t('deploy.failed')) ?></div>
    <?php elseif ($error === 'confirm'): ?>
        <div class="alert alert--warning" role="status"><?= e(t('deploy.confirm_needed')) ?></div>
    <?php elseif ($executed && $result !== null): ?>
        <div class="alert alert--success" role="status">
            <?= e($dry_run
                ? t('deploy.dry_done', ['count' => $result['statements'], 'tables' => $result['tables']])
                : t('deploy.done', ['count' => $result['statements'], 'tables' => $result['tables']])) ?>
        </div>
    <?php endif; ?>

    <section class="card">
        <h2><?= e(t('deploy.state')) ?></h2>
        <dl class="meta-grid">
            <div class="meta-item">
                <dt><?= e(t('deploy.db')) ?></dt>
                <dd><?= e((string) $state['database']) ?></dd>
            </div>
            <div class="meta-item">
                <dt><?= e(t('deploy.users')) ?></dt>
                <dd><?= e((string) $state['db_users']) ?></dd>
            </div>
            <div class="meta-item">
                <dt><?= e(t('deploy.teams')) ?></dt>
                <dd><?= e((string) $state['db_teams']) ?></dd>
            </div>
            <div class="meta-item">
                <dt><?= e(t('deploy.projects')) ?></dt>
                <dd><?= e((string) $state['db_projects']) ?></dd>
            </div>
            <div class="meta-item">
                <dt><?= e(t('deploy.file')) ?></dt>
                <dd><span class="mono"><?= e((string) $state['relative_path']) ?></span></dd>
            </div>
            <div class="meta-item">
                <dt><?= e(t('deploy.statements')) ?></dt>
                <dd><?= e((string) $state['statements']) ?> · <?= e((string) $state['dump_tables']) ?> tables</dd>
            </div>
        </dl>

        <p class="<?= $state['safe_to_import'] ? 'muted' : 'alert alert--warning' ?>">
            <?= e($state['safe_to_import'] ? t('deploy.safe') : t('deploy.unsafe')) ?>
        </p>

        <div class="form__actions">
            <a class="btn btn--secondary btn--sm" href="<?= e($base . '&dry_run=1') ?>"><?= e(t('deploy.dry_link')) ?></a>

            <?php if (!$state['safe_to_import']): ?>
                <a class="btn btn--sm" href="<?= e($base . '&replace=1') ?>"><?= e(t('deploy.import_confirm')) ?></a>
            <?php else: ?>
                <a class="btn btn--sm" href="<?= e($base) ?>"><?= e(t('deploy.import_link')) ?></a>
            <?php endif; ?>
        </div>

        <?php if ($executed && !$dry_run && $result !== null): ?>
            <p class="mt-2"><a class="btn btn--secondary btn--sm" href="<?= e($loginUrl) ?>"><?= e(t('deploy.login')) ?></a></p>
        <?php endif; ?>
    </section>

    <p class="small muted"><?= e(t('deploy.note')) ?></p>
</main>
</body>
</html>
