<?php

declare(strict_types=1);

/**
 * AI Engine test bench + few-shot skill examples (task-21).
 *
 * Offline: every provider call uses a mocked transport. Covers the ephemeral
 * bench run, input validation (.txt/.md only), example management, prompt
 * budget/disabled handling, DraftService few-shot injection, staff guard and
 * secret non-leakage.
 */

use App\AppError;
use App\Config;
use App\Db;
use App\Repositories\UserRepository;
use App\Services\AiEngineService;
use App\Services\AiEngineTestbenchService;
use App\Services\DraftService;
use App\Services\LlmClient;
use App\Services\ProjectService;
use App\Uuid;

function tb_secret_value(): string
{
    return 'ai-engine-testbench-unit-secret-42a7';
}

/** Pin the deployment secret and empty the env key fallback for this test. */
function tb_use_secret(): void
{
    putenv('AI_ENGINE_SECRET=' . tb_secret_value());
    putenv('AI_ENGINE_API_KEY');
    Config::load(require APP_ROOT . '/config/config.php');
}

function tb_staff(string $email = 'tb-staff@example.com'): array
{
    $account = make_user($email, 'Test Bench Staff');
    Db::execute('UPDATE users SET is_platform_admin = 1 WHERE id = ?', [(string) $account['user']['id']]);
    $account['user'] = UserRepository::findById((string) $account['user']['id']);
    if ($account['user'] === null) {
        throw new RuntimeException('Failed to load test-bench staff user');
    }

    return $account;
}

/** Configure a ready engine (real key never leaves the test). */
function tb_configure_engine(array $staff, string $apiKey = 'sk-tb-secret-1234'): array
{
    tb_use_secret();

    return AiEngineService::save((string) $staff['user']['id'], [
        'provider' => 'openai',
        'base_url' => 'https://api.openai.com/v1',
        'model' => 'gpt-4o-mini',
        'temperature' => '0.2',
        'max_tokens' => '600',
        'api_key' => $apiKey,
        'enabled' => '1',
        'skill_gold_standard' => 'TB-GOLD-SKILL: follow the Gold Standard headings.',
        'skill_verra_vcs' => 'TB-VERRA-SKILL: follow the Verra template.',
        'global_instructions' => 'TB-GLOBAL: write concise factual prose.',
    ]);
}

/**
 * Fake client. The handler receives the request and the 1-based attempt number
 * and returns ['status'=>int,'body'=>string]; captured requests land in $calls.
 */
function tb_client(callable $handler, ?array &$calls = null): LlmClient
{
    $calls = [];

    return new LlmClient(static function (array $request) use ($handler, &$calls): array {
        $calls[] = $request;

        return $handler($request, count($calls));
    });
}

function tb_ok_body(string $content): array
{
    return [
        'status' => 200,
        'body' => (string) json_encode([
            'model' => 'mock-model',
            'choices' => [['message' => ['content' => $content]]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

function tb_bench_answer(string $content = 'Sample PDD-style draft text from the bench.'): string
{
    return (string) json_encode(['status' => 'generated', 'content' => $content]);
}

function tb_seed_published_template(string $registry = 'gold_standard'): string
{
    $templateId = Uuid::v4();
    $versionId = Uuid::v4();
    Db::insert('templates', [
        'id' => $templateId,
        'name' => 'TB ' . $registry . ' template',
        'kind' => 'pdd_template',
        'registry' => $registry,
        'language' => 'en',
    ]);
    Db::insert('template_versions', [
        'id' => $versionId,
        'template_id' => $templateId,
        'version_label' => 'v-tb',
        'language' => 'en',
        'effective_date' => '2026-01-01',
        'status' => 'published',
        'source_url' => 'https://example.org/tb.pdf',
        'license_note' => 'TB fixture.',
    ]);
    Db::insert('template_sections', [
        'id' => Uuid::v4(),
        'template_version_id' => $versionId,
        'parent_section_id' => null,
        'ordinal' => 1,
        'stable_key' => 'A.1',
        'heading' => 'Purpose and general description',
        'level' => 2,
        'is_required' => true,
        'allow_intentionally_blank' => true,
        'guidance' => 'Use confirmed facts only.',
        'format_rules' => json_encode(['date_format' => 'DD/MM/YYYY']),
    ]);

    return $versionId;
}

/** @return array<string, mixed> */
function tb_project(array $owner, string $templateVersionId, string $registry = 'gold_standard'): array
{
    $project = ProjectService::create(
        (string) $owner['user']['id'],
        (string) $owner['team']['id'],
        'TB Project',
    );
    Db::execute(
        "UPDATE projects SET registry = ?, template_version_id = ?, country_code = 'KE', project_type = 'cookstoves', language = 'en', status = 'facts_pending_approval' WHERE id = ?",
        [$registry, $templateVersionId, (string) $project['id']],
    );

    return Db::selectOne('SELECT * FROM projects WHERE id = ?', [(string) $project['id']]) ?? $project;
}

/** @return array{fact_id: string, fact_sheet_id: string} */
function tb_fact_sheet(array $owner, array $project): array
{
    $factId = Uuid::v4();
    Db::insert('facts', [
        'id' => $factId,
        'project_id' => (string) $project['id'],
        'team_id' => (string) $owner['team']['id'],
        'category' => 'project',
        'fact_key' => 'project.description',
        'label' => 'Project description',
        'value_type' => 'string',
        'value_text' => 'Improved cookstoves for rural households.',
        'status' => 'confirmed',
        'confirmed_by_user_id' => (string) $owner['user']['id'],
        'confirmed_at' => gmdate('Y-m-d H:i:s'),
    ]);
    $factSheetId = Uuid::v4();
    Db::insert('fact_sheet_versions', [
        'id' => $factSheetId,
        'project_id' => (string) $project['id'],
        'version' => 1,
        'status' => 'approved',
        'snapshot' => json_encode(['fact_ids' => [$factId]]),
        'created_by_user_id' => (string) $owner['user']['id'],
        'approved_by_user_id' => (string) $owner['user']['id'],
        'approved_at' => gmdate('Y-m-d H:i:s'),
    ]);

    return ['fact_id' => $factId, 'fact_sheet_id' => $factSheetId];
}

return [
    'test bench runs the configured engine and audits without storing the input' => static function (): void {
        tb_use_secret();
        $staff = tb_staff();
        $staffId = (string) $staff['user']['id'];
        tb_configure_engine($staff);

        $calls = [];
        $client = tb_client(static fn (): array => tb_ok_body(tb_bench_answer()), $calls);

        $inputText = 'Project: 500 improved cookstoves for households in Kisumu County.';
        $result = AiEngineTestbenchService::run($staffId, 'gold_standard', $inputText, 'Keep it concise.', $client);

        assert_same('Sample PDD-style draft text from the bench.', $result['output']);
        assert_same('mock-model', $result['model']);
        assert_same('gold_standard', $result['registry']);
        assert_true(is_int($result['latency_ms']) && $result['latency_ms'] >= 0, 'latency reported');
        assert_same(1, count($calls), 'one provider call per run');

        $request = $calls[0];
        $body = json_decode((string) $request['body'], true);
        $system = (string) ($body['messages'][0]['content'] ?? '');
        $user = (string) ($body['messages'][1]['content'] ?? '');
        assert_contains('TEST BENCH', $system, 'bench prompt identified');
        assert_contains('TB-GOLD-SKILL', $system, 'registry skill injected');
        assert_contains('TB-GLOBAL', $system, 'global instructions injected');
        assert_contains($inputText, $user, 'sample input sent');
        assert_contains('Keep it concise.', $user, 'staff notes sent');
        assert_false(str_contains((string) $request['body'], 'sk-tb-secret-1234'), 'key never in the prompt body');
        assert_false(str_contains((string) $request['url'], 'sk-tb-secret-1234'), 'key never in the URL');

        $audit = Db::selectOne("SELECT * FROM audit_logs WHERE action = 'ai_engine.test_run' ORDER BY created_at DESC LIMIT 1");
        assert_not_null($audit);
        $metadata = (string) $audit['metadata'];
        assert_true(str_contains($metadata, '"ok":true'));
        assert_true(str_contains($metadata, 'gold_standard'));
        assert_false(str_contains($metadata, '500 improved cookstoves'), 'input text is not audited');
        assert_false(str_contains($metadata, 'sk-tb-secret-1234'), 'key is not audited');
    },

    'test bench refuses bad input, unknown registries, disabled engines and tenants' => static function (): void {
        tb_use_secret();
        $staff = tb_staff('tb-guards@example.com');
        $staffId = (string) $staff['user']['id'];
        tb_configure_engine($staff);

        $client = tb_client(static fn (): array => tb_ok_body(tb_bench_answer()));

        assert_app_error('validation_error', static function () use ($staffId): void {
            AiEngineTestbenchService::run($staffId, 'gold_standard', '   ');
        });
        assert_app_error('validation_error', static function () use ($staffId): void {
            AiEngineTestbenchService::run($staffId, 'not_a_registry', 'some input');
        });
        assert_app_error('validation_error', static function () use ($staffId): void {
            AiEngineTestbenchService::run($staffId, 'gold_standard', str_repeat('x', AiEngineTestbenchService::MAX_INPUT_CHARS + 1));
        });

        // Engine switched off.
        Db::execute('UPDATE ai_engine_settings SET enabled = 0 WHERE id = 1');
        assert_app_error('ai_engine_disabled', static function () use ($staffId): void {
            AiEngineTestbenchService::run($staffId, 'gold_standard', 'some input');
        });

        // Key removed while enabled.
        tb_configure_engine($staff);
        Db::execute('UPDATE ai_engine_settings SET api_key_ciphertext = NULL, api_key_last4 = NULL WHERE id = 1');
        assert_app_error('ai_engine_not_configured', static function () use ($staffId): void {
            AiEngineTestbenchService::run($staffId, 'gold_standard', 'some input');
        });

        // Tenant accounts get the console 404, never the bench.
        $tenant = make_user('tb-tenant@example.com');
        $tenantId = (string) $tenant['user']['id'];
        assert_app_error('not_found', static function () use ($tenantId): void {
            AiEngineTestbenchService::run($tenantId, 'gold_standard', 'sample', null, tb_client(static fn (): array => tb_ok_body(tb_bench_answer())));
        });
        assert_app_error('not_found', static function () use ($tenantId): void {
            AiEngineTestbenchService::examples($tenantId);
        });
        assert_app_error('not_found', static function () use ($tenantId): void {
            AiEngineTestbenchService::saveExample($tenantId, ['registry' => 'gold_standard', 'title' => 'x', 'input_text' => 'y']);
        });
    },

    'bench input accepts txt/md uploads and rejects other or oversized files' => static function (): void {
        $tmpTxt = (string) tempnam(APP_ROOT . '/storage/logs', 'tb-bench-');
        file_put_contents($tmpTxt, 'Uploaded notes for the bench.');
        try {
            $text = AiEngineTestbenchService::inputFromRequest([], [
                'input_file' => ['error' => UPLOAD_ERR_OK, 'name' => 'notes.txt', 'size' => 30, 'tmp_name' => $tmpTxt],
            ]);
            assert_contains('Uploaded notes for the bench.', $text);

            $text = AiEngineTestbenchService::inputFromRequest(['input_text' => 'Pasted part'], [
                'input_file' => ['error' => UPLOAD_ERR_OK, 'name' => 'notes.md', 'size' => 30, 'tmp_name' => $tmpTxt],
            ]);
            assert_contains('Pasted part', $text);
            assert_contains('Uploaded notes for the bench.', $text);
        } finally {
            @unlink($tmpTxt);
        }

        // PDFs are out of scope in the MVP.
        try {
            AiEngineTestbenchService::inputFromRequest([], [
                'input_file' => ['error' => UPLOAD_ERR_OK, 'name' => 'report.pdf', 'size' => 10, 'tmp_name' => __FILE__],
            ]);
            throw new RuntimeException('Expected the .pdf upload to be rejected');
        } catch (AppError $error) {
            assert_same('validation_error', $error->errorCode);
            assert_same('invalid_choice', $error->fields['input_file'] ?? null);
        }

        // Oversized upload is refused before reading it.
        try {
            AiEngineTestbenchService::inputFromRequest([], [
                'input_file' => [
                    'error' => UPLOAD_ERR_OK,
                    'name' => 'big.txt',
                    'size' => AiEngineTestbenchService::MAX_UPLOAD_BYTES + 1,
                    'tmp_name' => __FILE__,
                ],
            ]);
            throw new RuntimeException('Expected the oversized upload to be rejected');
        } catch (AppError $error) {
            assert_same('too_long', $error->fields['input_file'] ?? null);
        }

        assert_app_error('validation_error', static function (): void {
            AiEngineTestbenchService::inputFromRequest([], []);
        });
    },

    'examples save, toggle, delete and are staff-guarded and audited' => static function (): void {
        tb_use_secret();
        $staff = tb_staff('tb-examples@example.com');
        $staffId = (string) $staff['user']['id'];

        $example = AiEngineTestbenchService::saveExample($staffId, [
            'registry' => 'gold_standard',
            'title' => 'Cookstoves A.1 sample',
            'input_text' => 'Sample input for the cookstoves A.1 section.',
            'output_text' => 'Sample style output for the cookstoves A.1 section.',
        ]);
        assert_same('gold_standard', $example['registry']);
        assert_same('Cookstoves A.1 sample', $example['title']);
        assert_same(1, (int) $example['enabled']);
        assert_same(1, (int) $example['ordinal']);
        assert_same(1, count(AiEngineTestbenchService::examples($staffId)));

        $disabled = AiEngineTestbenchService::toggleExample($staffId, (string) $example['id'], false);
        assert_same(0, (int) $disabled['enabled']);
        assert_same([], AiEngineTestbenchService::enabledExamples('gold_standard'), 'disabled example is not injected');
        $enabled = AiEngineTestbenchService::toggleExample($staffId, (string) $example['id'], true);
        assert_same(1, (int) $enabled['enabled']);
        assert_same(1, count(AiEngineTestbenchService::enabledExamples('gold_standard')));

        AiEngineTestbenchService::deleteExample($staffId, (string) $example['id']);
        assert_same(0, count(AiEngineTestbenchService::examples($staffId)));
        assert_same(0, count(AiEngineTestbenchService::enabledExamples('gold_standard')));

        foreach (['ai_engine.example_saved', 'ai_engine.example_enabled', 'ai_engine.example_disabled', 'ai_engine.example_deleted'] as $action) {
            assert_same(1, count_rows('audit_logs', 'action = ?', [$action]), 'audit ' . $action);
        }
        $savedAudit = Db::selectOne("SELECT * FROM audit_logs WHERE action = 'ai_engine.example_saved' LIMIT 1");
        assert_false(str_contains((string) $savedAudit['metadata'], 'Sample input for the cookstoves'), 'examples audit does not store the text');

        // Validation: registry/title/input required.
        assert_app_error('validation_error', static function () use ($staffId): void {
            AiEngineTestbenchService::saveExample($staffId, ['registry' => 'nope', 'title' => '', 'input_text' => '']);
        });
        assert_app_error('not_found', static function () use ($staffId): void {
            AiEngineTestbenchService::toggleExample($staffId, Uuid::v4(), false);
        });
        assert_app_error('not_found', static function () use ($staffId): void {
            AiEngineTestbenchService::deleteExample($staffId, Uuid::v4());
        });
    },

    'example prompt block is capped and excludes disabled examples' => static function (): void {
        tb_use_secret();
        $staff = tb_staff('tb-budget@example.com');
        $staffId = (string) $staff['user']['id'];

        for ($i = 1; $i <= 5; $i++) {
            AiEngineTestbenchService::saveExample($staffId, [
                'registry' => 'gold_standard',
                'title' => 'Sample ' . $i,
                'input_text' => str_repeat('input-' . $i . ' ', 60),
                'output_text' => str_repeat('output-' . $i . ' ', 60),
            ]);
        }

        $block = AiEngineTestbenchService::examplesPromptBlock('gold_standard');
        assert_true($block !== '', 'block generated');
        assert_true(mb_strlen($block) <= AiEngineTestbenchService::MAX_EXAMPLE_PROMPT_CHARS, 'block respects the character budget');
        $exampleCount = preg_match_all('/^EXAMPLE \d+ —/m', $block);
        assert_true($exampleCount <= AiEngineTestbenchService::MAX_EXAMPLES_IN_PROMPT, 'block respects the example count');

        // Disabled examples disappear from the block; other registries never leak in.
        $first = Db::selectOne("SELECT id FROM ai_engine_examples WHERE title = 'Sample 1'");
        AiEngineTestbenchService::toggleExample($staffId, (string) $first['id'], false);
        $afterDisable = AiEngineTestbenchService::examplesPromptBlock('gold_standard');
        assert_false(str_contains($afterDisable, 'EXAMPLE 1 — Sample 1'), 'disabled example removed');
        AiEngineTestbenchService::saveExample($staffId, [
            'registry' => 'verra_vcs',
            'title' => 'Verra only sample',
            'input_text' => 'verra input',
            'output_text' => 'verra output',
        ]);
        assert_false(str_contains(AiEngineTestbenchService::examplesPromptBlock('gold_standard'), 'Verra only sample'), 'registry isolation');
        assert_contains('Verra only sample', AiEngineTestbenchService::examplesPromptBlock('verra_vcs'));
        assert_same('', AiEngineTestbenchService::examplesPromptBlock('openrouter'), 'unknown registry has no examples');
    },

    'drafting prompts include enabled examples for the project registry' => static function (): void {
        tb_use_secret();
        $staff = tb_staff('tb-drafting@example.com');
        tb_configure_engine($staff);
        $owner = make_user('tb-drafting-owner@example.com');

        $example = AiEngineTestbenchService::saveExample((string) $staff['user']['id'], [
            'registry' => 'gold_standard',
            'title' => 'TB style example',
            'input_text' => 'TB-EXAMPLE-INPUT-MARKER — a sample cookstove project',
            'output_text' => 'TB-EXAMPLE-OUTPUT-MARKER — concise style output.',
        ]);

        $versionId = tb_seed_published_template('gold_standard');
        $project = tb_project($owner, $versionId, 'gold_standard');
        $facts = tb_fact_sheet($owner, $project);
        $answer = (string) json_encode([
            'status' => 'generated',
            'content' => 'Improved cookstoves for rural households.',
            'citations' => [$facts['fact_id']],
        ]);

        $calls = [];
        $client = tb_client(static fn (): array => tb_ok_body($answer), $calls);
        $draft = DraftService::generate((string) $owner['user']['id'], (string) $project['id'], $client);
        assert_same(1, $draft['counts']['generated'], 'draft generated');

        $system = (string) (json_decode((string) $calls[0]['body'], true)['messages'][0]['content'] ?? '');
        assert_contains('FEW-SHOT STYLE EXAMPLES', $system);
        assert_contains('TB-EXAMPLE-INPUT-MARKER', $system);
        assert_contains('TB-EXAMPLE-OUTPUT-MARKER', $system);
        assert_false(str_contains($system, 'sk-tb-secret-1234'), 'prompt never contains the key');

        // A disabled example is not injected into the next draft.
        AiEngineTestbenchService::toggleExample((string) $staff['user']['id'], (string) $example['id'], false);
        $calls = [];
        $client = tb_client(static fn (): array => tb_ok_body($answer), $calls);
        DraftService::generate((string) $owner['user']['id'], (string) $project['id'], $client);
        $system = (string) (json_decode((string) $calls[0]['body'], true)['messages'][0]['content'] ?? '');
        assert_false(str_contains($system, 'FEW-SHOT STYLE EXAMPLES'), 'disabled examples are not injected');
        assert_false(str_contains($system, 'TB-EXAMPLE-INPUT-MARKER'));
    },

    'admin page renders the run-test progress UI as a CSP-safe enhancement' => static function (): void {
        tb_use_secret();
        $staff = tb_staff('tb-progress@example.com');

        $markup = \App\View::capture('pages/admin/ai', [
            'user' => $staff['user'],
            'settings' => AiEngineService::publicSettings(),
            'errors' => [],
            'taskBadge' => 0,
            'bench' => [
                'result' => null,
                'form' => [],
                'examples' => [],
                'registries' => AiEngineTestbenchService::REGISTRIES,
                'max_input_chars' => AiEngineTestbenchService::MAX_INPUT_CHARS,
                'max_upload_kb' => (int) (AiEngineTestbenchService::MAX_UPLOAD_BYTES / 1024),
                'budget_chars' => AiEngineTestbenchService::MAX_EXAMPLE_PROMPT_CHARS,
                'max_examples' => AiEngineTestbenchService::MAX_EXAMPLES_IN_PROMPT,
            ],
        ]);

        // The progress indicator is progressive enhancement: hidden markup,
        // external same-origin assets (CSP script-src/style-src 'self').
        assert_contains('data-ai-bench-form', $markup);
        assert_contains('id="bench-progress"', $markup);
        assert_contains('role="status"', $markup);
        assert_contains('data-bench-bar', $markup);
        assert_contains('data-bench-label', $markup);
        assert_contains('data-bench-elapsed', $markup);
        assert_contains('data-stage-sending', $markup);
        assert_contains('data-stage-waiting', $markup);
        assert_contains('ai-bench.js', $markup);
        assert_contains('ai-bench.css', $markup);
        assert_true(str_contains($markup, 'hidden'), 'progress starts hidden until JavaScript runs');
        assert_false(str_contains($markup, '<script>'), 'no inline script (CSP self)');
        assert_false(str_contains($markup, '<style>'), 'no inline style (CSP self)');
        assert_false(str_contains($markup, 'javascript:'), 'no javascript: URLs');
    },
];
