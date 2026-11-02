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

$status = (string) $settings['status'];
$badgeClass = match ($status) {
    'ready' => 'badge--normal',
    'disabled' => 'badge--draft',
    default => 'badge--neutral',
};
$maskedKey = $settings['has_stored_key'] ? '••••••••' . (string) ($settings['api_key_last4'] ?? '') : '';
$providerValue = old('provider', (string) $settings['provider']);
$baseUrlValue = old('base_url', (string) $settings['base_url']);
$modelValue = old('model', (string) $settings['model']);
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
            <input id="ai-model" name="model" type="text" required maxlength="120" value="<?= e($modelValue) ?>"
                   <?= isset($errors['model']) ? 'aria-invalid="true" aria-describedby="ai-model-hint ai-model-error"' : 'aria-describedby="ai-model-hint"' ?>>
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
        <input type="hidden" name="model" value="<?= e($modelValue) ?>">
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
