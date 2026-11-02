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
$trends = $overview['trends'] ?? [];
$system = $overview['system'];
$settings = $overview['settings'];
$plans = $overview['plans'];
$otpOn = (int) ($user['otp_enabled'] ?? 0) === 1;
$maintenanceOn = ($settings['maintenance_mode'] ?? '0') === '1';
$signupsOn = ($settings['signups_enabled'] ?? '1') === '1';

/**
 * Polyline points for a decorative sparkline built from a real series.
 * The series is min/max scaled into a 100x32 viewBox with vertical padding;
 * a flat series sits on the midline instead of dividing by zero.
 *
 * @param list<int> $series
 */
$sparklinePoints = static function (array $series): string {
    $count = count($series);
    if ($count === 0) {
        return '';
    }

    $values = array_values($series);
    $min = min($values);
    $max = max($values);
    $range = $max - $min;
    $stepX = $count > 1 ? 100 / ($count - 1) : 0;

    $points = [];
    foreach ($values as $index => $value) {
        $x = round($index * $stepX, 2);
        $y = $range === 0 ? 16.0 : round(3 + (26 * (1 - (($value - $min) / $range))), 2);
        $points[] = $x . ',' . $y;
    }

    return implode(' ', $points);
};

/**
 * Trend foot state: real percent change, or — when the previous seven days had
 * no rows and no percentage can be derived — the real direction derived from
 * current vs previous totals (Increasing / Decreasing / No change). No label is
 * invented and no percentage is faked.
 *
 * @param array{series?: list<int>, delta?: int|null, direction?: 'up'|'down'|'flat'} $trend
 * @return array{class: string, text: string, arrow: string}
 */
$deltaState = static function (array $trend): array {
    $delta = $trend['delta'] ?? null;
    if ($delta === null) {
        $direction = (string) ($trend['direction'] ?? 'flat');
        $arrow = match ($direction) {
            'up' => 'up',
            'down' => 'down',
            default => 'flat',
        };
        $text = match ($direction) {
            'up' => t('super.trend_up'),
            'down' => t('super.trend_down'),
            default => t('super.trend_flat'),
        };

        return ['class' => 'is-' . $arrow, 'text' => $text, 'arrow' => $arrow];
    }
    if ($delta === 0) {
        return ['class' => 'is-flat', 'text' => '0% in 7 days', 'arrow' => 'flat'];
    }

    return [
        'class' => $delta > 0 ? 'is-up' : 'is-down',
        'text' => ($delta > 0 ? '+' : '') . $delta . '% in 7 days',
        'arrow' => $delta > 0 ? 'up' : 'down',
    ];
};

/** Decorative inline-SVG arrow for the trend foot (decorative: aria-hidden). */
$deltaArrow = static function (string $arrow): string {
    $stroke = 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';

    return match ($arrow) {
        'up' => '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M8 12.5V3.5M4.2 7.3 8 3.5l3.8 3.8" ' . $stroke . '/></svg>',
        'down' => '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M8 3.5v9M4.2 8.7 8 12.5l3.8-3.8" ' . $stroke . '/></svg>',
        'flat' => '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M3.5 8h9" ' . $stroke . '/></svg>',
        default => '',
    };
};

/** Inline line icon for the stat-card chip (decorative: aria-hidden). */
$statIcon = static function (string $name): string {
    $stroke = 'fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"';
    $paths = [
        'workspaces' => 'M3 21h18M5 21V7l7-4 7 4v14M9.5 21v-5.5h5V21',
        'projects' => 'M3 3v18h18M7 15l4-5 3 3 5-6',
        'users' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
        'tasks' => 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2',
    ];
    $path = $paths[$name] ?? '';

    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' . $path . '" ' . $stroke . '/></svg>';
};

/**
 * "Nice" y-axis scale for the line chart: a round tick maximum and step that
 * keeps the gridlines sparse. Small maxima fall back to 0..max.
 *
 * @return array{max: int, ticks: list<int>}
 */
$niceScale = static function (int $rawMax): array {
    $rawMax = max(1, $rawMax);
    if ($rawMax <= 3) {
        return ['max' => $rawMax, 'ticks' => range(0, $rawMax)];
    }

    $rough = $rawMax / 3;
    $power = 10 ** (int) floor(log10($rough));
    $normalised = $rough / $power;
    $step = (int) (($normalised <= 1 ? 1 : ($normalised <= 2 ? 2 : ($normalised <= 5 ? 5 : 10))) * $power);
    $step = max(1, $step);
    $max = (int) (ceil($rawMax / $step) * $step);

    $ticks = [];
    for ($tick = 0; $tick <= $max; $tick += $step) {
        $ticks[] = $tick;
    }

    return ['max' => $max, 'ticks' => $ticks];
};

// Last 7 calendar days (UTC), the same window as the service's trend series.
$trendDays = [];
$todayUtc = new \DateTimeImmutable(gmdate('Y-m-d'), new \DateTimeZone('UTC'));
for ($daysAgo = 6; $daysAgo >= 0; $daysAgo--) {
    $trendDays[] = $todayUtc->modify(sprintf('-%d days', $daysAgo));
}
$trendDayLabel = static function (int $index) use ($trendDays): string {
    return $index === count($trendDays) - 1 ? t('super.today') : $trendDays[$index]->format('d/m');
};

// The four platform growth metrics become one line each, and one bar pair each.
$chartMetrics = [
    'users' => ['label' => t('super.users'), 'tone' => 'green'],
    'projects' => ['label' => t('super.projects'), 'tone' => 'blue'],
    'drafts' => ['label' => t('super.drafts'), 'tone' => 'gold'],
    'tasks' => ['label' => t('super.tasks'), 'tone' => 'slate'],
];

$chartSeries = [];
$barSeries = [];
$chartPeak = 0;
$barPeak = 0;
foreach ($chartMetrics as $trendKey => $meta) {
    $trend = is_array($trends[$trendKey] ?? null) ? $trends[$trendKey] : [];
    $rawSeries = is_array($trend['series'] ?? null) ? array_values($trend['series']) : [];
    $values = [];
    foreach (array_keys($trendDays) as $index) {
        $values[] = (int) ($rawSeries[$index] ?? 0);
    }
    $current = (int) ($trend['current'] ?? array_sum($values));
    $previous = (int) ($trend['previous'] ?? 0);

    $chartSeries[$trendKey] = $meta + ['values' => $values, 'current' => $current];
    $barSeries[$trendKey] = $meta + ['current' => $current, 'previous' => $previous];
    $chartPeak = max($chartPeak, ...$values);
    $barPeak = max($barPeak, $current, $previous);
}

// Line/area chart geometry (all server-built, no JavaScript).
$chartScale = $niceScale($chartPeak);
$chartWidth = 640;
$chartHeight = 260;
$chartPadLeft = 40;
$chartPadRight = 14;
$chartPadTop = 16;
$chartPadBottom = 30;
$chartPlotWidth = $chartWidth - $chartPadLeft - $chartPadRight;
$chartPlotHeight = $chartHeight - $chartPadTop - $chartPadBottom;
$chartBaseline = $chartPadTop + $chartPlotHeight;
$chartX = static function (int $index) use ($chartPadLeft, $chartPlotWidth, $trendDays): float {
    $steps = max(1, count($trendDays) - 1);

    return round($chartPadLeft + ($index * ($chartPlotWidth / $steps)), 2);
};
$chartY = static function (int $value) use ($chartPadTop, $chartPlotHeight, $chartScale): float {
    $max = max(1, $chartScale['max']);

    return round($chartPadTop + $chartPlotHeight * (1 - ($value / $max)), 2);
};

// Grouped "this week vs previous week" bars. The 480x195 box keeps the same
// 640:260 aspect ratio as the line chart, so with equal grid columns both
// charts render at exactly the same dimensions.
$barWidth = 480;
$barHeight = 195;
$barPadLeft = 10;
$barPadRight = 10;
$barPadTop = 28;
$barPadBottom = 30;
$barPlotWidth = $barWidth - $barPadLeft - $barPadRight;
$barPlotHeight = $barHeight - $barPadTop - $barPadBottom;
$barBaseline = $barPadTop + $barPlotHeight;
$barGroupWidth = $barPlotWidth / max(1, count($barSeries));
$barEach = min($barGroupWidth * 0.3, 30.0);

$barGeometry = [];
$barIndex = 0;
foreach ($barSeries as $trendKey => $bar) {
    $previousHeight = round($barPlotHeight * ($bar['previous'] / max(1, $barPeak)), 2);
    $currentHeight = round($barPlotHeight * ($bar['current'] / max(1, $barPeak)), 2);
    $centre = round($barPadLeft + $barGroupWidth * ($barIndex + 0.5), 2);

    $barGeometry[$trendKey] = [
        'centreX' => $centre,
        'currentX' => round($centre + 2, 2),
        'currentY' => round($barBaseline - $currentHeight, 2),
        'currentHeight' => $currentHeight,
        'previousX' => round($centre - 2 - $barEach, 2),
        'previousY' => round($barBaseline - $previousHeight, 2),
        'previousHeight' => $previousHeight,
    ];
    $barIndex++;
}

// Growth numbers that are not one of the four charted series stay visible.
$growthStrip = [
    ['label' => t('super.active_users'), 'value' => (int) $metrics['active_7d']],
    ['label' => t('super.signups_30d'), 'value' => (int) $metrics['signups_30d']],
    ['label' => t('super.admins'), 'value' => (int) $metrics['admins']],
    ['label' => t('super.creators_total'), 'value' => (int) $metrics['creators_total']],
    ['label' => t('super.staff_total'), 'value' => (int) $metrics['staff_total']],
    ['label' => t('super.facts_total'), 'value' => (int) $metrics['facts_total']],
];

// Four hero tiles; every trend comes from the real last-7-days series above.
$statCards = [
    [
        'label' => t('super.workspaces'),
        'value' => (string) $metrics['workspaces'],
        'icon' => 'workspaces',
        'tone' => 'green',
        'trend' => $trends['workspaces'] ?? null,
        'flag' => 0,
    ],
    [
        'label' => t('super.projects'),
        'value' => (string) $metrics['projects'],
        'icon' => 'projects',
        'tone' => 'blue',
        'trend' => $trends['projects'] ?? null,
        'flag' => 0,
    ],
    [
        'label' => t('super.users'),
        'value' => (string) $metrics['people'],
        'icon' => 'users',
        'tone' => 'gold',
        'trend' => $trends['users'] ?? null,
        'flag' => 0,
    ],
    [
        'label' => t('super.open_tasks'),
        'value' => (string) $metrics['open_tasks'],
        'icon' => 'tasks',
        'tone' => 'slate',
        'trend' => $trends['tasks'] ?? null,
        'flag' => (int) $metrics['review_breached'],
    ],
];
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

<section class="stat-grid" aria-labelledby="super-kpi-title">
    <h2 class="visually-hidden" id="super-kpi-title"><?= e(t('super.stats')) ?></h2>
    <?php foreach ($statCards as $card): ?>
        <?php
        $trend = is_array($card['trend']) ? $card['trend'] : null;
        $series = is_array($trend['series'] ?? null) ? $trend['series'] : [];
        $delta = $trend !== null ? $deltaState($trend) : null;
        ?>
        <article class="stat-card">
            <div class="stat-card__head">
                <span class="stat-card__icon stat-card__icon--<?= e($card['tone']) ?>" aria-hidden="true"><?= $statIcon($card['icon']) ?></span>
                <span class="stat-card__label"><?= e($card['label']) ?></span>
                <?php if ($card['flag'] > 0): ?>
                    <span class="stat-card__flag badge badge--urgent"><?= e(t('super.breached_count', ['count' => $card['flag']])) ?></span>
                <?php endif; ?>
            </div>
            <span class="stat-card__value"><?= e($card['value']) ?></span>
            <?php if ($delta !== null && $series !== []): ?>
                <div class="stat-card__foot">
                    <span class="stat-card__delta <?= e($delta['class']) ?>">
                        <?= $deltaArrow($delta['arrow']) ?>
                        <span><?= e($delta['text']) ?></span>
                    </span>
                    <svg class="stat-card__spark" viewBox="0 0 100 32" aria-hidden="true" focusable="false">
                        <polyline
                            points="<?= e($sparklinePoints($series)) ?>"
                            pathLength="100"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                </div>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</section>

<section class="panel-card" aria-labelledby="super-growth-title">
    <div class="panel-card__head">
        <h2 id="super-growth-title"><?= e(t('super.growth')) ?></h2>
        <span class="admin-muted"><?= e(t('super.since')) ?></span>
    </div>

    <dl class="growth-strip">
        <?php foreach ($growthStrip as $item): ?>
            <div class="growth-strip__item">
                <dt><?= e($item['label']) ?></dt>
                <dd><?= e((string) $item['value']) ?></dd>
            </div>
        <?php endforeach; ?>
    </dl>

    <div class="growth-grid">
        <section class="growth-chart" aria-labelledby="growth-chart-title">
            <div class="growth-chart__head">
                <h3 id="growth-chart-title"><?= e(t('super.last_7_days')) ?></h3>
                <span class="growth-chart__hint"><?= e(t('super.total')) ?></span>
            </div>
            <svg class="growth-chart__svg" viewBox="0 0 <?= e((string) $chartWidth) ?> <?= e((string) $chartHeight) ?>" aria-hidden="true" focusable="false">
                <?php foreach ($chartScale['ticks'] as $tick): ?>
                    <line
                        class="growth-chart__grid<?= $tick === 0 ? ' growth-chart__grid--base' : '' ?>"
                        x1="<?= e($chartPadLeft) ?>"
                        y1="<?= e($chartY($tick)) ?>"
                        x2="<?= e($chartPadLeft + $chartPlotWidth) ?>"
                        y2="<?= e($chartY($tick)) ?>"
                    />
                    <text
                        class="growth-chart__axis"
                        x="<?= e($chartPadLeft - 8) ?>"
                        y="<?= e($chartY($tick) + 3.5) ?>"
                        text-anchor="end"
                    ><?= e((string) $tick) ?></text>
                <?php endforeach; ?>

                <?php foreach (array_keys($trendDays) as $index): ?>
                    <?php
                    $anchor = $index === 0 ? 'start' : ($index === count($trendDays) - 1 ? 'end' : 'middle');
                    ?>
                    <text
                        class="growth-chart__axis growth-chart__axis--x"
                        x="<?= e($chartX($index)) ?>"
                        y="<?= e($chartHeight - 9) ?>"
                        text-anchor="<?= e($anchor) ?>"
                    ><?= e($trendDayLabel($index)) ?></text>
                <?php endforeach; ?>

                <?php foreach ($chartSeries as $series): ?>
                    <?php
                    $linePath = '';
                    $areaPath = 'M ' . $chartX(0) . ' ' . $chartBaseline;
                    foreach ($series['values'] as $index => $value) {
                        $point = $chartX($index) . ' ' . $chartY($value);
                        $linePath .= ($index === 0 ? 'M ' : ' L ') . $point;
                        $areaPath .= ' L ' . $point;
                    }
                    $areaPath .= ' L ' . $chartX(count($series['values']) - 1) . ' ' . $chartBaseline . ' Z';
                    ?>
                    <g class="growth-chart__series growth-chart__series--<?= e($series['tone']) ?>">
                        <path class="growth-chart__area" d="<?= e($areaPath) ?>" />
                        <path class="growth-chart__line" d="<?= e($linePath) ?>" pathLength="100" />
                        <?php foreach ($series['values'] as $index => $value): ?>
                            <circle class="growth-chart__dot" cx="<?= e($chartX($index)) ?>" cy="<?= e($chartY($value)) ?>" r="3.4">
                                <title><?= e($series['label'] . ' · ' . $trendDayLabel($index) . ': ' . $value) ?></title>
                            </circle>
                        <?php endforeach; ?>
                    </g>
                <?php endforeach; ?>
            </svg>

            <ul class="growth-chart__legend">
                <?php foreach ($chartSeries as $series): ?>
                    <li class="growth-chart__legend-item">
                        <span class="growth-chart__swatch growth-chart__swatch--<?= e($series['tone']) ?>" aria-hidden="true"></span>
                        <span><?= e($series['label']) ?></span>
                        <span class="growth-chart__legend-value"><?= e((string) $series['current']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <table class="visually-hidden growth-chart__data">
                <caption><?= e(t('super.last_7_days')) ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('super.day')) ?></th>
                        <?php foreach ($chartMetrics as $meta): ?>
                            <th scope="col"><?= e($meta['label']) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_keys($trendDays) as $index): ?>
                        <tr>
                            <th scope="row"><?= e($trendDays[$index]->format('d/m')) ?></th>
                            <?php foreach ($chartSeries as $series): ?>
                                <td><?= e((string) $series['values'][$index]) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section class="growth-bars" aria-labelledby="growth-bars-title">
            <div class="growth-bars__head">
                <h3 id="growth-bars-title"><?= e(t('super.week_compare')) ?></h3>
            </div>
            <svg class="growth-bars__svg" viewBox="0 0 <?= e((string) $barWidth) ?> <?= e((string) $barHeight) ?>" aria-hidden="true" focusable="false">
                <line
                    class="growth-bars__baseline"
                    x1="<?= e($barPadLeft) ?>"
                    y1="<?= e($barBaseline) ?>"
                    x2="<?= e($barPadLeft + $barPlotWidth) ?>"
                    y2="<?= e($barBaseline) ?>"
                />
                <?php foreach ($barSeries as $trendKey => $bar): ?>
                    <?php $geometry = $barGeometry[$trendKey]; ?>
                    <rect
                        class="growth-bars__bar growth-bars__bar--previous"
                        x="<?= e($geometry['previousX']) ?>"
                        y="<?= e($geometry['previousY']) ?>"
                        width="<?= e($barEach) ?>"
                        height="<?= e($geometry['previousHeight']) ?>"
                        rx="3"
                    >
                        <title><?= e(t('super.previous_week') . ': ' . $bar['previous']) ?></title>
                    </rect>
                    <text
                        class="growth-bars__value"
                        x="<?= e($geometry['previousX'] + $barEach / 2) ?>"
                        y="<?= e(max(18, $geometry['previousY'] - 7)) ?>"
                        text-anchor="middle"
                    ><?= e((string) $bar['previous']) ?></text>
                    <rect
                        class="growth-bars__bar growth-bars__bar--current"
                        x="<?= e($geometry['currentX']) ?>"
                        y="<?= e($geometry['currentY']) ?>"
                        width="<?= e($barEach) ?>"
                        height="<?= e($geometry['currentHeight']) ?>"
                        rx="3"
                    >
                        <title><?= e(t('super.this_week') . ': ' . $bar['current']) ?></title>
                    </rect>
                    <text
                        class="growth-bars__value"
                        x="<?= e($geometry['currentX'] + $barEach / 2) ?>"
                        y="<?= e(max(18, $geometry['currentY'] - 7)) ?>"
                        text-anchor="middle"
                    ><?= e((string) $bar['current']) ?></text>
                    <text
                        class="growth-bars__name"
                        x="<?= e($geometry['centreX']) ?>"
                        y="<?= e($barHeight - 8) ?>"
                        text-anchor="middle"
                    ><?= e($bar['label']) ?></text>
                <?php endforeach; ?>
            </svg>

            <ul class="growth-bars__legend">
                <li class="growth-bars__legend-item">
                    <span class="growth-bars__swatch growth-bars__swatch--current" aria-hidden="true"></span>
                    <?= e(t('super.this_week')) ?>
                </li>
                <li class="growth-bars__legend-item">
                    <span class="growth-bars__swatch growth-bars__swatch--previous" aria-hidden="true"></span>
                    <?= e(t('super.previous_week')) ?>
                </li>
            </ul>

            <table class="visually-hidden growth-bars__data">
                <caption><?= e(t('super.week_compare')) ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('super.metric')) ?></th>
                        <th scope="col"><?= e(t('super.this_week')) ?></th>
                        <th scope="col"><?= e(t('super.previous_week')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($barSeries as $bar): ?>
                        <tr>
                            <th scope="row"><?= e($bar['label']) ?></th>
                            <td><?= e((string) $bar['current']) ?></td>
                            <td><?= e((string) $bar['previous']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </div>
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
