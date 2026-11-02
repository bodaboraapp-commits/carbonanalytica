<?php

declare(strict_types=1);

/**
 * Shared KPI stat card: the same anatomy as the Super Admin overview hero
 * tiles (tinted rounded-square icon chip + muted label, then the big Manrope
 * value). Pages without a real 7-day series simply omit the overview's
 * trend/sparkline foot; never invent numbers here.
 *
 * @var string $label
 * @var string $value
 * @var string $icon  key in $statCardIconPaths
 * @var string $tone  green | blue | gold | slate | danger
 */

$statCardIconPaths = [
    'users' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
    'projects' => 'M3 3v18h18M7 15l4-5 3 3 5-6',
    'drafts' => 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M9 13h6M9 17h4',
    'facts' => 'M22 11.08V12a10 10 0 1 1-5.93-9.14M22 4 12 14.01l-3-3',
    'inbox' => 'M22 12h-6l-2 3h-4l-2-3H2M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z',
    'shield' => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10zM9 12l2 2 4-4',
    'reject' => 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM15 9l-6 6M9 9l6 6',
    'layers' => 'M12 2 2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5',
    'archive' => 'M3 3h18v5H3zM5 8v13h14V8M9 12h6',
    'file' => 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6',
    'tag' => 'M20.59 13.41 12 22 2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82zM7 7h.01',
    'chart' => 'M3 3v18h18M7 15l4-5 3 3 5-6',
];

$tone = (string) ($tone ?? 'green');
if (!in_array($tone, ['green', 'blue', 'gold', 'slate', 'danger'], true)) {
    $tone = 'green';
}
$iconPath = $statCardIconPaths[(string) ($icon ?? 'chart')] ?? $statCardIconPaths['chart'];
?>
<article class="stat-card">
    <div class="stat-card__head">
        <span class="stat-card__icon stat-card__icon--<?= e($tone) ?>" aria-hidden="true">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="<?= e($iconPath) ?>" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </span>
        <span class="stat-card__label"><?= e((string) $label) ?></span>
    </div>
    <span class="stat-card__value"><?= e((string) $value) ?></span>
</article>
