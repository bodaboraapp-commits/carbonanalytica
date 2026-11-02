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
 * @var array<string, mixed> $user
 * @var array<string, mixed> $settings
 * @var array<string, string> $errors
 * @var int $taskBadge
 */

// Defensive defaults: the page can be rendered without the bench payload (e.g.
// in a template-only test) without notices.
$bench = is_array($bench ?? null) ? $bench : [];
$benchResult = is_array($bench['result'] ?? null) ? $bench['result'] : null;
$benchForm = is_array($bench['form'] ?? null) ? $bench['form'] : [];
$benchExamples = is_array($bench['examples'] ?? null) ? $bench['examples'] : [];
$benchRegistries = is_array($bench['registries'] ?? null) && $bench['registries'] !== [] ? $bench['registries'] : ['gold_standard', 'verra_vcs'];
$benchMaxInput = (int) ($bench['max_input_chars'] ?? 20000);
$benchMaxKb = (int) ($bench['max_upload_kb'] ?? 256);
$benchBudget = (int) ($bench['budget_chars'] ?? 2000);
$benchMaxExamples = (int) ($bench['max_examples'] ?? 3);
$benchInput = old('input_text', (string) ($benchForm['input_text'] ?? ''));
$benchExpected = old('expected_output', (string) ($benchForm['expected_output'] ?? ''));
$benchRegistry = old('registry', (string) ($benchForm['registry'] ?? 'gold_standard'));

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
?>
<div class="admin-page-head">
    <div>
        <h1><?= e(t('ai.title')) ?></h1>
        <p><?= e(t('ai.sub')) ?></p>
    </div>
    <span class="badge <?= e($badgeClass) ?>"><?= e(t(\App\Services\AiEngineService::statusLabelKey($status))) ?></span>
</div>

<?php if (!$settings['encryption_ready']): ?>
    <div class="alert alert--error" role="alert">
        <strong><?= e(t('ai.encryption_missing')) ?></strong> <?= e(t('ai.encryption_missing_help')) ?>
    </div>
<?php endif; ?>

<div class="console-columns">
    <section class="panel-card" aria-labelledby="ai-status-title">
        <div class="panel-card__head">
            <h2 id="ai-status-title"><?= e(t('ai.status_title')) ?></h2>
        </div>

        <dl class="meta-grid">
            <div class="meta-item">
                <dt><?= e(t('ai.configured')) ?></dt>
                <dd><?= e($settings['configured'] ? t('ai.configured_yes') : t('ai.configured_no')) ?></dd>
            </div>
            <div class="meta-item">
                <dt><?= e(t('ai.key_last4')) ?></dt>
                <dd class="mono"><?= e($maskedKey !== '' ? $maskedKey : t('ai.api_key_none')) ?></dd>
            </div>
            <div class="meta-item">
                <dt><?= e(t('ai.provider')) ?></dt>
                <dd><?= e(t(\App\Services\AiEngineService::providerLabelKey((string) $settings['provider']))) ?></dd>
            </div>
            <div class="meta-item">
                <dt><?= e(t('ai.model')) ?></dt>
                <dd class="mono"><?= e((string) $settings['model']) ?></dd>
            </div>
            <div class="meta-item">
                <dt><?= e(t('ai.encryption_ready')) ?></dt>
                <dd><?= e($settings['encryption_ready'] ? t('ai.configured_yes') : t('ai.encryption_missing')) ?></dd>
            </div>
            <div class="meta-item">
                <dt><?= e(t('ai.last_saved')) ?></dt>
                <dd>
                    <?php if ($settings['updated_at'] !== null): ?>
                        <?= e(format_date((string) $settings['updated_at'])) ?>
                        <?php if ($settings['updated_by_name'] !== null): ?>
                            · <?= e(t('ai.by')) ?> <?= e((string) $settings['updated_by_name']) ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <?= e(t('ai.never')) ?>
                    <?php endif; ?>
                </dd>
            </div>
        </dl>

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
    </section>

    <section class="panel-card" aria-labelledby="ai-test-title">
        <div class="panel-card__head">
            <h2 id="ai-test-title"><?= e(t('ai.test_title')) ?></h2>
        </div>
        <p class="admin-muted"><?= e(t('ai.test_sub')) ?></p>
        <?php if ($settings['configured'] && $settings['encryption_ready']): ?>
            <form class="inline-form" method="post" action="<?= e(url('/admin/ai/test')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn--secondary btn--sm" type="submit"><?= e(t('ai.test_button')) ?></button>
            </form>
        <?php else: ?>
            <button class="btn btn--secondary btn--sm" type="button" disabled><?= e(t('ai.test_button')) ?></button>
            <p class="admin-muted small"><?= e(t('ai.test_unavailable')) ?></p>
        <?php endif; ?>
    </section>
</div>

<section class="panel-card" id="test-bench" aria-labelledby="ai-bench-title">
    <div class="panel-card__head">
        <h2 id="ai-bench-title"><?= e(t('ai.testbench_title')) ?></h2>
        <span class="badge badge--gold"><?= e(t('ai.testbench_badge')) ?></span>
    </div>
    <p class="admin-muted"><?= e(t('ai.testbench_sub')) ?></p>

    <div class="alert alert--info" role="note">
        <strong><?= e(t('ai.testbench_not_finetune')) ?></strong>
        <?= e(t('ai.testbench_not_finetune_body')) ?>
    </div>

    <?php if (isset($errors['input_text']) || isset($errors['input_file']) || isset($errors['registry']) || isset($errors['expected_output'])): ?>
        <div class="alert alert--error" role="alert"><?= e(t('error.validation_error')) ?></div>
    <?php endif; ?>

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
                      placeholder="<?= e(t('ai.testbench_input_placeholder')) ?>"><?= e($benchInput) ?></textarea>
            <span class="admin-muted small"><?= e(t('ai.testbench_input_hint', ['max' => $benchMaxInput])) ?></span>
            <?php if (isset($errors['input_text'])): ?>
                <span class="field__error"><?= e(form_error('input_text', $errors)) ?></span>
            <?php endif; ?>
        </div>

        <div class="field" data-invalid="<?= e($fieldInvalid('input_file')) ?>">
            <label for="bench-file">
                <?= e(t('ai.testbench_file')) ?>
                <span class="muted">(<?= e(t('common.optional')) ?>)</span>
            </label>
            <input id="bench-file" name="input_file" type="file" accept=".txt,.md,text/plain,text/markdown">
            <span class="admin-muted small"><?= e(t('ai.testbench_file_hint', ['kb' => $benchMaxKb])) ?></span>
            <?php if (isset($errors['input_file'])): ?>
                <span class="field__error"><?= e(form_error('input_file', $errors)) ?></span>
            <?php endif; ?>
        </div>

        <div class="field" data-invalid="<?= e($fieldInvalid('expected_output')) ?>">
            <label for="bench-expected">
                <?= e(t('ai.testbench_expected')) ?>
                <span class="muted">(<?= e(t('common.optional')) ?>)</span>
            </label>
            <textarea id="bench-expected" name="expected_output" rows="3" maxlength="<?= e((string) $benchMaxInput) ?>"><?= e($benchExpected) ?></textarea>
            <span class="admin-muted small"><?= e(t('ai.testbench_expected_hint')) ?></span>
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

    <?php if ($benchResult !== null): ?>
        <div class="alert alert--success" role="status"><?= e(t('ai.testbench_result_notice')) ?></div>
        <h3 class="panel-title"><?= e(t('ai.testbench_result_title')) ?></h3>
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
        <div class="card">
            <?= nl2br(e((string) ($benchResult['output'] ?? ''))) ?>
        </div>

        <h3 class="panel-title"><?= e(t('ai.testbench_save_title')) ?></h3>
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
    <?php endif; ?>

    <p class="admin-muted small"><?= e(t('ai.testbench_privacy_warning')) ?></p>
</section>

<section class="panel-card" id="examples" aria-labelledby="ai-examples-title">
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
            <table class="data admin-table">
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

<section class="panel-card" aria-labelledby="ai-settings-title">
    <div class="panel-card__head">
        <h2 id="ai-settings-title"><?= e(t('ai.settings_title')) ?></h2>
        <span class="admin-muted small"><?= e(t('ai.settings_sub')) ?></span>
    </div>

    <?php if ($errors !== []): ?>
        <div class="alert alert--error" role="alert"><?= e(t('error.validation_error')) ?></div>
    <?php endif; ?>

    <form class="form" method="post" action="<?= e(url('/admin/ai')) ?>" novalidate autocomplete="off">
        <?= csrf_field() ?>

        <div class="field" data-invalid="<?= e($fieldInvalid('provider')) ?>">
            <label for="ai-provider"><?= e(t('ai.provider')) ?></label>
            <select id="ai-provider" name="provider">
                <?php foreach ($settings['providers'] as $providerSlug): ?>
                    <option value="<?= e((string) $providerSlug) ?>" <?= $providerValue === $providerSlug ? 'selected' : '' ?>>
                        <?= e(t(\App\Services\AiEngineService::providerLabelKey((string) $providerSlug))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['provider'])): ?>
                <span class="field__error"><?= e(form_error('provider', $errors)) ?></span>
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
            <span class="admin-muted small" id="ai-base-url-hint"><?= e(t('ai.base_url_hint')) ?></span>
            <?php if (isset($errors['base_url'])): ?>
                <span class="field__error" id="ai-base-url-error"><?= e(form_error('base_url', $errors)) ?></span>
            <?php endif; ?>
        </div>

        <div class="field" data-invalid="<?= e($fieldInvalid('model')) ?>">
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
            <span class="admin-muted small" id="ai-model-custom-hint"><?= e(t('ai.model_custom_hint')) ?></span>
            <span class="admin-muted small" id="ai-model-hint"><?= e(t('ai.model_hint')) ?></span>
            <?php if (isset($errors['model'])): ?>
                <span class="field__error" id="ai-model-error"><?= e(form_error('model', $errors)) ?></span>
            <?php endif; ?>
        </div>

        <div class="field" data-invalid="<?= e($fieldInvalid('temperature')) ?>">
            <label for="ai-temperature"><?= e(t('ai.temperature')) ?></label>
            <input id="ai-temperature" name="temperature" type="number" min="0" max="2" step="0.05" value="<?= e($temperatureValue) ?>"
                   aria-describedby="ai-temperature-hint">
            <span class="admin-muted small" id="ai-temperature-hint"><?= e(t('ai.temperature_hint')) ?></span>
            <?php if (isset($errors['temperature'])): ?>
                <span class="field__error"><?= e(form_error('temperature', $errors)) ?></span>
            <?php endif; ?>
        </div>

        <div class="field" data-invalid="<?= e($fieldInvalid('max_tokens')) ?>">
            <label for="ai-max-tokens"><?= e(t('ai.max_tokens')) ?></label>
            <input id="ai-max-tokens" name="max_tokens" type="number" min="50" max="32000" step="1" value="<?= e($maxTokensValue) ?>"
                   aria-describedby="ai-max-tokens-hint">
            <span class="admin-muted small" id="ai-max-tokens-hint"><?= e(t('ai.max_tokens_hint')) ?></span>
            <?php if (isset($errors['max_tokens'])): ?>
                <span class="field__error"><?= e(form_error('max_tokens', $errors)) ?></span>
            <?php endif; ?>
        </div>

        <div class="field" data-invalid="<?= e($fieldInvalid('api_key')) ?>">
            <label for="ai-api-key"><?= e(t('ai.api_key')) ?></label>
            <input id="ai-api-key" name="api_key" type="password" autocomplete="new-password" spellcheck="false"
                   maxlength="512" value=""
                   placeholder="<?= e($settings['has_stored_key'] ? t('ai.api_key_placeholder_keep') : t('ai.api_key_placeholder_new')) ?>"
                   aria-describedby="ai-api-key-hint">
            <span class="admin-muted small" id="ai-api-key-hint"><?= e(t('ai.api_key_write_only')) ?></span>
            <?php if (isset($errors['api_key'])): ?>
                <span class="field__error"><?= e(form_error('api_key', $errors)) ?></span>
            <?php endif; ?>
            <?php if ($settings['has_stored_key']): ?>
                <label class="small admin-muted">
                    <input type="checkbox" name="clear_api_key" value="1">
                    <?= e(t('ai.clear_key')) ?>
                </label>
            <?php endif; ?>
        </div>

        <div class="field">
            <label>
                <input type="checkbox" name="enabled" value="1" <?= $enabledChecked ? 'checked' : '' ?>>
                <?= e(t('ai.enabled')) ?>
            </label>
            <span class="admin-muted small"><?= e(t('ai.enabled_hint')) ?></span>
        </div>

        <div class="form__actions">
            <button class="btn" type="submit"><?= e(t('common.save')) ?></button>
        </div>
        <p class="form__meta"><?= e(t('ai.version_note')) ?></p>
    </form>
</section>

<section class="panel-card" aria-labelledby="ai-skills-title">
    <div class="panel-card__head">
        <h2 id="ai-skills-title"><?= e(t('ai.skills_title')) ?></h2>
    </div>
    <p class="admin-muted"><?= e(t('ai.skills_sub')) ?></p>

    <form class="form" method="post" action="<?= e(url('/admin/ai')) ?>" novalidate autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="provider" value="<?= e($providerValue) ?>">
        <input type="hidden" name="base_url" value="<?= e($baseUrlValue) ?>">
        <input type="hidden" name="model" value="<?= e($modelSelectValue) ?>">
        <?php if ($modelCustomValue !== ''): ?>
            <input type="hidden" name="model_custom" value="<?= e($modelCustomValue) ?>">
        <?php endif; ?>
        <input type="hidden" name="temperature" value="<?= e($temperatureValue) ?>">
        <input type="hidden" name="max_tokens" value="<?= e($maxTokensValue) ?>">
        <?php if ($enabledChecked): ?><input type="hidden" name="enabled" value="1"><?php endif; ?>

        <div class="field" data-invalid="<?= e($fieldInvalid('skill_gold_standard')) ?>">
            <label for="ai-skill-gold"><?= e(t('ai.skill_gold_standard')) ?></label>
            <textarea id="ai-skill-gold" name="skill_gold_standard" rows="8" maxlength="20000"
                      aria-describedby="ai-skill-hint"><?= e($skillGoldValue) ?></textarea>
            <?php if (isset($errors['skill_gold_standard'])): ?>
                <span class="field__error"><?= e(form_error('skill_gold_standard', $errors)) ?></span>
            <?php endif; ?>
        </div>

        <div class="field" data-invalid="<?= e($fieldInvalid('skill_verra_vcs')) ?>">
            <label for="ai-skill-verra"><?= e(t('ai.skill_verra_vcs')) ?></label>
            <textarea id="ai-skill-verra" name="skill_verra_vcs" rows="8" maxlength="20000"
                      aria-describedby="ai-skill-hint"><?= e($skillVerraValue) ?></textarea>
            <?php if (isset($errors['skill_verra_vcs'])): ?>
                <span class="field__error"><?= e(form_error('skill_verra_vcs', $errors)) ?></span>
            <?php endif; ?>
        </div>

        <div class="field" data-invalid="<?= e($fieldInvalid('global_instructions')) ?>">
            <label for="ai-global"><?= e(t('ai.global_instructions')) ?></label>
            <textarea id="ai-global" name="global_instructions" rows="5" maxlength="8000"
                      aria-describedby="ai-global-hint"><?= e($globalValue) ?></textarea>
            <span class="admin-muted small" id="ai-global-hint"><?= e(t('ai.global_instructions_hint')) ?></span>
            <?php if (isset($errors['global_instructions'])): ?>
                <span class="field__error"><?= e(form_error('global_instructions', $errors)) ?></span>
            <?php endif; ?>
        </div>

        <p class="admin-muted small" id="ai-skill-hint"><?= e(t('ai.skill_hint')) ?></p>

        <div class="form__actions">
            <button class="btn" type="submit"><?= e(t('common.save')) ?></button>
        </div>
    </form>
</section>

<?php /* Run-test progress enhancement (external, same-origin asset for CSP self). */ ?>
<script src="<?= e(asset('ai-bench.js')) ?>" defer></script>
