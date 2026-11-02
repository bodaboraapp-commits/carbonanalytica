<?php

declare(strict_types=1);

/**
 * AI Engine page (SYSTEM group): honest placeholder until the drafting
 * pipeline lands. No provider, model or API-key settings are invented or
 * stored here — the page only reports the real deployment status.
 *
 * @var array<string, mixed> $user
 * @var int $taskBadge
 */
?>
<div class="admin-page-head">
    <div>
        <h1><?= e(t('ai.title')) ?></h1>
        <p><?= e(t('ai.sub')) ?></p>
    </div>
</div>

<section class="panel-card" aria-labelledby="ai-engine-title">
    <div class="panel-card__head">
        <h2 id="ai-engine-title"><?= e(t('ai.title')) ?></h2>
        <span class="badge badge--neutral"><?= e(t('ai.badge')) ?></span>
    </div>
    <p><?= e(t('ai.status')) ?></p>
    <p class="admin-muted small"><?= e(t('ai.note')) ?></p>
</section>
