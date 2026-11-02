<?php

declare(strict_types=1);

/**
 * Deployment diagnostic — no framework, no database requirement, safe to run
 * when the app returns 500.
 *
 *   https://your-domain.example/AIPDDGen/health.php
 *   https://your-domain.example/health.php   (when the docroot is public/)
 *
 * If DEPLOY_TOKEN is set in .env.local the page requires ?token=<value>.
 * Delete this file after the deployment is verified.
 */

$root = dirname(__DIR__);
$envFile = $root . '/.env.local';
$env = [];

if (is_readable($envFile)) {
    foreach (preg_split('/\r?\n/', (string) file_get_contents($envFile)) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $value = trim($value);
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[0] === substr($value, -1)) {
            $value = substr($value, 1, -1);
        }
        $env[trim($key)] = $value;
    }
}

$deployToken = (string) ($env['DEPLOY_TOKEN'] ?? getenv('DEPLOY_TOKEN') ?: '');
$provided = (string) ($_GET['token'] ?? '');
if ($deployToken !== '' && !hash_equals($deployToken, $provided)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$checks = [];

$add = static function (string $label, bool $ok, string $detail = '', string $severity = 'error') use (&$checks): void {
    $checks[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail, 'severity' => $severity];
};

// PHP runtime.
$add('PHP version ≥ 8.2', version_compare(PHP_VERSION, '8.2.0', '>='), 'running ' . PHP_VERSION);
foreach (['pdo_mysql' => 'MySQL driver', 'mbstring' => 'multibyte strings', 'openssl' => 'encryption', 'fileinfo' => 'file detection', 'zip' => 'DOCX/zip handling', 'json' => 'JSON'] as $ext => $label) {
    $add("Extension {$ext} ({$label})", extension_loaded($ext), extension_loaded($ext) ? 'loaded' : 'MISSING — enable it in PHP extensions');
}

// Files and directories.
$add('Project root found', is_file($root . '/src/bootstrap.php'), $root);
$add('Front controller present', is_file($root . '/public/index.php'), $root . '/public/index.php');
$add('Templates present', is_file($root . '/templates/layout.php'), $root . '/templates/layout.php');
$add('Language catalogs present', is_file($root . '/lang/en.php') && is_file($root . '/lang/fr.php'), $root . '/lang');
$add('.env.local present', is_file($envFile), is_file($envFile) ? $envFile : 'MISSING — copy .env.example to .env.local and set DB credentials');

foreach (['logs', 'sessions', 'uploads'] as $dir) {
    $path = $root . '/storage/' . $dir;
    $exists = is_dir($path);
    $writable = $exists && is_writable($path);
    $add("storage/{$dir} writable", $writable, $exists ? ($writable ? 'writable' : 'NOT WRITABLE — chmod 775 and check the owner') : 'MISSING — create the directory');
}

// Configuration summary (never print secrets).
$add('APP_ENV', true, (string) ($env['APP_ENV'] ?? 'not set (defaults to development)'), 'info');
$add('APP_URL', true, (string) ($env['APP_URL'] ?? 'not set (defaults to http://localhost:8000)'), 'info');
$add('DB_HOST', true, (string) ($env['DB_HOST'] ?? getenv('DB_HOST') ?: 'not set (defaults to 127.0.0.1)'), 'info');
$add('DB_PORT', true, (string) ($env['DB_PORT'] ?? getenv('DB_PORT') ?: 'not set (defaults to 3307)'), 'info');
$add('DB_NAME', true, (string) ($env['DB_NAME'] ?? getenv('DB_NAME') ?: 'not set (defaults to pdd_generator)'), 'info');
$add('DB_USER', true, (string) ($env['DB_USER'] ?? getenv('DB_USER') ?: 'not set (defaults to root)'), 'info');
$add('DB_PASS', true, (($env['DB_PASS'] ?? getenv('DB_PASS') ?: '') !== '') ? 'set' : 'empty', 'info');

// Database connectivity.
$dbHost = (string) ($env['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1');
$dbPort = (int) ($env['DB_PORT'] ?? getenv('DB_PORT') ?: 3307);
$dbName = (string) ($env['DB_NAME'] ?? getenv('DB_NAME') ?: 'pdd_generator');
$dbUser = (string) ($env['DB_USER'] ?? getenv('DB_USER') ?: 'root');
$dbPass = (string) ($env['DB_PASS'] ?? getenv('DB_PASS') ?: '');

$pdo = null;
try {
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 4],
    );
    $add('Database connection', true, "connected to {$dbName} on {$dbHost}:{$dbPort}");

    $tables = (int) ($pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn() ?: 0);
    $add('Database tables', $tables >= 20, $tables . ' tables (expected >= 20; import database/pdd_generator_deploy.sql or run php bin/setup.php)');

    $hasMigrations = (int) ($pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'schema_migrations'")->fetchColumn() ?: 0) === 1;
    $migrations = $hasMigrations ? (int) ($pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() ?: 0) : 0;
    $add('Migrations applied', $migrations >= 6, $migrations . ' recorded (expected >= 6)');

    $users = $hasMigrations ? (int) ($pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() ?: 0) : 0;
    $add('Accounts exist', $users > 0, $users . ' user(s) — import the dump or run php bin/seed.php', 'warning');
} catch (Throwable $error) {
    $add('Database connection', false, 'FAILED: ' . htmlspecialchars($error->getMessage(), ENT_QUOTES));
}

$failures = count(array_filter($checks, static fn (array $c): bool => !$c['ok'] && $c['severity'] === 'error'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Deployment health</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; background: #f6f7f5; color: #17211b; margin: 0; padding: 2rem 1rem; }
        main { max-width: 860px; margin: 0 auto; }
        h1 { font-size: 1.35rem; margin: 0 0 0.25rem; }
        p.sub { color: #5d6a61; margin: 0 0 1.25rem; }
        table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #dde3dd; border-radius: 10px; overflow: hidden; }
        th, td { text-align: left; padding: 0.55rem 0.75rem; border-bottom: 1px solid #eef1ee; font-size: 0.88rem; vertical-align: top; }
        th { background: #f2f5f2; font-weight: 600; }
        tr:last-child td { border-bottom: 0; }
        .ok { color: #146c3a; font-weight: 600; }
        .bad { color: #a1121b; font-weight: 600; }
        .info { color: #5d6a61; }
        .detail { color: #4a554d; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 0.78rem; word-break: break-word; }
        .banner { padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-weight: 600; }
        .banner--ok { background: #e7f5ec; color: #146c3a; border: 1px solid #b7e0c5; }
        .banner--bad { background: #fdecec; color: #a1121b; border: 1px solid #f3c2c2; }
        .foot { color: #6b776e; font-size: 0.8rem; margin-top: 1rem; }
    </style>
</head>
<body>
<main>
    <h1>Deployment health</h1>
    <p class="sub"><?= htmlspecialchars((string) ($_SERVER['HTTP_HOST'] ?? 'unknown host'), ENT_QUOTES) ?> · <?= htmlspecialchars((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), ENT_QUOTES) ?></p>

    <div class="banner <?= $failures === 0 ? 'banner--ok' : 'banner--bad' ?>">
        <?= $failures === 0 ? 'All essential checks passed.' : $failures . ' check(s) need attention.' ?>
    </div>

    <table>
        <thead><tr><th>Check</th><th>Result</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($checks as $check): ?>
            <tr>
                <td><?= htmlspecialchars($check['label'], ENT_QUOTES) ?></td>
                <td>
                    <?php if ($check['severity'] === 'info'): ?>
                        <span class="info">info</span>
                    <?php else: ?>
                        <span class="<?= $check['ok'] ? 'ok' : 'bad' ?>"><?= $check['ok'] ? 'PASS' : 'FAIL' ?></span>
                    <?php endif; ?>
                </td>
                <td class="detail"><?= $check['detail'] !== '' ? $check['detail'] : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <p class="foot">
        Set <code>DEPLOY_TOKEN</code> in <code>.env.local</code> to require a token for this page.
        <strong>Delete <code>public/health.php</code> after the deployment is verified.</strong>
    </p>
</main>
</body>
</html>
