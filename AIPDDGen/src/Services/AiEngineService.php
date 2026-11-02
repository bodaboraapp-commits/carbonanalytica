<?php

declare(strict_types=1);

namespace App\Services;

use App\AppError;
use App\Audit;
use App\Config;
use App\Db;

/**
 * AI engine settings: provider configuration and the admin-managed drafting
 * skill, owned by platform staff (/admin/ai).
 *
 * The LLM API key is encrypted at rest with AES-256-GCM using the deployment
 * secret `AI_ENGINE_SECRET`. The database stores only the ciphertext and the
 * last four characters (for the masked write-only field). No method of this
 * service returns the plaintext key, the ciphertext or any form of the key in a
 * page payload: `publicSettings()` is the only shape templates see.
 *
 * Every change is audited as `ai_engine.*`. Staff only: a tenant account gets a
 * 404 through the existing TaskCenterService staff guard.
 */
final class AiEngineService
{
    /** Provider presets. All of them speak the OpenAI-compatible protocol. */
    public const PROVIDERS = ['openai', 'claude', 'deepseek', 'openrouter', 'ollama', 'other'];

    /**
     * Suggested API root per provider (the admin can still override it).
     *
     * Anthropic (Claude) exposes an OpenAI SDK compatibility layer under
     * https://api.anthropic.com/v1 — the same chat-completions shape this client
     * speaks. Anthropic documents that layer for testing/comparison; for a
     * production gateway an OpenAI-compatible router (e.g. OpenRouter with an
     * `anthropic/claude-*` model) is the safer option, and both work here
     * because the preset is only a label + suggested URL.
     */
    public const BASE_URLS = [
        'openai' => 'https://api.openai.com/v1',
        'claude' => 'https://api.anthropic.com/v1',
        'deepseek' => 'https://api.deepseek.com/v1',
        'openrouter' => 'https://openrouter.ai/api/v1',
        'ollama' => 'http://127.0.0.1:11434/v1',
        'other' => '',
    ];

    /**
     * Curated, provider-aware model choices for the console dropdown.
     *
     * This is a convenience catalogue, not a restriction: the admin can always
     * pick "Other / custom model…" and store any model id the provider accepts
     * (catalogues change faster than releases). Nothing here touches the network.
     */
    public const MODEL_PRESETS = [
        'openai' => ['gpt-4o-mini', 'gpt-4o', 'gpt-4.1', 'gpt-4.1-mini', 'o3-mini'],
        'claude' => ['claude-sonnet-4-5', 'claude-opus-4-1', 'claude-haiku-4-5'],
        'deepseek' => ['deepseek-chat', 'deepseek-reasoner'],
        'openrouter' => ['anthropic/claude-sonnet-4-5', 'openai/gpt-4o', 'deepseek/deepseek-chat'],
        'ollama' => ['llama3.1', 'mistral', 'qwen2.5'],
        'other' => [],
    ];

    /**
     * The curated model choices for a provider (defaults to the stored provider).
     *
     * @return list<string>
     */
    public static function modelPresets(?string $provider = null): array
    {
        $provider ??= (string) self::settings()['provider'];

        /** @var list<string> $presets */
        $presets = self::MODEL_PRESETS[$provider] ?? [];

        return $presets;
    }

    /** Is this model one of the curated choices for the provider? */
    public static function isPresetModel(string $provider, string $model): bool
    {
        return in_array($model, self::modelPresets($provider), true);
    }

    public const MAX_BASE_URL = 500;
    public const MAX_MODEL = 120;
    public const MAX_KEY_LENGTH = 512;
    // Real registry skill documents run 20-100 KB of markdown, so the caps are
    // generous; the DB columns are MEDIUMTEXT and the prompt budget is applied
    // separately when drafting.
    public const MAX_SKILL_LENGTH = 100000;
    public const MAX_GLOBAL_INSTRUCTIONS = 20000;
    public const MIN_MAX_TOKENS = 50;
    public const MAX_MAX_TOKENS = 32000;

    /**
     * Skill documents can be uploaded as markdown instead of pasted. A valid
     * upload replaces the matching textarea value on save; the file is read into
     * the skill text and never stored on disk. Character limits still apply.
     */
    public const SKILL_UPLOAD_EXTENSIONS = ['md'];
    public const SKILL_UPLOAD_MAX_BYTES = 1048576;

    /** Skill field => upload input name (all three skills: GS, Verra, global). */
    public const SKILL_UPLOAD_FIELDS = [
        'skill_gold_standard' => 'skill_gold_standard_file',
        'skill_verra_vcs' => 'skill_verra_vcs_file',
        'global_instructions' => 'global_instructions_file',
    ];

    /**
     * Merge uploaded `.md` skill documents into settings input.
     *
     * A valid upload replaces the textarea content for that skill. Files are
     * validated (extension + size), read once into text and never written to
     * disk; existing character limits are enforced by save()/validate().
     *
     * @param array<string, mixed> $input POST fields
     * @param array<string, mixed> $files $_FILES
     * @return array<string, mixed> the input with uploaded skill text applied
     */
    public static function applySkillUploads(array $input, array $files): array
    {
        $errors = [];

        foreach (self::SKILL_UPLOAD_FIELDS as $field => $fileField) {
            $upload = $files[$fileField] ?? null;
            if (!is_array($upload)) {
                continue;
            }
            $errorCode = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($errorCode === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($errorCode !== UPLOAD_ERR_OK) {
                $errors[$field] = 'invalid_choice';
                continue;
            }

            $name = (string) ($upload['name'] ?? '');
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($extension, self::SKILL_UPLOAD_EXTENSIONS, true)) {
                $errors[$field] = 'invalid_choice';
                continue;
            }
            if ((int) ($upload['size'] ?? 0) > self::SKILL_UPLOAD_MAX_BYTES) {
                $errors[$field] = 'too_long';
                continue;
            }

            $content = file_get_contents((string) ($upload['tmp_name'] ?? ''));
            if (!is_string($content)) {
                $errors[$field] = 'invalid_choice';
                continue;
            }
            // Strip a UTF-8 BOM; keep the rest verbatim (markdown structure matters).
            $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);
            $content = trim($content);
            if ($content === '') {
                $errors[$field] = 'required';
                continue;
            }

            $input[$field] = $content;
        }

        if ($errors !== []) {
            throw AppError::validation($errors);
        }

        return $input;
    }

    /** @return array<string, mixed> the singleton row (created with defaults if missing) */
    public static function settings(): array
    {
        $row = Db::selectOne('SELECT * FROM ai_engine_settings WHERE id = 1');
        if ($row !== null) {
            return $row;
        }

        // A fresh/truncated database (tests, setup) has no row yet. Recreate the
        // singleton with the column defaults; the migration also inserts it.
        Db::insert('ai_engine_settings', ['id' => 1]);
        $row = Db::selectOne('SELECT * FROM ai_engine_settings WHERE id = 1');
        if ($row === null) {
            throw AppError::internal();
        }

        return $row;
    }

    /**
     * Everything the staff page needs — and nothing secret.
     *
     * @return array<string, mixed>
     */
    public static function publicSettings(): array
    {
        $row = self::settings();
        $envKey = trim((string) Config::get('ai_engine.api_key', ''));
        $storedKey = self::storedApiKey($row);

        $hasStoredKey = $storedKey !== null;
        $hasKey = $hasStoredKey || $envKey !== '';
        $enabled = (int) $row['enabled'] === 1;

        $status = 'not_configured';
        if ($hasKey) {
            $status = $enabled ? 'ready' : 'disabled';
        }

        $updatedByName = null;
        if ($row['updated_by_user_id'] !== null) {
            $actor = Db::selectOne('SELECT name FROM users WHERE id = ?', [(string) $row['updated_by_user_id']]);
            $updatedByName = isset($actor['name']) ? (string) $actor['name'] : null;
        }

        return [
            'provider' => (string) $row['provider'],
            'base_url' => (string) $row['base_url'],
            'model' => (string) $row['model'],
            'temperature' => (string) $row['temperature'],
            'max_tokens' => (int) $row['max_tokens'],
            'skill_gold_standard' => (string) ($row['skill_gold_standard'] ?? ''),
            'skill_verra_vcs' => (string) ($row['skill_verra_vcs'] ?? ''),
            'global_instructions' => (string) ($row['global_instructions'] ?? ''),
            'enabled' => $enabled,
            'status' => $status,
            'configured' => $hasKey,
            'has_stored_key' => $hasStoredKey,
            'env_fallback' => !$hasStoredKey && $envKey !== '',
            'api_key_last4' => $row['api_key_last4'] !== null ? (string) $row['api_key_last4'] : null,
            'encryption_ready' => self::encryptionReady(),
            'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
            'updated_by_name' => $updatedByName,
            'providers' => self::PROVIDERS,
            'base_urls' => self::BASE_URLS,
            'model_presets' => self::modelPresets((string) $row['provider']),
            'default_base_url' => (string) ($row['base_url'] ?? ''),
        ];
    }

    /**
     * Save the console settings. The key is write-only: send `api_key` to
     * replace it, set `clear_api_key` to remove it, omit both to keep it.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> the new public settings
     */
    public static function save(string $actorUserId, array $input): array
    {
        TaskCenterService::assertStaff($actorUserId);
        $row = self::settings();

        $fields = self::validate($input, $row);

        $newKey = trim((string) ($input['api_key'] ?? ''));
        $clearKey = (string) ($input['clear_api_key'] ?? '') === '1';
        $replaceKey = $newKey !== '';

        if ($replaceKey && !self::encryptionReady()) {
            throw new AppError(
                'ai_engine_secret_missing',
                503,
                'Add AI_ENGINE_SECRET to the environment before storing an API key.',
            );
        }

        $ciphertext = $row['api_key_ciphertext'] !== null ? (string) $row['api_key_ciphertext'] : null;
        $last4 = $row['api_key_last4'] !== null ? (string) $row['api_key_last4'] : null;
        if ($clearKey) {
            $ciphertext = null;
            $last4 = null;
        } elseif ($replaceKey) {
            $ciphertext = self::encrypt($newKey);
            $last4 = substr($newKey, -4);
        }

        // "Enabled" without any usable key would produce a vendor error on the
        // first real draft; refuse the save with an actionable message instead.
        $hasKeyAfterSave = $ciphertext !== null || trim((string) Config::get('ai_engine.api_key', '')) !== '';
        if ($fields['enabled'] && !$hasKeyAfterSave) {
            throw new AppError(
                'ai_engine_key_required',
                422,
                'Store an API key before enabling the AI engine.',
            );
        }

        Db::transaction(static function () use ($row, $fields, $ciphertext, $last4, $actorUserId, $replaceKey, $clearKey): void {
            Db::execute(
                'UPDATE ai_engine_settings
                 SET provider = ?, base_url = ?, model = ?, temperature = ?, max_tokens = ?,
                     skill_gold_standard = ?, skill_verra_vcs = ?, global_instructions = ?,
                     enabled = ?, api_key_ciphertext = ?, api_key_last4 = ?,
                     updated_by_user_id = ?, updated_at = NOW(3)
                 WHERE id = 1',
                [
                    $fields['provider'],
                    $fields['base_url'],
                    $fields['model'],
                    $fields['temperature'],
                    $fields['max_tokens'],
                    $fields['skill_gold_standard'],
                    $fields['skill_verra_vcs'],
                    $fields['global_instructions'],
                    $fields['enabled'] ? 1 : 0,
                    $ciphertext,
                    $last4,
                    $actorUserId,
                ],
            );

            Audit::record([
                'actor_user_id' => $actorUserId,
                'action' => 'ai_engine.settings_saved',
                'target_type' => 'ai_engine',
                'target_id' => '1',
                // Field names and booleans only: never the key, the ciphertext
                // or even its last four characters.
                'metadata' => [
                    'provider' => $fields['provider'],
                    'model' => $fields['model'],
                    'enabled' => $fields['enabled'],
                    'key_set' => $replaceKey,
                    'key_cleared' => $clearKey,
                    'skills_set' => [
                        'gold_standard' => $fields['skill_gold_standard'] !== '',
                        'verra_vcs' => $fields['skill_verra_vcs'] !== '',
                    ],
                ],
            ]);
        });

        return self::publicSettings();
    }

    /**
     * Load the settings the LLM client needs, including the decrypted key.
     *
     * @return array{provider: string, base_url: string, model: string, temperature: float, max_tokens: int, api_key: string, timeout: int}
     */
    public static function clientSettings(): array
    {
        $row = self::settings();
        $key = self::resolvedApiKey($row);
        if ($key === null) {
            throw new AppError(
                'ai_engine_not_configured',
                503,
                'The AI engine has no API key. Ask platform staff to configure it on /admin/ai.',
            );
        }

        return [
            'provider' => (string) $row['provider'],
            'base_url' => (string) $row['base_url'],
            'model' => (string) $row['model'],
            'temperature' => (float) $row['temperature'],
            'max_tokens' => (int) $row['max_tokens'],
            'api_key' => $key,
            'timeout' => (int) Config::get('ai_engine.timeout', LlmClient::DEFAULT_TIMEOUT),
        ];
    }

    /** True when the engine is switched on and a key is available. */
    public static function isReady(): bool
    {
        $row = self::settings();

        return (int) $row['enabled'] === 1 && self::resolvedApiKey($row) !== null;
    }

    /** The admin-managed rules for a registry, injected into the system prompt. */
    public static function skillForRegistry(string $registry, ?array $row = null): string
    {
        $row ??= self::settings();

        return match ($registry) {
            'gold_standard' => (string) ($row['skill_gold_standard'] ?? ''),
            'verra_vcs' => (string) ($row['skill_verra_vcs'] ?? ''),
            default => '',
        };
    }

    public static function globalInstructions(?array $row = null): string
    {
        $row ??= self::settings();

        return (string) ($row['global_instructions'] ?? '');
    }

    /** Skill sources, most specific first (see resolveSkill()). */
    public const SKILL_SOURCE_VERSION = 'template_version';
    public const SKILL_SOURCE_TEMPLATE = 'template';
    public const SKILL_SOURCE_REGISTRY = 'registry';
    public const SKILL_SOURCE_REGISTRY_SETTINGS = 'registry_settings';
    public const SKILL_SOURCE_GLOBAL = 'global';
    public const SKILL_SOURCE_NONE = 'none';

    public const SKILL_STATUS_PENDING = 'pending';
    public const SKILL_STATUS_READY = 'ready';
    public const SKILL_STATUS_MISSING = 'missing';

    /**
     * Resolve the admin-managed skill used for one draft.
     *
     * Order: per-template-version SKILL.md -> per-template skill -> registry-level
     * fallback row -> legacy registry skill from ai_engine_settings -> global
     * instructions. Only enabled rows are considered. A PENDING row at the most
     * specific level blocks drafting (it does not silently fall through), so the
     * UI can ask for the missing SKILL.md.
     *
     * @return array{status: string, text: string, source: string, source_label_key: string, name: string, template_label: string, template_id: ?string, template_version_id: ?string}
     */
    public static function resolveSkill(?string $templateVersionId = null, ?string $templateId = null, ?string $registry = null): array
    {
        $templateLabel = '';
        $version = null;

        if ($templateVersionId !== null && $templateVersionId !== '') {
            $version = Db::selectOne(
                'SELECT tv.id, tv.template_id, tv.version_label, tpl.name AS template_name, tpl.registry
                 FROM template_versions tv
                 INNER JOIN templates tpl ON tpl.id = tv.template_id
                 WHERE tv.id = ? LIMIT 1',
                [$templateVersionId],
            );
            if ($version !== null) {
                $templateId ??= (string) $version['template_id'];
                $registry ??= (string) $version['registry'];
                $templateLabel = trim((string) $version['template_name'] . ' ' . (string) $version['version_label']);
            }
        }

        if ($templateVersionId !== null && $templateVersionId !== '') {
            $row = Db::selectOne(
                'SELECT * FROM ai_engine_skills WHERE template_version_id = ? AND enabled = 1 LIMIT 1',
                [$templateVersionId],
            );
            $resolved = self::skillFromRow($row, self::SKILL_SOURCE_VERSION, $templateLabel, $templateId, $templateVersionId);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        if ($templateId !== null && $templateId !== '') {
            $row = Db::selectOne(
                'SELECT * FROM ai_engine_skills WHERE template_id = ? AND template_version_id IS NULL AND enabled = 1 LIMIT 1',
                [$templateId],
            );
            $resolved = self::skillFromRow($row, self::SKILL_SOURCE_TEMPLATE, $templateLabel, $templateId, $templateVersionId);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        if ($registry !== null && $registry !== '') {
            $row = Db::selectOne(
                'SELECT * FROM ai_engine_skills WHERE registry = ? AND template_id IS NULL AND template_version_id IS NULL AND enabled = 1 LIMIT 1',
                [$registry],
            );
            $resolved = self::skillFromRow($row, self::SKILL_SOURCE_REGISTRY, $templateLabel, $templateId, $templateVersionId);
            if ($resolved !== null) {
                return $resolved;
            }

            // Legacy fallback: the registry skill stored in ai_engine_settings.
            $legacy = trim(self::skillForRegistry($registry));
            if ($legacy !== '') {
                return self::skillArray(self::SKILL_STATUS_READY, $legacy, self::SKILL_SOURCE_REGISTRY_SETTINGS, 'Registry skill', $templateLabel, $templateId, $templateVersionId);
            }
        }

        $global = trim(self::globalInstructions());
        if ($global !== '') {
            return self::skillArray(self::SKILL_STATUS_READY, $global, self::SKILL_SOURCE_GLOBAL, 'Global instructions', $templateLabel, $templateId, $templateVersionId);
        }

        return self::skillArray(self::SKILL_STATUS_MISSING, '', self::SKILL_SOURCE_NONE, '', $templateLabel, $templateId, $templateVersionId);
    }

    /** Translation key for a resolved skill source. */
    public static function skillSourceLabelKey(string $source): string
    {
        return match ($source) {
            self::SKILL_SOURCE_VERSION => 'ai.skill_source_version',
            self::SKILL_SOURCE_TEMPLATE => 'ai.skill_source_template',
            self::SKILL_SOURCE_REGISTRY => 'ai.skill_source_registry',
            self::SKILL_SOURCE_REGISTRY_SETTINGS => 'ai.skill_source_registry_settings',
            self::SKILL_SOURCE_GLOBAL => 'ai.skill_source_global',
            default => 'ai.skill_source_none',
        };
    }

    /**
     * Data for the dynamic Skill tab: per-template/per-version SKILL.md rows
     * grouped by registry, registry fallbacks + global instructions and counts.
     *
     * @return array{registries: list<array<string, mixed>>, global: array{text: string, chars: int}, totals: array{versions: int, pending: int, ready: int, missing: int}, skill_max_chars: int, skill_max_bytes: int}
     */
    public static function skillTabPayload(): array
    {
        $versionRows = Db::select(
            "SELECT tv.id AS template_version_id, tv.version_label, tv.status AS version_status, tv.effective_date,
                    tpl.id AS template_id, tpl.name AS template_name, tpl.registry,
                    sk.id AS skill_id, sk.name AS skill_name, sk.status AS skill_status,
                    sk.enabled AS skill_enabled, sk.source_filename, sk.updated_at AS skill_updated_at,
                    CASE WHEN sk.skill_text IS NULL OR TRIM(sk.skill_text) = '' THEN 0 ELSE 1 END AS has_text
             FROM template_versions tv
             INNER JOIN templates tpl ON tpl.id = tv.template_id
             LEFT JOIN ai_engine_skills sk ON sk.template_version_id = tv.id
             WHERE tpl.kind = 'pdd_template'
             ORDER BY tpl.registry ASC, tpl.name ASC, tv.version_label ASC",
        );
        $templateRows = Db::select(
            'SELECT * FROM ai_engine_skills WHERE template_id IS NOT NULL AND template_version_id IS NULL',
        );
        $fallbackRows = Db::select(
            'SELECT * FROM ai_engine_skills WHERE template_id IS NULL AND template_version_id IS NULL',
        );

        $templateSkillByTemplate = [];
        foreach ($templateRows as $row) {
            $templateSkillByTemplate[(string) $row['template_id']] = self::skillSummary($row);
        }
        $fallbackByRegistry = [];
        foreach ($fallbackRows as $row) {
            $fallbackByRegistry[(string) ($row['registry'] ?? '')] = self::skillSummary($row);
        }

        $registries = [];
        $totals = ['versions' => 0, 'pending' => 0, 'ready' => 0, 'missing' => 0];
        $labels = [
            'gold_standard' => 'landing.registry_gs',
            'verra_vcs' => 'landing.registry_vcs',
        ];

        foreach ($labels as $registry => $labelKey) {
            $templates = [];
            $templateIndex = [];
            foreach ($versionRows as $row) {
                if ((string) $row['registry'] !== $registry) {
                    continue;
                }
                $templateId = (string) $row['template_id'];
                if (!isset($templateIndex[$templateId])) {
                    $templateIndex[$templateId] = count($templates);
                    $templates[] = [
                        'template_id' => $templateId,
                        'template_name' => (string) $row['template_name'],
                        'template_skill' => $templateSkillByTemplate[$templateId] ?? null,
                        'versions' => [],
                    ];
                }
                $skill = null;
                if ($row['skill_id'] !== null) {
                    $skill = [
                        'id' => (string) $row['skill_id'],
                        'name' => (string) ($row['skill_name'] ?? ''),
                        'status' => (string) $row['skill_status'],
                        'enabled' => (int) ($row['skill_enabled'] ?? 1) === 1,
                        'has_text' => (int) ($row['has_text'] ?? 0) === 1,
                        'source_filename' => $row['source_filename'] !== null ? (string) $row['source_filename'] : null,
                        'updated_at' => $row['skill_updated_at'] !== null ? (string) $row['skill_updated_at'] : null,
                    ];
                }
                $status = $skill === null ? self::SKILL_STATUS_MISSING : (string) $skill['status'];
                $totals['versions']++;
                if (isset($totals[$status])) {
                    $totals[$status]++;
                }
                $templates[$templateIndex[$templateId]]['versions'][] = [
                    'template_version_id' => (string) $row['template_version_id'],
                    'version_label' => (string) $row['version_label'],
                    'version_status' => (string) $row['version_status'],
                    'effective_date' => $row['effective_date'] !== null ? (string) $row['effective_date'] : null,
                    'status' => $status,
                    'skill' => $skill,
                ];
            }

            $registries[] = [
                'registry' => $registry,
                'label_key' => $labelKey,
                'fallback' => $fallbackByRegistry[$registry] ?? null,
                'templates' => $templates,
            ];
        }

        $global = trim(self::globalInstructions());

        return [
            'registries' => $registries,
            'global' => ['text' => $global, 'chars' => mb_strlen($global)],
            'totals' => $totals,
            'skill_max_chars' => TemplateLibraryService::SKILL_MAX_CHARS,
            'skill_max_bytes' => TemplateLibraryService::SKILL_MAX_BYTES,
        ];
    }

    /**
     * Build a resolution result from a skill row. Returns null when the row is
     * unusable (ready but empty) so resolution falls through to the next level.
     *
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>|null
     */
    private static function skillFromRow(?array $row, string $source, string $templateLabel, ?string $templateId, ?string $templateVersionId): ?array
    {
        if ($row === null) {
            return null;
        }

        $status = (string) ($row['status'] ?? '');
        $name = (string) ($row['name'] ?? '');
        if ($status === self::SKILL_STATUS_PENDING) {
            return self::skillArray(self::SKILL_STATUS_PENDING, '', $source, $name, $templateLabel, $templateId, $templateVersionId);
        }

        $text = trim((string) ($row['skill_text'] ?? ''));
        if ($status !== self::SKILL_STATUS_READY || $text === '') {
            return null;
        }

        return self::skillArray(self::SKILL_STATUS_READY, $text, $source, $name, $templateLabel, $templateId, $templateVersionId);
    }

    /** @return array{status: string, text: string, source: string, source_label_key: string, name: string, template_label: string, template_id: ?string, template_version_id: ?string} */
    private static function skillArray(string $status, string $text, string $source, string $name, string $templateLabel, ?string $templateId, ?string $templateVersionId): array
    {
        return [
            'status' => $status,
            'text' => $text,
            'source' => $source,
            'source_label_key' => self::skillSourceLabelKey($source),
            'name' => $name,
            'template_label' => $templateLabel,
            'template_id' => $templateId,
            'template_version_id' => $templateVersionId,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: string, name: string, status: string, enabled: bool, has_text: bool, chars: int, source_filename: ?string, updated_at: ?string}
     */
    private static function skillSummary(array $row): array
    {
        $text = trim((string) ($row['skill_text'] ?? ''));

        return [
            'id' => (string) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'enabled' => (int) ($row['enabled'] ?? 1) === 1,
            'has_text' => $text !== '',
            'chars' => mb_strlen($text),
            'source_filename' => $row['source_filename'] !== null ? (string) $row['source_filename'] : null,
            'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        ];
    }

    /**
     * Send a minimal real request to prove the provider configuration works.
     * This is the only staff action that calls the provider outside a draft.
     *
     * @return array{ok: bool, model: string}
     */
    public static function testConnection(string $actorUserId, ?LlmClient $client = null): array
    {
        TaskCenterService::assertStaff($actorUserId);
        $settings = self::clientSettings();
        $client ??= LlmClient::forSettings($settings);

        try {
            $result = $client->chat($settings, [
                ['role' => 'system', 'content' => 'You are a connection test. Reply with the single word OK.'],
                ['role' => 'user', 'content' => 'Reply with OK.'],
            ], 16);
        } catch (AppError $error) {
            Audit::record([
                'actor_user_id' => $actorUserId,
                'action' => 'ai_engine.connection_tested',
                'target_type' => 'ai_engine',
                'target_id' => '1',
                'metadata' => ['ok' => false, 'error' => $error->errorCode],
            ]);
            throw $error;
        }

        Audit::record([
            'actor_user_id' => $actorUserId,
            'action' => 'ai_engine.connection_tested',
            'target_type' => 'ai_engine',
            'target_id' => '1',
            'metadata' => ['ok' => true, 'model' => $result['model']],
        ]);

        return ['ok' => true, 'model' => $result['model']];
    }

    /** True when AI_ENGINE_SECRET is configured, i.e. keys can be encrypted. */
    public static function encryptionReady(): bool
    {
        return self::secret() !== '';
    }

    /** The provider label translation key for a stored preset slug. */
    public static function providerLabelKey(string $provider): string
    {
        return 'ai.provider_' . (in_array($provider, self::PROVIDERS, true) ? $provider : 'other');
    }

    /** The status label translation key for the console badge. */
    public static function statusLabelKey(string $status): string
    {
        return match ($status) {
            'ready' => 'ai.status_ready',
            'disabled' => 'ai.status_disabled',
            default => 'ai.status_not_configured',
        };
    }

    /** AES-256-GCM encryption of a key. The plaintext key never leaves memory. */
    public static function encrypt(string $plaintext): string
    {
        $secret = self::secret();
        if ($secret === '') {
            throw new AppError('ai_engine_secret_missing', 503, 'AI_ENGINE_SECRET is not configured.');
        }

        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            hash('sha256', $secret, true),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16,
        );
        if ($cipher === false) {
            throw new AppError('ai_engine_secret_missing', 503, 'The AI engine key could not be encrypted.');
        }

        return 'v1:' . base64_encode($nonce . $tag . $cipher);
    }

    /** Decrypt a stored key; null when absent, unreadable or the secret changed. */
    public static function decrypt(string $encoded): ?string
    {
        $secret = self::secret();
        if ($secret === '' || !str_starts_with($encoded, 'v1:')) {
            return null;
        }

        $raw = base64_decode(substr($encoded, 3), true);
        if ($raw === false || strlen($raw) <= 28) {
            return null;
        }

        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            hash('sha256', $secret, true),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        return $plain === false ? null : $plain;
    }

    private static function secret(): string
    {
        return trim((string) Config::get('ai_engine.secret', ''));
    }

    /** @param array<string, mixed> $row */
    private static function storedApiKey(array $row): ?string
    {
        $ciphertext = $row['api_key_ciphertext'] !== null ? (string) $row['api_key_ciphertext'] : '';
        if ($ciphertext === '') {
            return null;
        }

        $key = self::decrypt($ciphertext);

        return $key !== null && trim($key) !== '' ? $key : null;
    }

    /**
     * The key generation uses: the encrypted key stored from the console, or —
     * only while none is stored — the server-side AI_ENGINE_API_KEY fallback.
     *
     * @param array<string, mixed>|null $row
     */
    private static function resolvedApiKey(?array $row = null): ?string
    {
        $row ??= self::settings();
        $stored = self::storedApiKey($row);
        if ($stored !== null) {
            return $stored;
        }

        $fallback = trim((string) Config::get('ai_engine.api_key', ''));

        return $fallback !== '' ? $fallback : null;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current
     * @return array{provider: string, base_url: string, model: string, temperature: string, max_tokens: int, skill_gold_standard: string, skill_verra_vcs: string, global_instructions: string, enabled: bool}
     */
    private static function validate(array $input, array $current): array
    {
        $errors = [];

        $provider = trim((string) ($input['provider'] ?? $current['provider']));
        if (!in_array($provider, self::PROVIDERS, true)) {
            $errors['provider'] = 'invalid_choice';
            $provider = (string) $current['provider'];
        }

        $baseUrl = trim((string) ($input['base_url'] ?? ''));
        if ($baseUrl === '') {
            $errors['base_url'] = 'required';
        } elseif (mb_strlen($baseUrl) > self::MAX_BASE_URL) {
            $errors['base_url'] = 'too_long';
        } elseif (!preg_match('#^https?://[^\s]+$#i', $baseUrl)) {
            $errors['base_url'] = 'invalid_url';
        }

        // Model choice: the console posts a preset via `model` and an optional
        // custom id via `model_custom`. "Other / custom model…" wins; a preset
        // selection ignores any stale custom text so saving is predictable.
        $selectedModel = trim((string) ($input['model'] ?? ''));
        $customModel = trim((string) ($input['model_custom'] ?? ''));
        if ($selectedModel === '__custom__' || $selectedModel === '') {
            $model = $customModel;
        } else {
            $model = $selectedModel;
        }
        if ($model === '') {
            $errors['model'] = 'required';
        } elseif (mb_strlen($model) > self::MAX_MODEL) {
            $errors['model'] = 'too_long';
        }

        $temperatureRaw = trim((string) ($input['temperature'] ?? '0.2'));
        if (!is_numeric($temperatureRaw) || (float) $temperatureRaw < 0 || (float) $temperatureRaw > 2) {
            $errors['temperature'] = 'invalid_choice';
            $temperature = '0.20';
        } else {
            $temperature = number_format((float) $temperatureRaw, 2, '.', '');
        }

        $maxTokens = (int) ($input['max_tokens'] ?? 1200);
        if ($maxTokens < self::MIN_MAX_TOKENS || $maxTokens > self::MAX_MAX_TOKENS) {
            $errors['max_tokens'] = 'invalid_choice';
            $maxTokens = 1200;
        }

        // The Settings tab posts only settings fields; the Skill tab posts all
        // three skill fields (an empty string there is an intentional clear). An
        // ABSENT skill field therefore means "keep the stored value", not "erase
        // it" — otherwise saving the Settings tab destroys the skills.
        $skillGold = array_key_exists('skill_gold_standard', $input)
            ? trim((string) $input['skill_gold_standard'])
            : (string) ($current['skill_gold_standard'] ?? '');
        if (mb_strlen($skillGold) > self::MAX_SKILL_LENGTH) {
            $errors['skill_gold_standard'] = 'too_long';
            $skillGold = mb_substr($skillGold, 0, self::MAX_SKILL_LENGTH);
        }

        $skillVerra = array_key_exists('skill_verra_vcs', $input)
            ? trim((string) $input['skill_verra_vcs'])
            : (string) ($current['skill_verra_vcs'] ?? '');
        if (mb_strlen($skillVerra) > self::MAX_SKILL_LENGTH) {
            $errors['skill_verra_vcs'] = 'too_long';
            $skillVerra = mb_substr($skillVerra, 0, self::MAX_SKILL_LENGTH);
        }

        $global = array_key_exists('global_instructions', $input)
            ? trim((string) $input['global_instructions'])
            : (string) ($current['global_instructions'] ?? '');
        if (mb_strlen($global) > self::MAX_GLOBAL_INSTRUCTIONS) {
            $errors['global_instructions'] = 'too_long';
            $global = mb_substr($global, 0, self::MAX_GLOBAL_INSTRUCTIONS);
        }

        $newKey = trim((string) ($input['api_key'] ?? ''));
        if (mb_strlen($newKey) > self::MAX_KEY_LENGTH) {
            $errors['api_key'] = 'too_long';
        }

        if ($errors !== []) {
            throw AppError::validation($errors);
        }

        return [
            'provider' => $provider,
            'base_url' => $baseUrl,
            'model' => $model,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            'skill_gold_standard' => $skillGold,
            'skill_verra_vcs' => $skillVerra,
            'global_instructions' => $global,
            'enabled' => (string) ($input['enabled'] ?? '') === '1',
        ];
    }
}
