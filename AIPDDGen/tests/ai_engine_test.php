<?php

declare(strict_types=1);

/**
 * AI Engine tests: settings + encrypted key storage, the OpenAI-compatible client
 * (mocked transport — no network), and drafting guards/citations/needs-input.
 *
 * Everything here runs offline: the LLM transport is always injected, so the
 * suite never calls a provider and never needs an API key.
 */

use App\AppError;
use App\Config;
use App\Db;
use App\Repositories\UserRepository;
use App\Services\AiEngineService;
use App\Services\DraftService;
use App\Services\LlmClient;
use App\Services\ProjectService;
use App\Uuid;

function ai_test_secret_value(): string
{
    return 'ai-engine-unit-test-secret-5f1c9a7e';
}

/**
 * Reload the application config with a controlled secret/env key. The test
 * runner boots with the real .env.local, so every test pins its own values.
 */
function ai_test_config(?string $secret, ?string $envKey): void
{
    putenv($secret === null ? 'AI_ENGINE_SECRET' : 'AI_ENGINE_SECRET=' . $secret);
    putenv($envKey === null ? 'AI_ENGINE_API_KEY' : 'AI_ENGINE_API_KEY=' . $envKey);
    Config::load(require APP_ROOT . '/config/config.php');
}

function ai_test_use_secret(?string $envKey = null): void
{
    ai_test_config(ai_test_secret_value(), $envKey);
}

/** A platform-admin account (staff console access). */
function ai_test_staff(string $email = 'ai-engine-staff@example.com'): array
{
    $account = make_user($email, 'AI Engine Staff');
    Db::execute('UPDATE users SET is_platform_admin = 1 WHERE id = ?', [(string) $account['user']['id']]);
    $account['user'] = UserRepository::findById((string) $account['user']['id']);
    if ($account['user'] === null) {
        throw new RuntimeException('Failed to load staff user');
    }

    return $account;
}

/**
 * Seed a template + version + sections. Defaults to a published Gold Standard
 * PDD template with two sections.
 *
 * @return array{template_id: string, version_id: string, section_ids: list<string>}
 */
function ai_test_seed_template(string $registry = 'gold_standard', string $status = 'published'): array
{
    $templateId = Uuid::v4();
    $versionId = Uuid::v4();
    Db::insert('templates', [
        'id' => $templateId,
        'name' => 'Test ' . $registry . ' PDD template',
        'kind' => 'pdd_template',
        'registry' => $registry,
        'language' => 'en',
        'description' => 'Test template for the AI engine suite.',
    ]);
    Db::insert('template_versions', [
        'id' => $versionId,
        'template_id' => $templateId,
        'version_label' => 'v1.0',
        'language' => 'en',
        'effective_date' => '2026-01-01',
        'status' => $status,
        'source_url' => 'https://example.org/test-template.pdf',
        'license_note' => 'Test fixture only.',
    ]);

    $sections = [
        ['A.1', 'Purpose and general description'],
        ['A.4', 'Location'],
    ];
    $sectionIds = [];
    $ordinal = 0;
    foreach ($sections as [$key, $heading]) {
        $id = Uuid::v4();
        Db::insert('template_sections', [
            'id' => $id,
            'template_version_id' => $versionId,
            'parent_section_id' => null,
            'ordinal' => ++$ordinal,
            'stable_key' => $key,
            'heading' => $heading,
            'level' => 2,
            'is_required' => true,
            'allow_intentionally_blank' => true,
            'guidance' => 'Use only confirmed facts.',
            'format_rules' => json_encode([
                'date_format' => 'DD/MM/YYYY',
                'number_format' => 'thousands_comma_decimal_point',
            ]),
        ]);
        $sectionIds[] = $id;
    }

    return ['template_id' => $templateId, 'version_id' => $versionId, 'section_ids' => $sectionIds];
}

/** A project owned by the given account, configured for one registry/template. */
function ai_test_project(array $owner, array $attrs = []): array
{
    $userId = (string) $owner['user']['id'];
    $teamId = (string) $owner['team']['id'];
    $project = ProjectService::create($userId, $teamId, (string) ($attrs['name'] ?? 'AI Engine Project'));

    Db::execute(
        'UPDATE projects SET registry = ?, template_version_id = ?, country_code = ?, project_type = ?, language = ?, status = ? WHERE id = ?',
        [
            array_key_exists('registry', $attrs) ? $attrs['registry'] : 'gold_standard',
            $attrs['template_version_id'] ?? null,
            $attrs['country_code'] ?? 'KE',
            $attrs['project_type'] ?? 'cookstoves',
            $attrs['language'] ?? 'en',
            $attrs['status'] ?? 'setup',
            (string) $project['id'],
        ],
    );

    return Db::selectOne('SELECT * FROM projects WHERE id = ?', [(string) $project['id']]) ?? $project;
}

/**
 * Insert confirmed facts and an approved fact-sheet version for a project.
 *
 * @param list<array{key: string, label: string, columns?: array<string, mixed>, category?: string}> $facts
 * @return array{fact_ids: array<string, string>, fact_sheet_id: string}
 */
function ai_test_seed_fact_sheet(string $projectId, string $teamId, string $userId, array $facts): array
{
    $factIds = [];
    foreach ($facts as $fact) {
        $id = Uuid::v4();
        Db::insert('facts', array_merge([
            'id' => $id,
            'project_id' => $projectId,
            'team_id' => $teamId,
            'category' => $fact['category'] ?? 'project',
            'fact_key' => $fact['key'],
            'label' => $fact['label'],
            'value_type' => 'string',
            'status' => 'confirmed',
            'confirmed_by_user_id' => $userId,
            'confirmed_at' => gmdate('Y-m-d H:i:s'),
        ], $fact['columns'] ?? []));
        $factIds[$fact['key']] = $id;
    }

    $factSheetId = Uuid::v4();
    Db::insert('fact_sheet_versions', [
        'id' => $factSheetId,
        'project_id' => $projectId,
        'version' => 1,
        'status' => 'approved',
        'snapshot' => json_encode(['fact_ids' => array_values($factIds)]),
        'created_by_user_id' => $userId,
        'approved_by_user_id' => $userId,
        'approved_at' => gmdate('Y-m-d H:i:s'),
    ]);

    return ['fact_ids' => $factIds, 'fact_sheet_id' => $factSheetId];
}

/**
 * A client backed by a fake transport. The handler receives the request array
 * and the 1-based attempt number and returns ['status' => int, 'body' => string].
 * Captured requests are available through $calls.
 */
function ai_test_transport_client(callable $handler, ?array &$calls = null): LlmClient
{
    $calls = [];

    return new LlmClient(static function (array $request) use ($handler, &$calls): array {
        $calls[] = $request;

        return $handler($request, count($calls));
    });
}

/** A 200 response whose assistant message is $content. */
function ai_test_ok_body(string $content): array
{
    return [
        'status' => 200,
        'body' => json_encode([
            'model' => 'mock-model',
            'choices' => [['message' => ['content' => $content]]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

function ai_test_generated_answer(string $factId, string $content = 'The project distributes improved cookstoves to households.'): string
{
    return json_encode([
        'status' => 'generated',
        'content' => $content,
        'citations' => [$factId],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function ai_test_needs_input_answer(string $reason = 'No confirmed fact covers the baseline.'): string
{
    return json_encode(['status' => 'needs_input', 'reason' => $reason]);
}

/** Configure and save a working engine (key stored, enabled) as staff. */
function ai_test_configured_engine(array $staff, string $key = 'sk-test-secret-1234'): array
{
    ai_test_use_secret();

    return AiEngineService::save((string) $staff['user']['id'], [
        'provider' => 'openai',
        'base_url' => 'https://api.openai.com/v1',
        'model' => 'gpt-4o-mini',
        'temperature' => '0.2',
        'max_tokens' => '600',
        'api_key' => $key,
        'enabled' => '1',
        'skill_gold_standard' => 'Follow the Gold Standard Template Guide v1.4 headings exactly.',
        'skill_verra_vcs' => 'Follow the Verra VCS PDD template exactly.',
        'global_instructions' => 'Write concise, factual prose.',
    ]);
}

return [
    'saves settings with an encrypted key and exposes only the masked last four' => static function (): void {
        ai_test_use_secret();
        $staff = ai_test_staff();

        $saved = AiEngineService::save((string) $staff['user']['id'], [
            'provider' => 'deepseek',
            'base_url' => 'https://api.deepseek.com/v1',
            'model' => 'deepseek-chat',
            'temperature' => '0.35',
            'max_tokens' => '900',
            'api_key' => 'sk-test-super-secret-1234',
            'enabled' => '1',
            'skill_gold_standard' => 'Gold rules',
            'skill_verra_vcs' => 'Verra rules',
            'global_instructions' => 'Global rules',
        ]);

        assert_same('deepseek', $saved['provider']);
        assert_same('https://api.deepseek.com/v1', $saved['base_url']);
        assert_same('deepseek-chat', $saved['model']);
        assert_same('0.35', $saved['temperature']);
        assert_same(900, $saved['max_tokens']);
        assert_true($saved['enabled'], 'engine enabled');
        assert_true($saved['has_stored_key'], 'stored key flag');
        assert_same('1234', $saved['api_key_last4']);
        assert_same('ready', $saved['status']);
        assert_same('Gold rules', $saved['skill_gold_standard']);
        assert_same('Verra rules', $saved['skill_verra_vcs']);

        // The public payload (the only shape templates see) never carries the key
        // or its ciphertext.
        $encoded = json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        assert_false(str_contains((string) $encoded, 'sk-test-super-secret-1234'), 'public payload leaks the key');
        assert_false(array_key_exists('api_key', $saved), 'public payload has no api_key field');
        assert_false(array_key_exists('api_key_ciphertext', $saved), 'public payload has no ciphertext field');

        // At rest only AES-GCM ciphertext + last4 are stored.
        $row = Db::selectOne('SELECT * FROM ai_engine_settings WHERE id = 1');
        assert_not_null($row);
        $ciphertext = (string) $row['api_key_ciphertext'];
        assert_true(str_starts_with($ciphertext, 'v1:'), 'ciphertext is versioned');
        assert_false(str_contains($ciphertext, 'sk-test-super-secret-1234'), 'ciphertext contains plaintext');
        assert_same('sk-test-super-secret-1234', AiEngineService::decrypt($ciphertext), 'decrypt round-trip');
        assert_same('1234', (string) $row['api_key_last4']);

        // Audited as ai_engine.settings_saved without the key.
        $audit = Db::selectOne("SELECT * FROM audit_logs WHERE action = 'ai_engine.settings_saved' ORDER BY created_at DESC LIMIT 1");
        assert_not_null($audit);
        assert_false(str_contains((string) $audit['metadata'], 'sk-test-super-secret-1234'), 'audit leaks the key');
    },

    'accepts Claude as an OpenAI-compatible provider preset' => static function (): void {
        ai_test_use_secret();
        $staff = ai_test_staff('claude-staff@example.com');

        assert_true(in_array('claude', AiEngineService::PROVIDERS, true), 'claude is an offered provider preset');
        assert_same('https://api.anthropic.com/v1', AiEngineService::BASE_URLS['claude'], 'suggested Claude API root');
        assert_same('ai.provider_claude', AiEngineService::providerLabelKey('claude'));
        assert_true(t('ai.provider_claude') !== 'ai.provider_claude', 'EN/FR label for the Claude preset exists');

        $saved = AiEngineService::save((string) $staff['user']['id'], [
            'provider' => 'claude',
            'base_url' => 'https://api.anthropic.com/v1',
            'model' => 'claude-sonnet-4-5',
            'temperature' => '0.2',
            'max_tokens' => '800',
            'api_key' => 'sk-ant-test-9876',
            'enabled' => '1',
        ]);

        assert_same('claude', $saved['provider']);
        assert_same('claude-sonnet-4-5', $saved['model']);
        assert_same('9876', $saved['api_key_last4']);
        assert_same('ready', $saved['status']);
        assert_true(in_array('claude', $saved['providers'], true), 'page payload offers the Claude preset');

        $row = Db::selectOne('SELECT * FROM ai_engine_settings WHERE id = 1');
        assert_same('claude', (string) $row['provider']);
        assert_same('sk-ant-test-9876', AiEngineService::decrypt((string) $row['api_key_ciphertext']));
    },

    'refuses to enable the engine without a key' => static function (): void {
        ai_test_use_secret();
        $staff = ai_test_staff();

        assert_app_error('ai_engine_key_required', static function () use ($staff): void {
            AiEngineService::save((string) $staff['user']['id'], [
                'provider' => 'openai',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'gpt-4o-mini',
                'temperature' => '0.2',
                'max_tokens' => '600',
                'enabled' => '1',
            ]);
        });
    },

    'refuses to store a key when the encryption secret is missing' => static function (): void {
        ai_test_config(null, null);
        $staff = ai_test_staff();

        assert_false(AiEngineService::encryptionReady(), 'encryption must be off without a secret');
        assert_app_error('ai_engine_secret_missing', static function () use ($staff): void {
            AiEngineService::save((string) $staff['user']['id'], [
                'provider' => 'openai',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'gpt-4o-mini',
                'api_key' => 'sk-any-key-9999',
            ]);
        });
        assert_same(0, count_rows('audit_logs', "action = 'ai_engine.settings_saved'"), 'no audit written on refusal');
    },

    'clears the stored key and stops reporting it as configured' => static function (): void {
        ai_test_use_secret();
        $staff = ai_test_staff();
        $staffId = (string) $staff['user']['id'];

        ai_test_configured_engine($staff, 'sk-clear-me-5678');

        $cleared = AiEngineService::save($staffId, [
            'provider' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o-mini',
            'temperature' => '0.2',
            'max_tokens' => '600',
            'clear_api_key' => '1',
            'enabled' => '',
        ]);

        assert_false($cleared['has_stored_key'], 'stored key removed');
        assert_null($cleared['api_key_last4']);
        assert_false($cleared['configured'], 'engine no longer configured');
        assert_same('not_configured', $cleared['status']);

        $row = Db::selectOne('SELECT api_key_ciphertext, api_key_last4 FROM ai_engine_settings WHERE id = 1');
        assert_null($row['api_key_ciphertext']);
        assert_null($row['api_key_last4']);
    },

    'uses the environment key fallback only while no key is stored' => static function (): void {
        ai_test_use_secret('env-fallback-key-7777');
        $staff = ai_test_staff();
        $staffId = (string) $staff['user']['id'];

        $saved = AiEngineService::save($staffId, [
            'provider' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o-mini',
            'temperature' => '0.2',
            'max_tokens' => '600',
            'enabled' => '1',
        ]);

        assert_true($saved['env_fallback'], 'environment fallback in use');
        assert_false($saved['has_stored_key'], 'no stored key yet');
        assert_same('env-fallback-key-7777', AiEngineService::clientSettings()['api_key']);

        // A stored key always wins over the environment fallback.
        AiEngineService::save($staffId, [
            'provider' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o-mini',
            'temperature' => '0.2',
            'max_tokens' => '600',
            'enabled' => '1',
            'api_key' => 'sk-stored-wins-0001',
        ]);
        $public = AiEngineService::publicSettings();
        assert_false($public['env_fallback'], 'stored key disables the fallback');
        assert_same('sk-stored-wins-0001', AiEngineService::clientSettings()['api_key']);
    },

    'encrypts with AES-256-GCM and cannot decrypt after the secret changes' => static function (): void {
        ai_test_config('first-secret-value', null);
        $ciphertext = AiEngineService::encrypt('sk-round-trip-4321');

        assert_true(str_starts_with($ciphertext, 'v1:'), 'versioned ciphertext');
        assert_false(str_contains($ciphertext, 'sk-round-trip-4321'), 'ciphertext hides plaintext');
        assert_same('sk-round-trip-4321', AiEngineService::decrypt($ciphertext));

        // A different deployment secret must not read the stored ciphertext.
        ai_test_config('different-secret-value', null);
        assert_null(AiEngineService::decrypt($ciphertext), 'wrong secret must not decrypt');

        // And no secret at all fails closed.
        ai_test_config(null, null);
        assert_null(AiEngineService::decrypt($ciphertext));
        ai_test_use_secret();
    },

    'llm client retries once on a server error and maps failures to safe errors' => static function (): void {
        $settings = ['base_url' => 'https://api.example.org/v1', 'api_key' => 'sk-provider-secret-9999', 'model' => 'test-model', 'max_tokens' => 100];
        $messages = [['role' => 'user', 'content' => 'hello']];

        // 500 then 200: exactly one retry, then success.
        $calls = [];
        $client = ai_test_transport_client(static function (array $request, int $attempt): array {
            return $attempt === 1 ? ['status' => 500, 'body' => '{"error":"server"}'] : ai_test_ok_body('OK');
        }, $calls);
        $result = $client->chat($settings, $messages);
        assert_same('OK', $result['content']);
        assert_same(2, count($calls), 'one retry after a 5xx');

        // The key travels in the Authorization header only, never in the URL/body.
        assert_false(str_contains((string) $calls[0]['url'], 'sk-provider-secret-9999'));
        assert_false(str_contains((string) $calls[0]['body'], 'sk-provider-secret-9999'));
        assert_true(in_array('Authorization: Bearer sk-provider-secret-9999', $calls[0]['headers'], true));

        // Persistent 5xx becomes a structured, key-free error.
        $callCount = 0;
        $failing = ai_test_transport_client(static fn (array $request, int $attempt): array => ['status' => 503, 'body' => 'down'], $failingCalls);
        try {
            $failing->chat($settings, $messages);
            throw new RuntimeException('Expected a 5xx failure');
        } catch (AppError $error) {
            assert_same('ai_engine_llm_request', $error->errorCode);
            assert_false(str_contains($error->getMessage(), 'sk-provider-secret-9999'), 'error leaks the key');
            assert_false(str_contains(json_encode($error->fields), 'sk-provider-secret-9999'), 'detail leaks the key');
        }
        assert_same(2, count($failingCalls), 'two attempts before giving up');

        // 401 is reported as an auth problem without echoing the key.
        $auth = ai_test_transport_client(static fn (array $request, int $attempt): array => ['status' => 401, 'body' => 'nope'], $authCalls);
        try {
            $auth->chat($settings, $messages);
            throw new RuntimeException('Expected an auth failure');
        } catch (AppError $error) {
            assert_same('ai_engine_llm_auth', $error->errorCode);
            assert_false(str_contains($error->getMessage(), 'sk-provider-secret-9999'));
        }
        assert_same(1, count($authCalls), '4xx is not retried');

        // A transport exception becomes a network error, never the raw detail.
        $network = ai_test_transport_client(static function (): array {
            throw new RuntimeException('DNS failure for api.example.org');
        }, $networkCalls);
        try {
            $network->chat($settings, $messages);
            throw new RuntimeException('Expected a network failure');
        } catch (AppError $error) {
            assert_same('ai_engine_llm_network', $error->errorCode);
            assert_false(str_contains($error->getMessage(), 'DNS failure'), 'raw transport detail leaked');
        }

        // A malformed provider answer is a structured response error.
        $broken = ai_test_transport_client(static fn (): array => ['status' => 200, 'body' => 'not json'], $brokenCalls);
        try {
            $broken->chat($settings, $messages);
            throw new RuntimeException('Expected a response error');
        } catch (AppError $error) {
            assert_same('ai_engine_llm_response', $error->errorCode);
        }
    },

    'llm client retries without temperature when a current model rejects it' => static function (): void {
        $settings = [
            'base_url' => 'https://api.anthropic.com/v1',
            'api_key' => 'sk-ant-temp-secret-0000',
            'model' => 'claude-sonnet-5-5',
            'temperature' => 0.2,
            'max_tokens' => 100,
        ];
        $messages = [['role' => 'user', 'content' => 'hello']];

        // First attempt carries temperature and is rejected; the retry omits it.
        $calls = [];
        $client = ai_test_transport_client(static function (array $request, int $attempt) use (&$calls): array {
            $payload = json_decode((string) $request['body'], true);
            if (is_array($payload) && array_key_exists('temperature', $payload)) {
                return [
                    'status' => 400,
                    'body' => '{"type":"error","error":{"type":"invalid_request_error","message":"`temperature` is deprecated for this model."}}',
                ];
            }

            return ai_test_ok_body('OK');
        }, $calls);

        $result = $client->chat($settings, $messages);
        assert_same('OK', $result['content']);
        assert_same(2, count($calls), 'one temperature fallback attempt');
        $firstPayload = json_decode((string) $calls[0]['body'], true);
        $secondPayload = json_decode((string) $calls[1]['body'], true);
        assert_true(is_array($firstPayload) && array_key_exists('temperature', $firstPayload), 'first attempt sends temperature');
        assert_false(is_array($secondPayload) && array_key_exists('temperature', $secondPayload), 'retry omits temperature');

        // The client remembers: the next chat call makes a single request.
        $again = $client->chat($settings, $messages);
        assert_same('OK', $again['content']);
        assert_same(3, count($calls), 'temperature is omitted for later calls too');
        $thirdPayload = json_decode((string) $calls[2]['body'], true);
        assert_false(is_array($thirdPayload) && array_key_exists('temperature', $thirdPayload));

        // An unrelated 400 is not retried and still fails safely.
        $unrelatedCalls = [];
        $unrelated = ai_test_transport_client(static fn (): array => [
            'status' => 400,
            'body' => '{"error":{"type":"invalid_request_error","message":"model not found"}}',
        ], $unrelatedCalls);
        try {
            $unrelated->chat($settings, $messages);
            throw new RuntimeException('Expected a request error');
        } catch (AppError $error) {
            // "model not found" is a model/base-URL problem, not a generic request failure.
            assert_same('ai_engine_llm_model', $error->errorCode);
            assert_contains('model not found', (string) ($error->fields['detail'] ?? ''), 'detail keeps the reason');
        }
        assert_same(1, count($unrelatedCalls), 'unrelated 4xx is not retried');
        assert_false(str_contains($error->getMessage(), 'sk-ant-temp-secret-0000'), 'error leaks the key');
    },

    'model presets are provider-aware and custom model ids round-trip' => static function (): void {
        ai_test_use_secret();
        $staff = ai_test_staff('model-presets-staff@example.com');
        $staffId = (string) $staff['user']['id'];

        assert_true(in_array('gpt-4o-mini', AiEngineService::modelPresets('openai'), true), 'openai preset');
        assert_true(in_array('o3-mini', AiEngineService::modelPresets('openai'), true), 'reasoning model preset');
        assert_true(in_array('claude-haiku-4-5', AiEngineService::modelPresets('claude'), true), 'claude preset');
        assert_true(in_array('deepseek-reasoner', AiEngineService::modelPresets('deepseek'), true), 'deepseek preset');
        assert_true(in_array('anthropic/claude-sonnet-4-5', AiEngineService::modelPresets('openrouter'), true), 'openrouter preset');
        assert_same([], AiEngineService::modelPresets('other'), 'no presets for the custom provider');
        assert_true(AiEngineService::isPresetModel('claude', 'claude-haiku-4-5'));
        assert_false(AiEngineService::isPresetModel('claude', 'gpt-4o-mini'));
        // Default accessor follows the stored provider (fresh row = openai).
        assert_same(AiEngineService::modelPresets('openai'), AiEngineService::modelPresets());

        // Preset save/read.
        $saved = AiEngineService::save($staffId, [
            'provider' => 'claude',
            'base_url' => 'https://api.anthropic.com/v1',
            'model' => 'claude-haiku-4-5',
            'temperature' => '0.2',
            'max_tokens' => '700',
            'api_key' => 'sk-model-preset-1111',
            'enabled' => '1',
        ]);
        assert_same('claude-haiku-4-5', $saved['model']);
        assert_true(in_array('claude-haiku-4-5', $saved['model_presets'], true), 'page payload offers the preset');

        // Custom save/read: model_custom wins over the "__custom__" marker.
        $custom = AiEngineService::save($staffId, [
            'provider' => 'claude',
            'base_url' => 'https://api.anthropic.com/v1',
            'model' => '__custom__',
            'model_custom' => 'claude-experimental-2099',
            'temperature' => '0.2',
            'max_tokens' => '700',
            'enabled' => '1',
        ]);
        assert_same('claude-experimental-2099', $custom['model']);
        assert_same('claude-experimental-2099', (string) AiEngineService::settings()['model'], 'custom id persisted');
        assert_false(AiEngineService::isPresetModel('claude', 'claude-experimental-2099'), 'custom id is not a preset');
        assert_false(in_array('claude-experimental-2099', $custom['model_presets'], true), 'preset list stays curated');

        // Selecting a preset ignores stale custom text (predictable saving).
        $presetAgain = AiEngineService::save($staffId, [
            'provider' => 'claude',
            'base_url' => 'https://api.anthropic.com/v1',
            'model' => 'claude-sonnet-4-5',
            'model_custom' => 'stale-custom-value',
            'temperature' => '0.2',
            'max_tokens' => '700',
            'enabled' => '1',
        ]);
        assert_same('claude-sonnet-4-5', $presetAgain['model']);

        // Neither a preset nor a custom id: clear validation error.
        assert_app_error('validation_error', static function () use ($staffId): void {
            AiEngineService::save($staffId, [
                'provider' => 'claude',
                'base_url' => 'https://api.anthropic.com/v1',
                'model' => '__custom__',
                'model_custom' => '',
                'temperature' => '0.2',
                'max_tokens' => '700',
            ]);
        });
    },

    'normalises the base URL and rejects unusable ones' => static function (): void {
        $settings = ['api_key' => 'sk-normalise-1234', 'model' => 'gpt-4o-mini', 'max_tokens' => 100];
        $messages = [['role' => 'user', 'content' => 'hello']];

        foreach ([
            'https://api.openai.com/v1',
            'https://api.openai.com/v1/',
            '  https://api.openai.com/v1  ',
            'https://api.openai.com/v1/chat/completions',
        ] as $rawBaseUrl) {
            $calls = [];
            $client = ai_test_transport_client(static fn (): array => ai_test_ok_body('OK'), $calls);
            $client->chat(array_merge($settings, ['base_url' => $rawBaseUrl]), $messages);
            assert_same(1, count($calls), 'one request for ' . $rawBaseUrl);
            assert_same('https://api.openai.com/v1/chat/completions', (string) $calls[0]['url'], 'normalised from ' . $rawBaseUrl);
        }

        $calls = [];
        $client = ai_test_transport_client(static fn (): array => ai_test_ok_body('OK'), $calls);
        try {
            $client->chat(array_merge($settings, ['base_url' => 'api.openai.com/v1']), $messages);
            throw new RuntimeException('Expected an invalid base URL error');
        } catch (AppError $error) {
            assert_same('ai_engine_base_url', $error->errorCode);
        }
        assert_same(0, count($calls), 'no request is made for an unusable base URL');
    },

    'sends Anthropic headers for the Claude provider' => static function (): void {
        $messages = [['role' => 'user', 'content' => 'hello']];

        $calls = [];
        $claude = ai_test_transport_client(static fn (): array => ai_test_ok_body('OK'), $calls);
        $claude->chat([
            'provider' => 'claude',
            'base_url' => 'https://api.anthropic.com/v1',
            'api_key' => 'sk-ant-headers-7777',
            'model' => 'claude-haiku-4-5',
            'max_tokens' => 100,
        ], $messages);
        $headers = $calls[0]['headers'];
        assert_true(in_array('x-api-key: sk-ant-headers-7777', $headers, true), 'x-api-key header for Anthropic');
        assert_true(in_array('anthropic-version: 2023-06-01', $headers, true), 'anthropic-version header');
        assert_true(in_array('Authorization: Bearer sk-ant-headers-7777', $headers, true), 'Bearer kept too');
        assert_false(str_contains((string) $calls[0]['url'], 'sk-ant-headers-7777'), 'key never in the URL');

        $calls = [];
        $openai = ai_test_transport_client(static fn (): array => ai_test_ok_body('OK'), $calls);
        $openai->chat([
            'provider' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-openai-8888',
            'model' => 'gpt-4o-mini',
            'max_tokens' => 100,
        ], $messages);
        $joined = implode("\n", $calls[0]['headers']);
        assert_false(str_contains($joined, 'x-api-key:'), 'no Anthropic header for OpenAI');
        assert_true(str_contains($joined, 'Authorization: Bearer sk-openai-8888'), 'Bearer for OpenAI');
    },

    'uses max_completion_tokens for reasoning models and retries on rejection' => static function (): void {
        $messages = [['role' => 'user', 'content' => 'hello']];

        // o-series models get the newer token field from the start.
        $calls = [];
        $reasoning = ai_test_transport_client(static fn (): array => ai_test_ok_body('OK'), $calls);
        $reasoning->chat([
            'provider' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-o-series-1234',
            'model' => 'o3-mini',
            'max_tokens' => 321,
        ], $messages);
        $payload = json_decode((string) $calls[0]['body'], true);
        assert_same(321, $payload['max_completion_tokens'] ?? null);
        assert_false(array_key_exists('max_tokens', $payload), 'reasoning model never sends max_tokens');

        // A provider that rejects max_tokens gets one retry with the new field.
        $calls = [];
        $retrying = ai_test_transport_client(static function (array $request): array {
            $payload = json_decode((string) $request['body'], true);
            if (is_array($payload) && array_key_exists('max_tokens', $payload)) {
                return [
                    'status' => 400,
                    'body' => '{"error":{"type":"invalid_request_error","message":"Unsupported parameter: max_tokens. Use max_completion_tokens instead.","param":"max_tokens"}}',
                ];
            }

            return ai_test_ok_body('OK');
        }, $calls);
        $result = $retrying->chat([
            'provider' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-token-retry-5678',
            'model' => 'gpt-4o-mini',
            'max_tokens' => 456,
        ], $messages);
        assert_same('OK', $result['content']);
        assert_same(2, count($calls), 'one token-field retry');
        $first = json_decode((string) $calls[0]['body'], true);
        $second = json_decode((string) $calls[1]['body'], true);
        assert_true(array_key_exists('max_tokens', $first), 'first attempt uses max_tokens');
        assert_same(456, $second['max_completion_tokens'] ?? null);
        assert_false(array_key_exists('max_tokens', $second), 'retry uses max_completion_tokens');
    },

    'surfaces sanitised provider details for model, auth and request errors' => static function (): void {
        $settings = [
            'provider' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-detail-secret-4242',
            'model' => 'gpt-4o-mini',
            'max_tokens' => 100,
        ];
        $messages = [['role' => 'user', 'content' => 'hello']];

        // 404: model / base-URL problem.
        $calls = [];
        $client = ai_test_transport_client(static fn (): array => [
            'status' => 404,
            'body' => '{"error":{"type":"not_found_error","message":"model: claude-typo"}}',
        ], $calls);
        try {
            $client->chat($settings, $messages);
            throw new RuntimeException('Expected a model error');
        } catch (AppError $error) {
            assert_same('ai_engine_llm_model', $error->errorCode);
            assert_contains('model: claude-typo', (string) ($error->fields['detail'] ?? ''), 'model reason surfaced');
        }
        assert_same(1, count($calls), '404 is not retried');

        // 401 with the key echoed in the provider body: auth + redacted detail.
        $calls = [];
        $client = ai_test_transport_client(static fn (): array => [
            'status' => 401,
            'body' => '{"error":{"type":"authentication_error","message":"invalid x-api-key sk-detail-secret-4242"}}',
        ], $calls);
        try {
            $client->chat($settings, $messages);
            throw new RuntimeException('Expected an auth error');
        } catch (AppError $error) {
            assert_same('ai_engine_llm_auth', $error->errorCode);
            $detail = (string) ($error->fields['detail'] ?? '');
            assert_contains('invalid x-api-key', $detail);
            assert_false(str_contains($detail, 'sk-detail-secret-4242'), 'key redacted from the detail');
            assert_contains('[redacted]', $detail);
        }

        // A generic 400 keeps a truncated, secret-free provider detail.
        $longMessage = str_repeat('invalid parameter value ', 30);
        $calls = [];
        $client = ai_test_transport_client(static fn (): array => [
            'status' => 400,
            'body' => (string) json_encode(['error' => ['message' => $longMessage, 'param' => 'temperature']]),
        ], $calls);
        try {
            $client->chat($settings, $messages);
            throw new RuntimeException('Expected a request error');
        } catch (AppError $error) {
            assert_same('ai_engine_llm_request', $error->errorCode);
            $detail = (string) ($error->fields['detail'] ?? '');
            assert_true($detail !== '', 'detail present');
            assert_true(mb_strlen($detail) <= 200, 'detail truncated to 200 characters');
            assert_false(str_contains($detail, 'sk-detail-secret-4242'), 'no key in the detail');
        }
        assert_same(1, count($calls), '400 without token rejection is not retried');
    },

    'connection test is staff-only, audited and returns the model on success' => static function (): void {
        ai_test_use_secret();
        $staff = ai_test_staff();
        ai_test_configured_engine($staff);

        $okClient = ai_test_transport_client(static fn (): array => ai_test_ok_body('OK'), $okCalls);
        $result = AiEngineService::testConnection((string) $staff['user']['id'], $okClient);
        assert_true($result['ok']);
        assert_same('mock-model', $result['model']);
        assert_same(1, count($okCalls));
        $audit = Db::selectOne("SELECT * FROM audit_logs WHERE action = 'ai_engine.connection_tested' ORDER BY created_at DESC LIMIT 1");
        assert_not_null($audit);
        assert_true(str_contains((string) $audit['metadata'], '"ok":true'));

        $failClient = ai_test_transport_client(static fn (): array => ['status' => 500, 'body' => 'down'], $failCalls);
        try {
            AiEngineService::testConnection((string) $staff['user']['id'], $failClient);
            throw new RuntimeException('Expected the connection test to fail');
        } catch (AppError $error) {
            assert_same('ai_engine_llm_request', $error->errorCode);
            assert_contains('down', (string) ($error->fields['detail'] ?? ''), 'provider detail is kept');
        }
        $failedAudit = Db::selectOne("SELECT * FROM audit_logs WHERE action = 'ai_engine.connection_tested' ORDER BY created_at DESC LIMIT 1");
        assert_true(str_contains((string) $failedAudit['metadata'], '"ok":false'));

        // Tenant accounts get the same 404 as every console page.
        $tenant = make_user('ai-engine-tenant@example.com');
        assert_app_error('not_found', static function () use ($tenant): void {
            AiEngineService::save((string) $tenant['user']['id'], [
                'provider' => 'openai',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'gpt-4o-mini',
            ]);
        });
        assert_app_error('not_found', static function () use ($tenant): void {
            AiEngineService::testConnection((string) $tenant['user']['id']);
        });
    },

    'refuses generation when the project has no registry' => static function (): void {
        ai_test_use_secret();
        $staff = ai_test_staff();
        ai_test_configured_engine($staff);
        $owner = make_user('ai-gate-registry@example.com');
        $project = ai_test_project($owner, ['registry' => null]);

        assert_app_error('ai_engine_registry_missing', static function () use ($owner, $project): void {
            DraftService::generate((string) $owner['user']['id'], (string) $project['id']);
        });
    },

    'refuses generation when the registry template is not published' => static function (): void {
        ai_test_use_secret();
        $staff = ai_test_staff();
        ai_test_configured_engine($staff);
        $owner = make_user('ai-gate-template@example.com');
        $template = ai_test_seed_template('gold_standard', 'draft');
        $project = ai_test_project($owner, ['template_version_id' => $template['version_id']]);
        ai_test_seed_fact_sheet((string) $project['id'], (string) $owner['team']['id'], (string) $owner['user']['id'], [
            ['key' => 'project.description', 'label' => 'Project description', 'columns' => ['value_text' => 'Improved cookstoves']],
        ]);

        assert_app_error('ai_engine_no_published_template', static function () use ($owner, $project): void {
            DraftService::generate((string) $owner['user']['id'], (string) $project['id']);
        });
    },

    'refuses generation without an approved fact sheet' => static function (): void {
        ai_test_use_secret();
        $staff = ai_test_staff();
        ai_test_configured_engine($staff);
        $owner = make_user('ai-gate-factsheet@example.com');
        $template = ai_test_seed_template();
        $project = ai_test_project($owner, ['template_version_id' => $template['version_id']]);

        assert_app_error('ai_engine_no_approved_fact_sheet', static function () use ($owner, $project): void {
            DraftService::generate((string) $owner['user']['id'], (string) $project['id']);
        });
    },

    'refuses generation when the engine is off or has no key' => static function (): void {
        ai_test_use_secret();
        $owner = make_user('ai-gate-engine@example.com');
        $template = ai_test_seed_template();
        $project = ai_test_project($owner, ['template_version_id' => $template['version_id']]);
        ai_test_seed_fact_sheet((string) $project['id'], (string) $owner['team']['id'], (string) $owner['user']['id'], [
            ['key' => 'project.description', 'label' => 'Project description', 'columns' => ['value_text' => 'Improved cookstoves']],
        ]);

        // Default row: enabled = 0.
        assert_app_error('ai_engine_disabled', static function () use ($owner, $project): void {
            DraftService::generate((string) $owner['user']['id'], (string) $project['id']);
        });

        // Enabled but with no stored/env key.
        $staff = ai_test_staff();
        ai_test_configured_engine($staff);
        Db::execute('UPDATE ai_engine_settings SET api_key_ciphertext = NULL, api_key_last4 = NULL WHERE id = 1');
        assert_app_error('ai_engine_not_configured', static function () use ($owner, $project): void {
            DraftService::generate((string) $owner['user']['id'], (string) $project['id']);
        });
    },

    'generates a labelled draft with cited sections, a snapshot and an audit entry' => static function (): void {
        ai_test_use_secret();
        $staff = ai_test_staff();
        ai_test_configured_engine($staff);
        $owner = make_user('ai-happy-owner@example.com');
        $ownerId = (string) $owner['user']['id'];
        $teamId = (string) $owner['team']['id'];

        $template = ai_test_seed_template();
        $project = ai_test_project($owner, ['template_version_id' => $template['version_id'], 'status' => 'facts_pending_approval']);
        $facts = ai_test_seed_fact_sheet((string) $project['id'], $teamId, $ownerId, [
            ['key' => 'project.description', 'label' => 'Project description', 'columns' => ['value_text' => 'Improved cookstoves for households']],
            ['key' => 'project.location', 'label' => 'Project location', 'columns' => ['value_text' => 'Kisumu, Kenya']],
        ]);
        $descriptionFactId = $facts['fact_ids']['project.description'];

        $calls = [];
        $client = ai_test_transport_client(
            static fn (): array => ai_test_ok_body(ai_test_generated_answer($descriptionFactId)),
            $calls,
        );

        $result = DraftService::generate($ownerId, (string) $project['id'], $client);

        assert_same(2, $result['counts']['generated']);
        assert_same(0, $result['counts']['needs_input']);
        assert_same(2, count($calls), 'one provider call per template section');
        assert_same('draft', (string) $result['draft']['is_labeled_draft']);
        assert_same((string) $template['version_id'], (string) $result['draft']['template_version_id']);
        assert_same($facts['fact_sheet_id'], (string) $result['draft']['fact_sheet_version_id']);
        assert_same('en', (string) $result['draft']['language']);
        assert_same(1, (int) $result['draft']['version']);

        // Sections + citations (+ version snapshot).
        $sections = Db::select('SELECT * FROM draft_sections WHERE draft_id = ? ORDER BY heading ASC', [(string) $result['draft']['id']]);
        assert_same(2, count($sections));
        foreach ($sections as $section) {
            assert_same('generated', (string) $section['status']);
            assert_true((string) $section['content_text'] !== '', 'generated section has text');
            $citations = json_decode((string) $section['citations'], true);
            assert_true(is_array($citations) && $citations !== [], 'generated section cites a fact');
            assert_same($descriptionFactId, (string) $citations[0]['factId']);
            assert_same('Project description', (string) $citations[0]['label']);
        }
        assert_same(1, count_rows('draft_versions', 'draft_id = ?', [(string) $result['draft']['id']]));
        $snapshot = json_decode((string) Db::selectOne('SELECT snapshot FROM draft_versions WHERE draft_id = ?', [(string) $result['draft']['id']])['snapshot'], true);
        assert_same(2, count($snapshot['sections']));
        assert_same('mock-model', $snapshot['model']);

        // The project moved forward and the action is audited.
        $updated = Db::selectOne('SELECT status FROM projects WHERE id = ?', [(string) $project['id']]);
        assert_same('drafting', (string) $updated['status']);
        assert_same(1, count_rows('audit_logs', "action = 'draft.generated' AND target_id = ?", [(string) $result['draft']['id']]));

        // The draft page view resolves the owner and keeps citation labels.
        $view = DraftService::view($ownerId, (string) $result['draft']['id']);
        assert_same(2, count($view['sections']));
        assert_same('Test gold_standard PDD template', (string) $view['template']['template_name']);
        assert_same(1, (int) $view['fact_sheet']['version']);

        // No prompt or stored row may contain the API key.
        foreach ($calls as $call) {
            assert_false(str_contains((string) $call['body'], 'sk-test-secret-1234'), 'prompt body leaks the key');
        }
        $storedSections = json_encode(Db::select('SELECT * FROM draft_sections WHERE draft_id = ?', [(string) $result['draft']['id']]));
        assert_false(str_contains((string) $storedSections, 'sk-test-secret-1234'), 'draft rows leak the key');
    },

    'drafting a project without facts yields needs_input sections and no model calls' => static function (): void {
        ai_test_use_secret();
        $staff = ai_test_staff();
        ai_test_configured_engine($staff);
        $owner = make_user('ai-nofacts-owner@example.com');
        $template = ai_test_seed_template();
        $project = ai_test_project($owner, ['template_version_id' => $template['version_id']]);
        // Approved fact sheet with no facts and no input text.
        ai_test_seed_fact_sheet((string) $project['id'], (string) $owner['team']['id'], (string) $owner['user']['id'], []);

        $calls = [];
        $client = ai_test_transport_client(static fn (): array => ai_test_ok_body(ai_test_generated_answer(Uuid::v4())), $calls);

        $result = DraftService::generate((string) $owner['user']['id'], (string) $project['id'], $client);

        assert_same(0, count($calls), 'no model call when there is nothing to ground a draft');
        assert_same(0, $result['counts']['generated']);
        assert_same(2, $result['counts']['needs_input']);
        foreach (Db::select('SELECT * FROM draft_sections WHERE draft_id = ?', [(string) $result['draft']['id']]) as $section) {
            assert_same('needs_input', (string) $section['status']);
            assert_null($section['content_text']);
            $content = json_decode((string) $section['content_json'], true);
            assert_same('no_facts', (string) $content['reason']);
        }
        assert_same('draft', (string) $result['draft']['is_labeled_draft']);
    },

    'downgrades an answer whose number is not in the confirmed facts' => static function (): void {
        ai_test_use_secret();
        $staff = ai_test_staff();
        ai_test_configured_engine($staff);
        $owner = make_user('ai-number-owner@example.com');
        $template = ai_test_seed_template();
        $project = ai_test_project($owner, ['template_version_id' => $template['version_id']]);
        $facts = ai_test_seed_fact_sheet((string) $project['id'], (string) $owner['team']['id'], (string) $owner['user']['id'], [
            ['key' => 'project.reductions', 'label' => 'Expected reductions', 'category' => 'baseline', 'columns' => [
                'value_type' => 'number',
                'value_numeric' => '12000',
                'unit' => 'tCO2e',
            ]],
        ]);
        $factId = $facts['fact_ids']['project.reductions'];

        $calls = [];
        $client = ai_test_transport_client(
            static fn (): array => ai_test_ok_body(ai_test_generated_answer($factId, 'The project delivers 99,999 tCO2e of reductions.')),
            $calls,
        );

        $result = DraftService::generate((string) $owner['user']['id'], (string) $project['id'], $client);
        assert_same(0, $result['counts']['generated'], 'unsupported number must not be accepted as generated');

        $section = Db::selectOne('SELECT * FROM draft_sections WHERE draft_id = ? ORDER BY heading ASC LIMIT 1', [(string) $result['draft']['id']]);
        assert_same('needs_input', (string) $section['status']);
        assert_null($section['content_text'], 'invented number is not stored as content');
        $content = json_decode((string) $section['content_json'], true);
        assert_same('unsupported_number', (string) $content['reason']);

        // A number that is in the confirmed facts is accepted.
        $allowedCalls = [];
        $allowedClient = ai_test_transport_client(
            static fn (): array => ai_test_ok_body(ai_test_generated_answer($factId, 'Expected reductions are 12,000 tCO2e.')),
            $allowedCalls,
        );
        $accepted = DraftService::generate((string) $owner['user']['id'], (string) $project['id'], $allowedClient);
        assert_same(2, $accepted['counts']['generated']);
    },

    'rejects a generated answer without a citation' => static function (): void {
        ai_test_use_secret();
        $staff = ai_test_staff();
        ai_test_configured_engine($staff);
        $owner = make_user('ai-citation-owner@example.com');
        $template = ai_test_seed_template();
        $project = ai_test_project($owner, ['template_version_id' => $template['version_id']]);
        ai_test_seed_fact_sheet((string) $project['id'], (string) $owner['team']['id'], (string) $owner['user']['id'], [
            ['key' => 'project.description', 'label' => 'Project description', 'columns' => ['value_text' => 'Improved cookstoves']],
        ]);

        $uncited = json_encode(['status' => 'generated', 'content' => 'The project distributes improved cookstoves.', 'citations' => []]);
        $calls = [];
        $client = ai_test_transport_client(static fn (): array => ai_test_ok_body((string) $uncited), $calls);

        $result = DraftService::generate((string) $owner['user']['id'], (string) $project['id'], $client);
        assert_same(0, $result['counts']['generated']);
        $section = Db::selectOne('SELECT * FROM draft_sections WHERE draft_id = ? ORDER BY heading ASC LIMIT 1', [(string) $result['draft']['id']]);
        $content = json_decode((string) $section['content_json'], true);
        assert_same('missing_citation', (string) $content['reason']);
    },

    'requires the editor role and hides other tenants' => static function (): void {
        ai_test_use_secret();
        $owner = make_user('ai-roles-owner@example.com');
        $template = ai_test_seed_template();
        $project = ai_test_project($owner, ['template_version_id' => $template['version_id']]);
        ai_test_seed_fact_sheet((string) $project['id'], (string) $owner['team']['id'], (string) $owner['user']['id'], [
            ['key' => 'project.description', 'label' => 'Project description', 'columns' => ['value_text' => 'Improved cookstoves']],
        ]);

        // Viewer in the same workspace: forbidden, not a silent success.
        $viewer = add_team_member((string) $owner['team']['id'], (string) $owner['user']['id'], 'ai-roles-viewer@example.com', 'viewer');
        assert_app_error('forbidden', static function () use ($viewer, $project): void {
            DraftService::generate((string) $viewer['user']['id'], (string) $project['id']);
        });

        // Editor in the same workspace is allowed through the role gate (it then
        // fails later because the engine is off in this test).
        $editor = add_team_member((string) $owner['team']['id'], (string) $owner['user']['id'], 'ai-roles-editor@example.com', 'editor');
        assert_app_error('ai_engine_disabled', static function () use ($editor, $project): void {
            DraftService::generate((string) $editor['user']['id'], (string) $project['id']);
        });

        // Another tenant is a 404, never a 403.
        $outsider = make_user('ai-roles-outsider@example.com');
        assert_app_error('not_found', static function () use ($outsider, $project): void {
            DraftService::generate((string) $outsider['user']['id'], (string) $project['id']);
        });
        assert_app_error('not_found', static function () use ($outsider): void {
            DraftService::view((string) $outsider['user']['id'], Uuid::v4());
        });

        // A second generation is a new draft version for the same project.
        ai_test_use_secret();
        $staff = ai_test_staff();
        ai_test_configured_engine($staff);
        $facts = Db::selectOne('SELECT id FROM facts WHERE project_id = ? LIMIT 1', [(string) $project['id']]);
        $clientCalls = [];
        $client = ai_test_transport_client(
            static fn (): array => ai_test_ok_body(ai_test_generated_answer((string) $facts['id'])),
            $clientCalls,
        );
        $first = DraftService::generate((string) $owner['user']['id'], (string) $project['id'], $client);
        $second = DraftService::generate((string) $owner['user']['id'], (string) $project['id'], $client);
        assert_same(1, (int) $first['draft']['version']);
        assert_same(2, (int) $second['draft']['version']);
    },
];
