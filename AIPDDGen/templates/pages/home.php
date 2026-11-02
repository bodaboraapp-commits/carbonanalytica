<?php

declare(strict_types=1);

/** Landing page (public). */
?>
<section class="hero">
    <div class="container hero__inner">
        <div>
            <span class="hero__eyebrow"><?= e(t('landing.eyebrow')) ?></span>
            <h1><?= e(t('landing.h1')) ?></h1>
            <p class="hero__sub"><?= e(t('landing.sub')) ?></p>
            <div class="hero__actions">
                <a class="btn" href="<?= e(url('/signup')) ?>"><?= e(t('landing.cta_primary')) ?></a>
                <a class="btn btn--secondary" href="<?= e(url('/login')) ?>"><?= e(t('landing.cta_secondary')) ?></a>
            </div>

            <dl class="hero__stats">
                <div class="hero__stat">
                    <dt><?= e(t('landing.stats_weeks_value')) ?></dt>
                    <dd><?= e(t('landing.stats_weeks_label')) ?></dd>
                </div>
                <div class="hero__stat">
                    <dt><?= e(t('landing.stats_citation_value')) ?></dt>
                    <dd><?= e(t('landing.stats_citation_label')) ?></dd>
                </div>
                <div class="hero__stat">
                    <dt><?= e(t('landing.stats_language_value')) ?></dt>
                    <dd><?= e(t('landing.stats_language_label')) ?></dd>
                </div>
            </dl>
        </div>

        <aside class="hero__card" aria-label="<?= e(t('landing.factsheet_preview_title')) ?>">
            <div class="hero__card-title">
                <span><?= e(t('landing.factsheet_preview_title')) ?></span>
                <span class="badge badge--draft"><?= e(t('common.draft_badge')) ?></span>
            </div>
            <div class="fact-row">
                <span class="fact-row__key"><?= e(t('project.country_label')) ?></span>
                <span class="fact-row__value">Kenya</span>
            </div>
            <div class="fact-row">
                <span class="fact-row__key"><?= e(t('project.registry_label')) ?></span>
                <span class="fact-row__value"><?= e(t('landing.registry_gs')) ?></span>
            </div>
            <div class="fact-row">
                <span class="fact-row__key">tCO₂e (ex ante)</span>
                <span class="fact-row__value">84,320</span>
            </div>
            <div class="fact-row">
                <span class="fact-row__key"><?= e(t('project.pipeline_facts')) ?></span>
                <span class="fact-row__value cite">cited · input #4</span>
            </div>
            <div class="fact-row">
                <span class="fact-row__key">Baseline source</span>
                <span class="fact-row__value cite">transcript 00:14:22</span>
            </div>
            <div class="fact-row">
                <span class="fact-row__key"><?= e(t('project.pipeline_check')) ?></span>
                <span class="fact-row__value">1 needs input</span>
            </div>
        </aside>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="section__head">
            <h2><?= e(t('landing.audience_title')) ?></h2>
            <p><?= e(t('landing.audience_sub')) ?></p>
        </div>
        <div class="grid-2">
            <article class="card feature">
                <div class="feature__icon" aria-hidden="true">◆</div>
                <h3><?= e(t('landing.audience_consultant_title')) ?></h3>
                <p><?= e(t('landing.audience_consultant_body')) ?></p>
            </article>
            <article class="card feature">
                <div class="feature__icon" aria-hidden="true">▦</div>
                <h3><?= e(t('landing.audience_enterprise_title')) ?></h3>
                <p><?= e(t('landing.audience_enterprise_body')) ?></p>
                <p class="feature__note"><?= e(t('landing.audience_enterprise_note')) ?></p>
            </article>
        </div>
    </div>
</section>

<section class="section section--alt">
    <div class="container">
        <div class="section__head">
            <h2><?= e(t('landing.trust_title')) ?></h2>
        </div>
        <div class="grid-3">
            <article class="card feature">
                <div class="feature__icon" aria-hidden="true">✓</div>
                <h3><?= e(t('landing.trust_citations_title')) ?></h3>
                <p><?= e(t('landing.trust_citations_body')) ?></p>
            </article>
            <article class="card feature">
                <div class="feature__icon" aria-hidden="true">↻</div>
                <h3><?= e(t('landing.trust_versions_title')) ?></h3>
                <p><?= e(t('landing.trust_versions_body')) ?></p>
            </article>
            <article class="card feature">
                <div class="feature__icon" aria-hidden="true">♦</div>
                <h3><?= e(t('landing.trust_privacy_title')) ?></h3>
                <p><?= e(t('landing.trust_privacy_body')) ?></p>
            </article>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="section__head">
            <h2><?= e(t('landing.workflow_title')) ?></h2>
        </div>
        <div class="grid-4">
            <article class="card">
                <div class="step__num" aria-hidden="true">1</div>
                <h3><?= e(t('landing.step1_title')) ?></h3>
                <p class="muted small"><?= e(t('landing.step1_body')) ?></p>
            </article>
            <article class="card">
                <div class="step__num" aria-hidden="true">2</div>
                <h3><?= e(t('landing.step2_title')) ?></h3>
                <p class="muted small"><?= e(t('landing.step2_body')) ?></p>
            </article>
            <article class="card">
                <div class="step__num" aria-hidden="true">3</div>
                <h3><?= e(t('landing.step3_title')) ?></h3>
                <p class="muted small"><?= e(t('landing.step3_body')) ?></p>
            </article>
            <article class="card">
                <div class="step__num" aria-hidden="true">4</div>
                <h3><?= e(t('landing.step4_title')) ?></h3>
                <p class="muted small"><?= e(t('landing.step4_body')) ?></p>
            </article>
        </div>
    </div>
</section>

<section class="section section--alt">
    <div class="container">
        <div class="section__head">
            <h2><?= e(t('landing.registries_title')) ?></h2>
            <p><?= e(t('landing.registries_sub')) ?></p>
        </div>
        <div class="grid-2">
            <article class="card registry-card">
                <span class="badge"><?= e(t('landing.registry_gs')) ?></span>
                <h3><?= e(t('landing.registry_gs')) ?></h3>
                <p><?= e(t('landing.registry_gs_note')) ?></p>
            </article>
            <article class="card registry-card">
                <span class="badge badge--neutral"><?= e(t('landing.registry_vcs')) ?></span>
                <h3><?= e(t('landing.registry_vcs')) ?></h3>
                <p><?= e(t('landing.registry_vcs_note')) ?></p>
            </article>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="pricing-band">
            <div>
                <h2><?= e(t('landing.pricing_title')) ?></h2>
                <p><?= e(t('landing.pricing_body')) ?></p>
            </div>
            <a class="btn" href="<?= e(url('/signup')) ?>"><?= e(t('landing.cta_primary')) ?></a>
        </div>
    </div>
</section>
