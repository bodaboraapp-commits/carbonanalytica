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

    public const MAX_BASE_URL = 500;
    public const MAX_MODEL = 120;
    public const MAX_KEY_LENGTH = 512;
    public const MAX_SKILL_LENGTH = 20000;
    public const MAX_GLOBAL_INSTRUCTIONS = 8000;
    public const MIN_MAX_TOKENS = 50;
    public const MAX_MAX_TOKENS = 32000;

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
     * @return array{base_url: string, model: string, temperature: float, max_tokens: int, api_key: string, timeout: int}
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

        $model = trim((string) ($input['model'] ?? ''));
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

        $skillGold = trim((string) ($input['skill_gold_standard'] ?? ''));
        if (mb_strlen($skillGold) > self::MAX_SKILL_LENGTH) {
            $errors['skill_gold_standard'] = 'too_long';
            $skillGold = mb_substr($skillGold, 0, self::MAX_SKILL_LENGTH);
        }

        $skillVerra = trim((string) ($input['skill_verra_vcs'] ?? ''));
        if (mb_strlen($skillVerra) > self::MAX_SKILL_LENGTH) {
            $errors['skill_verra_vcs'] = 'too_long';
            $skillVerra = mb_substr($skillVerra, 0, self::MAX_SKILL_LENGTH);
        }

        $global = trim((string) ($input['global_instructions'] ?? ''));
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
