<?php

declare(strict_types=1);

/**
 * AI Engine settings (platform console, SYSTEM group).
 *
 * Staff only. The API key is write-only: this page never receives it from the
 * server (only the last four characters), and the key field is always empty so a
 * browser cannot repopulate it from history. Everything else is the ordinary
 * provider/model/skill configuration stored as data.
 *
 * Server-side tabs: plain `?tab=overview|settings|skill|bench` links (no
 * JavaScript). The inactive panels stay in the DOM as `hidden` so deep links,
 * legacy anchors (#test-bench, #examples) and no-JS navigation all keep working.
 *
 * @var array<string, mixed> $user
 * @var string $activeTab
 * @var array<string, mixed> $settings
 * @var array<string, string> $errors
 * @var array<string, mixed> $bench
 * @var int $taskBadge
 */

// --- Defensive defaults -----------------------------------------------------
// The page can be rendered without the bench payload (e.g. a template-only
// test) without notices; limits always come from the server, never hardcoded.
$activeTab = isset($activeTab) && is_string($activeTab) && in_array($activeTab, ['overview', 'settings', 'skill', 'bench'], true)
    ? $activeTab
    : 'overview';
$bench = is_array($bench ?? null) ? $bench : [];
$benchResult = is_array($bench['result'] ?? null) ? $bench['result'] : null;
$benchForm = is_array($bench['form'] ?? null) ? $bench['form'] : [];
$benchExamples = is_array($bench['examples'] ?? null) ? $bench['examples'] : [];
$benchRegistries = is_array($bench['registries'] ?? null) && $bench['registries'] !== [] ? $bench['registries'] : ['gold_standard', 'verra_vcs'];
$benchMaxInput = (int) ($bench['max_input_chars'] ?? \App\Services\AiEngineTestbenchService::MAX_INPUT_CHARS);
$benchMaxKb = (int) ($bench['max_upload_kb'] ?? (int) (\App\Services\AiEngineTestbenchService::MAX_UPLOAD_BYTES / 1024));
$benchBudget = (int) ($bench['budget_chars'] ?? 2000);
$benchMaxExamples = (int) ($bench['max_examples'] ?? 3);
$benchInput = old('input_text', (string) ($benchForm['input_text'] ?? ''));
$benchExpected = old('expected_output', (string) ($benchForm['expected_output'] ?? ''));
$benchRegistry = old('registry', (string) ($benchForm['registry'] ?? 'gold_standard'));

// Dynamic per-template SKILL.md data (AiEngineService::skillTabPayload()). The
// defensive default keeps template-only renders (e.g. tests) working without it.
$skills = is_array($skills ?? null) ? $skills : [];
$skillRegistries = is_array($skills['registries'] ?? null) ? $skills['registries'] : [];
$skillTotals = is_array($skills['totals'] ?? null) ? $skills['totals'] : [];
$skillStatusBadge = static fn (string $skillStatus): string => match ($skillStatus) {
    'ready' => 'badge--normal',
    'pending' => 'badge--high',
    default => 'badge--neutral',
};
$skillStatusText = static fn (string $skillStatus): string => match ($skillStatus) {
    'ready' => t('template.skill_ready'),
    'pending' => t('template.skill_pending'),
    default => t('ai.skill_missing'),
};

// Skill limits and upload size: the same service constants the server validates
// against, so a cap change shows up here without touching the template.
$skillMaxChars = \App\Services\AiEngineService::MAX_SKILL_LENGTH;
$globalMaxChars = \App\Services\AiEngineService::MAX_GLOBAL_INSTRUCTIONS;
$skillUploadMaxKb = (int) (\App\Services\AiEngineService::SKILL_UPLOAD_MAX_BYTES / 1024);

$status = (string) $settings['status'];
$badgeClass = match ($status) {
    'ready' => 'badge--normal',
    'disabled' => 'badge--draft',
    default => 'badge--neutral',
};
$maskedKey = $settings['has_stored_key'] ? '••••••••' . (string) ($settings['api_key_last4'] ?? '') : '';
$providerValue = old('provider', (string) $settings['provider']);
$baseUrlValue = old('base_url', (string) $settings['base_url']);

// Model choice: curated presets for the selected provider + a custom id field.
// If the stored model is not in the preset list we select "custom" and prefill
// it, so an arbitrary (valid) model id is never lost on render.
$modelPresets = \App\Services\AiEngineService::modelPresets($providerValue);
$submittedModel = old('model', '');
$submittedCustom = old('model_custom', '');
$storedModel = (string) $settings['model'];
if ($submittedModel !== '') {
    if ($submittedModel === '__custom__') {
        $modelSelectValue = '__custom__';
        $modelCustomValue = $submittedCustom;
    } elseif (in_array($submittedModel, $modelPresets, true)) {
        $modelSelectValue = $submittedModel;
        $modelCustomValue = $submittedCustom;
    } else {
        $modelSelectValue = '__custom__';
        $modelCustomValue = $submittedModel;
    }
} elseif (in_array($storedModel, $modelPresets, true)) {
    $modelSelectValue = $storedModel;
    $modelCustomValue = '';
} else {
    $modelSelectValue = '__custom__';
    $modelCustomValue = $storedModel;
}

$temperatureValue = old('temperature', (string) $settings['temperature']);
$maxTokensValue = old('max_tokens', (string) $settings['max_tokens']);
$skillGoldValue = old('skill_gold_standard', (string) $settings['skill_gold_standard']);
$skillVerraValue = old('skill_verra_vcs', (string) $settings['skill_verra_vcs']);
$globalValue = old('global_instructions', (string) $settings['global_instructions']);
$enabledChecked = $errors !== [] ? old('enabled') === '1' : (bool) $settings['enabled'];
$fieldInvalid = static fn (string $field): string => isset($errors[$field]) ? 'true' : 'false';

$tabs = [
    'overview' => t('ai.tab_overview'),
    'settings' => t('ai.tab_settings'),
    'skill' => t('ai.tab_skill'),
    'bench' => t('ai.tab_bench'),
];
$tabVisible = static fn (string $tab): string => $activeTab === $tab ? '' : ' hidden';
$skillConfigured = trim($skillGoldValue) !== '' || trim($skillVerraValue) !== '';

/**
 * Inline field error, with the actionable per-field copy for length errors
 * (the generic "This value is too long." would not tell staff which limit).
 */
$fieldError = static function (string $field) use ($errors, $skillMaxChars, $globalMaxChars, $benchMaxInput, $skillUploadMaxKb): string {
    if (!isset($errors[$field])) {
        return '';
    }
    if (($errors[$field] ?? '') === 'too_long') {
        return match ($field) {
            'skill_gold_standard', 'skill_verra_vcs' => t('ai.skill_too_long', ['max' => $skillMaxChars])
                . ' ' . t('ai.skill_upload_too_large', ['max' => $skillUploadMaxKb]),
            'global_instructions' => t('ai.skill_too_long', ['max' => $globalMaxChars])
                . ' ' . t('ai.skill_upload_too_large', ['max' => $skillUploadMaxKb]),
            'input_text' => t('ai.bench_input_too_long', ['max' => $benchMaxInput]),
            'input_file' => t('ai.skill_upload_too_large', ['max' => $benchMaxKb]),
            default => form_error($field, $errors),
        };
    }

    return form_error($field, $errors);
};
$charCounter = static fn (string $value, int $max): string => t('ai.char_counter', [
    'used' => (string) mb_strlen($value),
    'max' => (string) $max,
]);
$benchValidationError = isset($errors['input_text']) || isset($errors['input_file']) || isset($errors['registry']) || isset($errors['expected_output']);
?>
<div class="admin-page-head ai-head">
    <div>
        <h1><?= e(t('ai.title')) ?></h1>
        <p><?= e(t('ai.sub')) ?></p>
    </div>
</div>

<?php if (!$settings['encryption_ready']): ?>
    <div class="alert alert--error" role="alert">
        <strong><?= e(t('ai.encryption_missing')) ?></strong> <?= e(t('ai.encryption_missing_help')) ?>
    </div>
<?php endif; ?>

<nav class="ai-tabs" aria-label="<?= e(t('ai.tabs_label')) ?>">
    <?php foreach ($tabs as $tabSlug => $tabLabel): ?>
        <a class="ai-tab" href="<?= e(url('/admin/ai') . '?tab=' . $tabSlug) ?>" <?= $activeTab === $tabSlug ? 'aria-current="page"' : '' ?>>
            <?= e($tabLabel) ?>
        </a>
    <?php endforeach; ?>
</nav>

<?php /* ---------------------------------------------------------------- Overview */ ?>
<div class="ai-overview-grid"<?= $tabVisible('overview') ?>>
    <section class="panel-card" aria-labelledby="ai-overview-title">
        <div class="panel-card__head">
            <h2 id="ai-overview-title"><?= e(t('ai.overview_title')) ?></h2>
            <span class="badge <?= e($badgeClass) ?>"><?= e(t(\App\Services\AiEngineService::statusLabelKey($status))) ?></span>
        </div>
        <p class="admin-muted"><?= e(t('ai.overview_sub')) ?></p>

        <ul class="ai-checklist">
            <li class="ai-checklist__item">
                <span class="ai-checklist__text">
                    <strong><?= e(t('ai.provider')) ?></strong>
                    <span><?= e(t(\App\Services\AiEngineService::providerLabelKey((string) $settings['provider']))) ?></span>
                </span>
                <span class="badge badge--normal"><?= e(t('ai.configured_yes')) ?></span>
            </li>
            <li class="ai-checklist__item">
                <span class="ai-checklist__text">
                    <strong><?= e(t('ai.model')) ?></strong>
                    <span class="mono"><?= e((string) $settings['model']) ?></span>
                </span>
                <span class="badge badge--normal"><?= e(t('ai.configured_yes')) ?></span>
            </li>
            <li class="ai-checklist__item">
                <span class="ai-checklist__text">
                    <strong><?= e(t('ai.configured')) ?></strong>
                    <span class="mono"><?= e($maskedKey !== '' ? $maskedKey : t('ai.api_key_none')) ?></span>
                </span>
                <span class="badge <?= $settings['configured'] ? 'badge--normal' : 'badge--neutral' ?>">
                    <?= e($settings['configured'] ? t('ai.configured_yes') : t('ai.configured_no')) ?>
                </span>
            </li>
            <li class="ai-checklist__item">
                <span class="ai-checklist__text">
                    <strong><?= e(t('ai.skills_title')) ?></strong>
                    <?php if ($skillConfigured): ?>
                        <span>
                            <?= e(trim(implode(', ', array_filter([
                                trim($skillGoldValue) !== '' ? t('landing.registry_gs') : '',
                                trim($skillVerraValue) !== '' ? t('landing.registry_vcs') : '',
                            ])))) ?>
                        </span>
                    <?php else: ?>
                        <span><?= e(t('ai.skill_hint')) ?></span>
                    <?php endif; ?>
                </span>
                <span class="badge <?= $skillConfigured ? 'badge--normal' : 'badge--neutral' ?>">
                    <?= e($skillConfigured ? t('ai.configured_yes') : t('ai.configured_no')) ?>
                </span>
            </li>
            <li class="ai-checklist__item">
                <span class="ai-checklist__text">
                    <strong><?= e(t('ai.enabled')) ?></strong>
                    <span><?= e(t('ai.enabled_hint')) ?></span>
                </span>
                <span class="badge <?= $settings['enabled'] ? 'badge--normal' : 'badge--neutral' ?>">
                    <?= e($settings['enabled'] ? t('super.on') : t('super.off')) ?>
                </span>
            </li>
        </ul>

        <?php if (!$settings['configured']): ?>
            <div class="alert alert--warning" role="status">
                <strong><?= e(t('ai.status_not_configured')) ?></strong> <?= e(t('ai.not_configured_help')) ?>
            </div>
        <?php elseif (!$settings['enabled']): ?>
            <div class="alert alert--info" role="status"><?= e(t('ai.disabled_help')) ?></div>
        <?php else: ?>
            <div class="alert alert--success" role="status"><?= e(t('ai.ready_help')) ?></div>
        <?php endif; ?>

        <?php if ($settings['env_fallback']): ?>
            <div class="alert alert--info" role="status"><?= e(t('ai.env_fallback_help')) ?></div>
        <?php endif; ?>

        <p class="admin-muted small"><?= e(t('ai.overview_note')) ?></p>
    </section>
</div>

<?php /* ---------------------------------------------------------------- Settings */ ?>
<section class="panel-card" aria-labelledby="ai-settings-title"<?= $tabVisible('settings') ?>>
    <div class="panel-card__head">
        <h2 id="ai-settings-title"><?= e(t('ai.settings_title')) ?></h2>
        <span class="admin-muted small"><?= e(t('ai.settings_sub')) ?></span>
    </div>

    <?php if ($errors !== []): ?>
        <div class="alert alert--error" role="alert"><?= e(t('error.validation_error')) ?></div>
    <?php endif; ?>

    <form class="form ai-form" method="post" action="<?= e(url('/admin/ai')) ?>" novalidate autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="_tab" value="settings">

        <div class="ai-form-grid">
            <div class="field" data-invalid="<?= e($fieldInvalid('provider')) ?>">
                <label for="ai-provider"><?= e(t('ai.provider')) ?></label>
                <select id="ai-provider" name="provider"
                        <?= isset($errors['provider']) ? 'aria-invalid="true" aria-describedby="ai-provider-error"' : '' ?>>
                    <?php foreach ($settings['providers'] as $providerSlug): ?>
                        <option value="<?= e((string) $providerSlug) ?>" <?= $providerValue === $providerSlug ? 'selected' : '' ?>>
                            <?= e(t(\App\Services\AiEngineService::providerLabelKey((string) $providerSlug))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['provider'])): ?>
                    <span class="field__error" id="ai-provider-error"><?= e($fieldError('provider')) ?></span>
                <?php endif; ?>
            </div>

            <div class="field" data-invalid="<?= e($fieldInvalid('base_url')) ?>">
                <label for="ai-base-url"><?= e(t('ai.base_url')) ?></label>
                <input id="ai-base-url" name="base_url" type="url" inputmode="url" required maxlength="500"
                       list="ai-base-urls" value="<?= e($baseUrlValue) ?>"
                       <?= isset($errors['base_url']) ? 'aria-invalid="true" aria-describedby="ai-base-url-hint ai-base-url-error"' : 'aria-describedby="ai-base-url-hint"' ?>>
                <datalist id="ai-base-urls">
                    <?php foreach ($settings['base_urls'] as $presetUrl): ?>
                        <?php if ($presetUrl !== ''): ?><option value="<?= e((string) $presetUrl) ?>"></option><?php endif; ?>
                    <?php endforeach; ?>
                </datalist>
                <?php if (isset($errors['base_url'])): ?>
                    <span class="field__error" id="ai-base-url-error"><?= e($fieldError('base_url')) ?></span>
                <?php endif; ?>
            </div>

            <div class="field ai-field--wide" data-invalid="<?= e($fieldInvalid('model')) ?>">
                <label for="ai-model"><?= e(t('ai.model')) ?></label>
                <select id="ai-model" name="model"
                        <?= isset($errors['model']) ? 'aria-invalid="true" aria-describedby="ai-model-hint ai-model-error"' : 'aria-describedby="ai-model-hint"' ?>>
                    <?php foreach ($modelPresets as $presetModel): ?>
                        <option value="<?= e((string) $presetModel) ?>" <?= $modelSelectValue === $presetModel ? 'selected' : '' ?>>
                            <?= e((string) $presetModel) ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="__custom__" <?= $modelSelectValue === '__custom__' ? 'selected' : '' ?>>
                        <?= e(t('ai.model_other')) ?>
                    </option>
                </select>
                <label for="ai-model-custom" class="small admin-muted"><?= e(t('ai.model_custom_label')) ?></label>
                <input id="ai-model-custom" name="model_custom" type="text" maxlength="120"
                       value="<?= e($modelCustomValue) ?>" inputmode="text" spellcheck="false"
                       aria-describedby="ai-model-custom-hint">
                <?php if (isset($errors['model'])): ?>
                    <span class="field__error" id="ai-model-error"><?= e($fieldError('model')) ?></span>
                <?php endif; ?>
            </div>

            <div class="field" data-invalid="<?= e($fieldInvalid('temperature')) ?>">
                <label for="ai-temperature"><?= e(t('ai.temperature')) ?></label>
                <input id="ai-temperature" name="temperature" type="number" min="0" max="2" step="0.05"
                       value="<?= e($temperatureValue) ?>"
                       <?= isset($errors['temperature']) ? 'aria-invalid="true" aria-describedby="ai-temperature-hint ai-temperature-error"' : 'aria-describedby="ai-temperature-hint"' ?>>
                <?php if (isset($errors['temperature'])): ?>
                    <span class="field__error" id="ai-temperature-error"><?= e($fieldError('temperature')) ?></span>
                <?php endif; ?>
            </div>

            <div class="field" data-invalid="<?= e($fieldInvalid('max_tokens')) ?>">
                <label for="ai-max-tokens"><?= e(t('ai.max_tokens')) ?></label>
                <input id="ai-max-tokens" name="max_tokens" type="number" min="50" max="32000" step="1"
                       value="<?= e($maxTokensValue) ?>"
                       <?= isset($errors['max_tokens']) ? 'aria-invalid="true" aria-describedby="ai-max-tokens-hint ai-max-tokens-error"' : 'aria-describedby="ai-max-tokens-hint"' ?>>
                <?php if (isset($errors['max_tokens'])): ?>
                    <span class="field__error" id="ai-max-tokens-error"><?= e($fieldError('max_tokens')) ?></span>
                <?php endif; ?>
            </div>

            <div class="field" data-invalid="<?= e($fieldInvalid('api_key')) ?>">
                <label for="ai-api-key"><?= e(t('ai.api_key')) ?></label>
                <input id="ai-api-key" name="api_key" type="password" autocomplete="new-password" spellcheck="false"
                       maxlength="512" value=""
                       placeholder="<?= e($settings['has_stored_key'] ? t('ai.api_key_placeholder_keep') : t('ai.api_key_placeholder_new')) ?>"
                       <?= isset($errors['api_key']) ? 'aria-invalid="true" aria-describedby="ai-api-key-hint ai-api-key-error"' : 'aria-describedby="ai-api-key-hint"' ?>>
                <?php if (isset($errors['api_key'])): ?>
                    <span class="field__error" id="ai-api-key-error"><?= e($fieldError('api_key')) ?></span>
                <?php endif; ?>
                <?php if ($settings['has_stored_key']): ?>
                    <details class="ai-danger">
                        <summary><?= e(t('ai.clear_key')) ?></summary>
                        <label class="small admin-muted">
                            <input type="checkbox" name="clear_api_key" value="1">
                            <?= e(t('ai.clear_key')) ?>
                        </label>
                    </details>
                <?php endif; ?>
            </div>

            <div class="field">
                <label>
                    <input type="checkbox" name="enabled" value="1" <?= $enabledChecked ? 'checked' : '' ?>>
                    <?= e(t('ai.enabled')) ?>
                </label>
            </div>
        </div>

        <details class="ai-advanced">
            <summary><?= e(t('console.table_details')) ?></summary>
            <ul class="ai-advanced__list">
                <li><span class="admin-muted small" id="ai-base-url-hint"><?= e(t('ai.base_url_hint')) ?></span></li>
                <li><span class="admin-muted small" id="ai-model-hint"><?= e(t('ai.model_hint')) ?></span></li>
                <li><span class="admin-muted small" id="ai-model-custom-hint"><?= e(t('ai.model_custom_hint')) ?></span></li>
                <li><span class="admin-muted small" id="ai-temperature-hint"><?= e(t('ai.temperature_hint')) ?></span></li>
                <li><span class="admin-muted small" id="ai-max-tokens-hint"><?= e(t('ai.max_tokens_hint')) ?></span></li>
                <li><span class="admin-muted small" id="ai-api-key-hint"><?= e(t('ai.api_key_write_only')) ?></span></li>
                <li><span class="admin-muted small" id="ai-enabled-hint"><?= e(t('ai.enabled_hint')) ?></span></li>
            </ul>
        </details>

        <div class="ai-save-bar">
            <button class="btn" type="submit"><?= e(t('common.save')) ?></button>
            <?php if ($settings['configured'] && $settings['encryption_ready']): ?>
                <button class="btn btn--secondary" type="submit" formaction="<?= e(url('/admin/ai/test')) ?>" formmethod="post">
                    <?= e(t('ai.test_button')) ?>
                </button>
            <?php else: ?>
                <button class="btn btn--secondary" type="button" disabled><?= e(t('ai.test_button')) ?></button>
            <?php endif; ?>
            <p class="form__meta"><?= e(t('ai.version_note')) ?></p>
        </div>
        <p class="admin-muted small mb-0">
            <?= e(t($settings['configured'] && $settings['encryption_ready'] ? 'ai.test_sub' : 'ai.test_unavailable')) ?>
        </p>
    </form>
</section>

<?php /* ------------------------------------------------------------------- Skill */ ?>
<section class="panel-card" aria-labelledby="ai-skills-title"<?= $tabVisible('skill') ?>>
    <div class="panel-card__head">
        <h2 id="ai-skills-title"><?= e(t('ai.skills_title')) ?></h2>
        <span class="admin-muted small">
            <?= e(t('ai.skill_pending_count', ['count' => (int) ($skillTotals['pending'] ?? 0)])) ?>
            · <?= e(t('ai.skill_ready_count', ['count' => (int) ($skillTotals['ready'] ?? 0)])) ?>
        </span>
    </div>
    <h3 class="ai-skill-section-title"><?= e(t('ai.skill_tab_title')) ?></h3>
    <p class="admin-muted"><?= e(t('ai.skill_tab_sub')) ?></p>
    <p class="admin-muted small"><?= e(t('ai.skill_order_hint')) ?></p>

    <?php if ($errors !== []): ?>
        <div class="alert alert--error" role="alert"><?= e(t('error.validation_error')) ?></div>
    <?php endif; ?>

    <?php if ($skillRegistries === []): ?>
        <p class="admin-muted"><?= e(t('ai.skill_no_templates')) ?></p>
    <?php else: ?>
        <div class="ai-skill-templates">
            <?php foreach ($skillRegistries as $registryGroup): ?>
                <?php
                $registrySlug = (string) ($registryGroup['registry'] ?? '');
                $registryHeadingId = 'ai-skill-registry-' . $registrySlug;
                $fallbackSkill = is_array($registryGroup['fallback'] ?? null) ? $registryGroup['fallback'] : null;
                $templateRows = is_array($registryGroup['templates'] ?? null) ? $registryGroup['templates'] : [];
                ?>
                <section class="ai-skill-registry" aria-labelledby="<?= e($registryHeadingId) ?>">
                    <div class="ai-skill-registry__head">
                        <h3 id="<?= e($registryHeadingId) ?>"><?= e(t((string) ($registryGroup['label_key'] ?? 'landing.registry_gs'))) ?></h3>
                        <?php if ($fallbackSkill !== null): ?>
                            <span class="badge <?= e($skillStatusBadge((string) ($fallbackSkill['status'] ?? 'ready'))) ?>"><?= e($skillStatusText((string) ($fallbackSkill['status'] ?? 'ready'))) ?></span>
                            <span class="admin-muted small">
                                <?= e(t('ai.skill_registry_fallback')) ?>
                                <?php if (!empty($fallbackSkill['chars'])): ?>· <?= e(t('ai.skill_chars', ['count' => (int) $fallbackSkill['chars']])) ?><?php endif; ?>
                            </span>
                        <?php else: ?>
                            <span class="admin-muted small"><?= e(t('ai.skill_fallback_none')) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($templateRows === []): ?>
                        <p class="admin-muted small"><?= e(t('ai.skill_no_templates')) ?></p>
                    <?php else: ?>
                        <?php foreach ($templateRows as $templateRow): ?>
                            <?php
                            $templateSkill = is_array($templateRow['template_skill'] ?? null) ? $templateRow['template_skill'] : null;
                            $versions = is_array($templateRow['versions'] ?? null) ? $templateRow['versions'] : [];
                            ?>
                            <div class="ai-skill-template">
                                <h4 class="ai-skill-template__name"><?= e((string) ($templateRow['template_name'] ?? '')) ?></h4>
                                <?php if ($templateSkill !== null): ?>
                                    <p class="admin-muted small">
                                        <?= e(t('ai.skill_source_template')) ?> ·
                                        <span class="badge <?= e($skillStatusBadge((string) ($templateSkill['status'] ?? 'ready'))) ?>"><?= e($skillStatusText((string) ($templateSkill['status'] ?? 'ready'))) ?></span>
                                        <?php if (!empty($templateSkill['chars'])): ?>· <?= e(t('ai.skill_chars', ['count' => (int) $templateSkill['chars']])) ?><?php endif; ?>
                                    </p>
                                <?php endif; ?>

                                <ul class="ai-skill-versions">
                                    <?php foreach ($versions as $versionRow): ?>
                                        <?php
                                        $versionId = (string) ($versionRow['template_version_id'] ?? '');
                                        $versionStatus = (string) ($versionRow['status'] ?? 'missing');
                                        $versionSkill = is_array($versionRow['skill'] ?? null) ? $versionRow['skill'] : null;
                                        ?>
                                        <li class="ai-skill-version">
                                            <div class="ai-skill-version__main">
                                                <span class="ai-skill-version__label">
                                                    <?= e(t('ai.skill_version_label')) ?> <strong class="mono"><?= e((string) ($versionRow['version_label'] ?? '')) ?></strong>
                                                </span>
                                                <span class="badge <?= e($skillStatusBadge($versionStatus)) ?>"><?= e($skillStatusText($versionStatus)) ?></span>
                                                <?php if ($versionStatus === 'ready' && $versionSkill !== null): ?>
                                                    <span class="admin-muted small">
                                                        <?= e(t('ai.skill_chars', ['count' => (int) ($versionSkill['chars'] ?? 0)])) ?>
                                                        <?php if (($versionSkill['source_filename'] ?? null) !== null): ?>
                                                            · <?= e((string) $versionSkill['source_filename']) ?>
                                                        <?php endif; ?>
                                                        <?php if (($versionSkill['updated_at'] ?? null) !== null): ?>
                                                            · <?= e(t('ai.skill_updated_label')) ?> <?= e(format_date((string) $versionSkill['updated_at'])) ?>
                                                        <?php endif; ?>
                                                    </span>
                                                <?php elseif ($versionStatus === 'missing'): ?>
                                                    <?php /* No version skill row: drafting uses the registry fallback (not blocking). */ ?>
                                                    <span class="admin-muted small">
                                                        <?= e(t('ai.skill_source_registry')) ?>: <?= e(t('ai.skill_registry_fallback')) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="admin-muted small"><?= e(t('ai.skill_required_hint')) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <form class="ai-skill-version__form" method="post" enctype="multipart/form-data"
                                                  action="<?= e(url('/admin/templates/' . $versionId . '/skill')) ?>">
                                                <?= csrf_field() ?>
                                                <label for="ai-skill-file-<?= e($versionId) ?>" class="small admin-muted"><?= e(t('template.skill_upload_label')) ?></label>
                                                <input id="ai-skill-file-<?= e($versionId) ?>" name="skill" type="file" accept=".md,text/markdown" required>
                                                <button class="btn btn--sm" type="submit"><?= e($versionStatus === 'ready' ? t('ai.skill_upload_replace') : t('ai.skill_upload_first')) ?></button>
                                            </form>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
        <p class="admin-muted small"><?= e(t('template.skill_hint')) ?></p>
    <?php endif; ?>

    <h3 class="ai-skill-section-title"><?= e(t('ai.skill_registry_fallback')) ?></h3>
    <p class="admin-muted small"><?= e(t('ai.skill_registry_fallback_hint')) ?></p>
    <p class="admin-muted small" id="ai-skill-upload-hint">
        <?= e(t('ai.skill_upload_hint', ['kb' => $skillUploadMaxKb])) ?>
    </p>

    <form class="form" method="post" action="<?= e(url('/admin/ai')) ?>" enctype="multipart/form-data" novalidate autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="_tab" value="skill">
        <input type="hidden" name="provider" value="<?= e($providerValue) ?>">
        <input type="hidden" name="base_url" value="<?= e($baseUrlValue) ?>">
        <input type="hidden" name="model" value="<?= e($modelSelectValue) ?>">
        <?php if ($modelCustomValue !== ''): ?>
            <input type="hidden" name="model_custom" value="<?= e($modelCustomValue) ?>">
        <?php endif; ?>
        <input type="hidden" name="temperature" value="<?= e($temperatureValue) ?>">
        <input type="hidden" name="max_tokens" value="<?= e($maxTokensValue) ?>">
        <?php if ($enabledChecked): ?><input type="hidden" name="enabled" value="1"><?php endif; ?>

        <div class="ai-skill-grid">
            <div class="field" data-invalid="<?= e($fieldInvalid('skill_gold_standard')) ?>">
                <label for="ai-skill-gold"><?= e(t('ai.skill_gold_standard')) ?></label>
                <textarea id="ai-skill-gold" name="skill_gold_standard" rows="8" maxlength="<?= e((string) $skillMaxChars) ?>"
                          <?= isset($errors['skill_gold_standard']) ? 'aria-invalid="true" aria-describedby="ai-skill-gold-error"' : '' ?>><?= e($skillGoldValue) ?></textarea>
                <span class="admin-muted ai-counter"><?= e($charCounter($skillGoldValue, $skillMaxChars)) ?></span>
                <label for="ai-skill-gold-file" class="small admin-muted"><?= e(t('ai.skill_upload_label')) ?></label>
                <input id="ai-skill-gold-file" name="skill_gold_standard_file" type="file"
                       accept=".md,text/markdown" aria-describedby="ai-skill-upload-hint">
                <?php if (isset($errors['skill_gold_standard'])): ?>
                    <span class="field__error" id="ai-skill-gold-error"><?= e($fieldError('skill_gold_standard')) ?></span>
                <?php endif; ?>
            </div>

            <div class="field" data-invalid="<?= e($fieldInvalid('skill_verra_vcs')) ?>">
                <label for="ai-skill-verra"><?= e(t('ai.skill_verra_vcs')) ?></label>
                <textarea id="ai-skill-verra" name="skill_verra_vcs" rows="8" maxlength="<?= e((string) $skillMaxChars) ?>"
                          <?= isset($errors['skill_verra_vcs']) ? 'aria-invalid="true" aria-describedby="ai-skill-verra-error"' : '' ?>><?= e($skillVerraValue) ?></textarea>
                <span class="admin-muted ai-counter"><?= e($charCounter($skillVerraValue, $skillMaxChars)) ?></span>
                <label for="ai-skill-verra-file" class="small admin-muted"><?= e(t('ai.skill_upload_label')) ?></label>
                <input id="ai-skill-verra-file" name="skill_verra_vcs_file" type="file"
                       accept=".md,text/markdown" aria-describedby="ai-skill-upload-hint">
                <?php if (isset($errors['skill_verra_vcs'])): ?>
                    <span class="field__error" id="ai-skill-verra-error"><?= e($fieldError('skill_verra_vcs')) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <h3 class="ai-skill-section-title"><?= e(t('ai.skill_global_title')) ?></h3>
        <p class="admin-muted small"><?= e(t('ai.skill_global_hint')) ?></p>

        <div class="field" data-invalid="<?= e($fieldInvalid('global_instructions')) ?>">
            <label for="ai-global"><?= e(t('ai.global_instructions')) ?></label>
            <textarea id="ai-global" name="global_instructions" rows="5" maxlength="<?= e((string) $globalMaxChars) ?>"
                      aria-describedby="ai-global-hint<?= isset($errors['global_instructions']) ? ' ai-global-error' : '' ?>"
                      <?= isset($errors['global_instructions']) ? 'aria-invalid="true"' : '' ?>><?= e($globalValue) ?></textarea>
            <span class="admin-muted ai-counter"><?= e($charCounter($globalValue, $globalMaxChars)) ?></span>
            <label for="ai-global-file" class="small admin-muted"><?= e(t('ai.skill_upload_label')) ?></label>
            <input id="ai-global-file" name="global_instructions_file" type="file"
                   accept=".md,text/markdown" aria-describedby="ai-skill-upload-hint">
            <span class="admin-muted small" id="ai-global-hint"><?= e(t('ai.global_instructions_hint')) ?></span>
            <?php if (isset($errors['global_instructions'])): ?>
                <span class="field__error" id="ai-global-error"><?= e($fieldError('global_instructions')) ?></span>
            <?php endif; ?>
        </div>

        <p class="admin-muted small" id="ai-skill-hint"><?= e(t('ai.skill_hint')) ?></p>

        <div class="ai-save-bar">
            <button class="btn" type="submit"><?= e(t('common.save')) ?></button>
        </div>
    </form>
</section>

<?php /* --------------------------------------------------------- Bench & training */ ?>
<section class="panel-card" id="test-bench" aria-labelledby="ai-bench-title"<?= $tabVisible('bench') ?>>
    <div class="panel-card__head">
        <h2 id="ai-bench-title"><?= e(t('ai.testbench_title')) ?></h2>
        <span class="badge badge--gold"><?= e(t('ai.testbench_badge')) ?></span>
    </div>

    <div class="alert alert--info" role="note">
        <strong><?= e(t('ai.testbench_not_finetune')) ?></strong>
        <?= e(t('ai.testbench_not_finetune_body')) ?>
    </div>

    <?php if ($benchValidationError): ?>
        <div class="alert alert--error" role="alert"><?= e(t('error.validation_error')) ?></div>
    <?php endif; ?>

    <div class="ai-bench-grid">
        <div class="ai-bench-panel">
            <form class="form" method="post" action="<?= e(url('/admin/ai/test-input')) ?>" enctype="multipart/form-data" novalidate data-ai-bench-form>
                <?= csrf_field() ?>

                <div class="field">
                    <label for="bench-registry"><?= e(t('ai.testbench_registry')) ?></label>
                    <select id="bench-registry" name="registry">
                        <?php foreach ($benchRegistries as $benchRegistrySlug): ?>
                            <option value="<?= e((string) $benchRegistrySlug) ?>" <?= $benchRegistry === $benchRegistrySlug ? 'selected' : '' ?>>
                                <?= e((string) $benchRegistrySlug === 'gold_standard' ? t('landing.registry_gs') : t('landing.registry_vcs')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field" data-invalid="<?= e($fieldInvalid('input_text')) ?>">
                    <label for="bench-input"><?= e(t('ai.testbench_input')) ?></label>
                    <textarea id="bench-input" name="input_text" rows="8" maxlength="<?= e((string) $benchMaxInput) ?>"
                              placeholder="<?= e(t('ai.testbench_input_placeholder')) ?>"
                              aria-describedby="bench-input-hint<?= isset($errors['input_text']) ? ' bench-input-error' : '' ?>"
                              <?= isset($errors['input_text']) ? 'aria-invalid="true"' : '' ?>><?= e($benchInput) ?></textarea>
                    <span class="admin-muted small" id="bench-input-hint"><?= e(t('ai.testbench_input_hint', ['max' => $benchMaxInput])) ?></span>
                    <?php if (isset($errors['input_text'])): ?>
                        <span class="field__error" id="bench-input-error"><?= e($fieldError('input_text')) ?></span>
                    <?php endif; ?>
                </div>

                <div class="field" data-invalid="<?= e($fieldInvalid('input_file')) ?>">
                    <label for="bench-file">
                        <?= e(t('ai.testbench_file')) ?>
                        <span class="muted">(<?= e(t('common.optional')) ?>)</span>
                    </label>
                    <input id="bench-file" name="input_file" type="file" accept=".txt,.md,text/plain,text/markdown"
                           aria-describedby="bench-file-hint<?= isset($errors['input_file']) ? ' bench-file-error' : '' ?>"
                           <?= isset($errors['input_file']) ? 'aria-invalid="true"' : '' ?>>
                    <span class="admin-muted small" id="bench-file-hint"><?= e(t('ai.testbench_file_hint', ['kb' => $benchMaxKb])) ?></span>
                    <?php if (isset($errors['input_file'])): ?>
                        <span class="field__error" id="bench-file-error"><?= e($fieldError('input_file')) ?></span>
                    <?php endif; ?>
                </div>

                <div class="field" data-invalid="<?= e($fieldInvalid('expected_output')) ?>">
                    <label for="bench-expected">
                        <?= e(t('ai.testbench_expected')) ?>
                        <span class="muted">(<?= e(t('common.optional')) ?>)</span>
                    </label>
                    <textarea id="bench-expected" name="expected_output" rows="3" maxlength="<?= e((string) $benchMaxInput) ?>"
                              aria-describedby="bench-expected-hint<?= isset($errors['expected_output']) ? ' bench-expected-error' : '' ?>"
                              <?= isset($errors['expected_output']) ? 'aria-invalid="true"' : '' ?>><?= e($benchExpected) ?></textarea>
                    <span class="admin-muted small" id="bench-expected-hint"><?= e(t('ai.testbench_expected_hint')) ?></span>
                    <?php if (isset($errors['expected_output'])): ?>
                        <span class="field__error" id="bench-expected-error"><?= e($fieldError('expected_output')) ?></span>
                    <?php endif; ?>
                </div>

                <div class="form__actions">
                    <button class="btn" type="submit" data-bench-submit><?= e(t('ai.testbench_run')) ?></button>
                </div>

                <?php /* Client-side progress for the synchronous run (progressive enhancement). */ ?>
                <div class="bench-run">
                    <div class="bench-progress" id="bench-progress" role="status" aria-live="polite" hidden
                         data-stylesheet="<?= e(asset('ai-bench.css')) ?>"
                         data-stage-idle="<?= e(t('ai.testbench_progress_idle')) ?>"
                         data-stage-sending="<?= e(t('ai.testbench_stage_sending')) ?>"
                         data-stage-waiting="<?= e(t('ai.testbench_stage_waiting')) ?>"
                         data-stage-slow="<?= e(t('ai.testbench_stage_slow')) ?>"
                         data-stage-long="<?= e(t('ai.testbench_stage_long')) ?>">
                        <div class="bench-progress__track" aria-hidden="true">
                            <div class="bench-progress__bar" data-bench-bar></div>
                        </div>
                        <p class="bench-progress__label">
                            <strong><?= e(t('ai.testbench_progress_prefix')) ?></strong>
                            <span data-bench-label><?= e(t('ai.testbench_progress_idle')) ?></span>
                            <span class="bench-progress__elapsed" data-bench-elapsed aria-hidden="true"></span>
                        </p>
                        <p class="admin-muted small"><?= e(t('ai.testbench_progress_hint')) ?></p>
                    </div>
                </div>
            </form>
        </div>

        <aside class="ai-bench-panel" aria-labelledby="ai-bench-result-title">
            <h3 class="panel-title" id="ai-bench-result-title"><?= e(t('ai.testbench_result_title')) ?></h3>
            <?php if ($benchResult !== null): ?>
                <div class="alert alert--success" role="status"><?= e(t('ai.testbench_result_notice')) ?></div>
                <dl class="meta-grid">
                    <div class="meta-item">
                        <dt><?= e(t('ai.testbench_model')) ?></dt>
                        <dd class="mono"><?= e((string) ($benchResult['model'] ?? '')) ?></dd>
                    </div>
                    <div class="meta-item">
                        <dt><?= e(t('ai.testbench_latency')) ?></dt>
                        <dd><?= e((string) ($benchResult['latency_ms'] ?? 0)) ?> ms</dd>
                    </div>
                    <div class="meta-item">
                        <dt><?= e(t('ai.testbench_chars')) ?></dt>
                        <dd><?= e((string) ($benchResult['output_chars'] ?? 0)) ?></dd>
                    </div>
                </dl>
                <div class="ai-bench-result__output"><?= nl2br(e((string) ($benchResult['output'] ?? ''))) ?></div>

                <h4 class="panel-title"><?= e(t('ai.testbench_save_title')) ?></h4>
                <p class="admin-muted small"><?= e(t('ai.testbench_save_hint')) ?></p>
                <form class="form" method="post" action="<?= e(url('/admin/ai/examples')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="registry" value="<?= e((string) ($benchResult['registry'] ?? 'gold_standard')) ?>">
                    <input type="hidden" name="input_text" value="<?= e((string) ($benchResult['input_text'] ?? '')) ?>">
                    <input type="hidden" name="output_text" value="<?= e((string) ($benchResult['output'] ?? '')) ?>">
                    <div class="field" data-invalid="<?= e($fieldInvalid('title')) ?>">
                        <label for="bench-example-title"><?= e(t('ai.testbench_save_title_label')) ?></label>
                        <input id="bench-example-title" name="title" type="text" maxlength="160" required
                               placeholder="<?= e(t('ai.testbench_save_title_placeholder')) ?>" value="<?= e(old('title')) ?>">
                        <?php if (isset($errors['title'])): ?>
                            <span class="field__error"><?= e(form_error('title', $errors)) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="form__actions">
                        <button class="btn btn--secondary" type="submit"><?= e(t('ai.testbench_save_button')) ?></button>
                    </div>
                </form>
            <?php else: ?>
                <p class="admin-muted"><?= e(t('ai.testbench_sub')) ?></p>
            <?php endif; ?>
        </aside>
    </div>

    <p class="admin-muted small"><?= e(t('ai.testbench_privacy_warning')) ?></p>
</section>

<section class="panel-card" id="examples" aria-labelledby="ai-examples-title"<?= $tabVisible('bench') ?>>
    <div class="panel-card__head">
        <h2 id="ai-examples-title"><?= e(t('ai.examples_title')) ?></h2>
        <span class="badge badge--neutral"><?= e(t('ai.examples_count', ['count' => count($benchExamples)])) ?></span>
    </div>
    <p class="admin-muted"><?= e(t('ai.examples_sub')) ?></p>
    <p class="admin-muted small"><?= e(t('ai.examples_budget_hint', ['count' => $benchMaxExamples, 'chars' => $benchBudget])) ?></p>

    <?php if ($benchExamples === []): ?>
        <p class="admin-muted mb-0"><?= e(t('ai.examples_none')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data admin-table ai-examples-table">
                <caption class="visually-hidden"><?= e(t('ai.examples_title')) ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('ai.examples_table_title')) ?></th>
                        <th scope="col"><?= e(t('ai.examples_table_registry')) ?></th>
                        <th scope="col"><?= e(t('ai.examples_table_state')) ?></th>
                        <th scope="col"><?= e(t('ai.examples_table_created')) ?></th>
                        <th scope="col"><?= e(t('ai.examples_table_actions')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($benchExamples as $example): ?>
                        <?php $exampleEnabled = (int) $example['enabled'] === 1; ?>
                        <tr>
                            <td>
                                <strong><?= e((string) $example['title']) ?></strong>
                                <?php if ((string) ($example['output_text'] ?? '') !== ''): ?>
                                    <details>
                                        <summary><?= e(t('ai.examples_preview')) ?></summary>
                                        <p class="small muted"><?= e(t('ai.examples_preview_input')) ?></p>
                                        <?= nl2br(e(mb_substr((string) $example['input_text'], 0, 800))) ?>
                                        <p class="small muted"><?= e(t('ai.examples_preview_output')) ?></p>
                                        <?= nl2br(e(mb_substr((string) $example['output_text'], 0, 800))) ?>
                                    </details>
                                <?php endif; ?>
                            </td>
                            <td><?= e($example['registry'] === 'gold_standard' ? t('landing.registry_gs') : t('landing.registry_vcs')) ?></td>
                            <td>
                                <span class="badge <?= $exampleEnabled ? 'badge--normal' : 'badge--neutral' ?>">
                                    <?= e($exampleEnabled ? t('ai.examples_enabled') : t('ai.examples_disabled')) ?>
                                </span>
                            </td>
                            <td class="admin-muted"><?= e(format_date((string) $example['created_at'])) ?></td>
                            <td>
                                <form class="inline-form" method="post" action="<?= e(url('/admin/ai/examples/' . (string) $example['id'] . '/toggle')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="enabled" value="<?= $exampleEnabled ? '0' : '1' ?>">
                                    <button class="btn btn--ghost btn--sm" type="submit">
                                        <?= e($exampleEnabled ? t('ai.examples_disable') : t('ai.examples_enable')) ?>
                                    </button>
                                </form>
                                <form class="inline-form" method="post" action="<?= e(url('/admin/ai/examples/' . (string) $example['id'] . '/delete')) ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn btn--ghost btn--sm" type="submit"><?= e(t('ai.examples_delete')) ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php /* Run-test progress enhancement (external, same-origin asset for CSP self). */ ?>
<script src="<?= e(asset('ai-bench.js')) ?>" defer></script>
