<?php

declare(strict_types=1);

namespace App\Services;

use App\AppError;
use App\Audit;
use App\Db;
use App\Uuid;

/**
 * AI Engine test bench and few-shot example library.
 *
 * Staff paste or upload (.txt/.md) a sample input, run it through the configured
 * engine with the selected registry SKILL, and see the generated PDD-style
 * output. A run is ephemeral: it never creates a draft, never touches a project
 * or fact sheet, and never enters the shared template library.
 *
 * A staff member can save an input/output pair as a few-shot "training example"
 * for a registry. Enabled examples are injected into future drafting prompts as
 * STYLE examples. This is in-context skill tuning only — **not** fine-tuning:
 * no model weights change, nothing is sent to a training endpoint, and the
 * provider only ever sees the examples inside a normal prompt.
 *
 * Privacy: examples are staff-authored test snippets. Customer project data must
 * never be saved here (the UI and docs say so; nothing in this service accepts a
 * project id).
 *
 * Access: every management method uses the platform staff guard, so a tenant
 * account receives the same 404 as the rest of the console.
 */
final class AiEngineTestbenchService
{
    /** Registries with a drafting skill. */
    public const REGISTRIES = ['gold_standard', 'verra_vcs'];

    /** Maximum pasted/uploaded input accepted by the bench (characters). */
    public const MAX_INPUT_CHARS = 20000;

    /** Maximum saved output length (characters). */
    public const MAX_OUTPUT_CHARS = 20000;

    public const MAX_TITLE = 160;

    /** Upload cap: 256 KB of .txt/.md. */
    public const MAX_UPLOAD_BYTES = 262144;

    public const ALLOWED_EXTENSIONS = ['txt', 'md'];

    /** Few-shot budget: at most N examples and ~2000 characters in one prompt. */
    public const MAX_EXAMPLES_IN_PROMPT = 3;
    public const MAX_EXAMPLE_PROMPT_CHARS = 2000;

    /**
     * Run one sample input through the configured engine (ephemeral).
     *
     * @return array{output: string, model: string, latency_ms: int, registry: string, input_chars: int, output_chars: int}
     */
    public static function run(
        string $actorUserId,
        string $registry,
        string $inputText,
        ?string $expectedOutput = null,
        ?LlmClient $client = null,
    ): array {
        TaskCenterService::assertStaff($actorUserId);
        $registry = self::assertRegistry($registry);

        $settingsRow = AiEngineService::settings();
        if ((int) $settingsRow['enabled'] !== 1) {
            throw new AppError('ai_engine_disabled', 503, 'The AI engine is switched off.');
        }
        $clientSettings = AiEngineService::clientSettings();
        $client ??= LlmClient::forSettings($clientSettings);

        $text = trim($inputText);
        if ($text === '') {
            throw AppError::validation(['input_text' => 'required']);
        }
        if (mb_strlen($text) > self::MAX_INPUT_CHARS) {
            throw AppError::validation(['input_text' => 'too_long']);
        }

        $systemPrompt = self::systemPrompt($registry);
        $userPrompt = self::userPrompt($registry, $text, trim((string) $expectedOutput));
        $startedAt = microtime(true);

        try {
            $response = $client->chat(
                $clientSettings,
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                min((int) $clientSettings['max_tokens'], 1200),
            );
        } catch (AppError $error) {
            Audit::record([
                'actor_user_id' => $actorUserId,
                'action' => 'ai_engine.test_run',
                'target_type' => 'ai_engine',
                'target_id' => '1',
                // Never the input text: only shape/meta information.
                'metadata' => [
                    'ok' => false,
                    'registry' => $registry,
                    'input_chars' => mb_strlen($text),
                    'error' => $error->errorCode,
                ],
            ]);
            throw $error;
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        $output = self::extractOutput((string) $response['content']);

        Audit::record([
            'actor_user_id' => $actorUserId,
            'action' => 'ai_engine.test_run',
            'target_type' => 'ai_engine',
            'target_id' => '1',
            'metadata' => [
                'ok' => true,
                'registry' => $registry,
                'model' => $response['model'],
                'latency_ms' => $latencyMs,
                'input_chars' => mb_strlen($text),
                'output_chars' => mb_strlen($output),
            ],
        ]);

        return [
            'output' => $output,
            'model' => $response['model'],
            'latency_ms' => $latencyMs,
            'registry' => $registry,
            'input_chars' => mb_strlen($text),
            'output_chars' => mb_strlen($output),
        ];
    }

    /**
     * Resolve the bench input from a paste box and/or an uploaded .txt/.md file.
     * Validation is part of the service so every caller shares the same limits.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public static function inputFromRequest(array $post, array $files): string
    {
        $text = trim((string) ($post['input_text'] ?? ''));

        $upload = $files['input_file'] ?? null;
        if (is_array($upload) && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ((int) ($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                throw AppError::validation(['input_file' => 'invalid_choice'], 'The uploaded file could not be read.');
            }

            $name = (string) ($upload['name'] ?? '');
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                throw AppError::validation(['input_file' => 'invalid_choice'], 'Only .txt and .md files are supported.');
            }
            if ((int) ($upload['size'] ?? 0) > self::MAX_UPLOAD_BYTES) {
                throw AppError::validation(['input_file' => 'too_long'], 'The uploaded file is too large (256 KB maximum).');
            }

            $content = file_get_contents((string) ($upload['tmp_name'] ?? ''));
            if (!is_string($content)) {
                throw AppError::validation(['input_file' => 'invalid_choice'], 'The uploaded file could not be read.');
            }
            $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);
            $text = trim($text === '' ? $content : $text . "\n\n" . $content);
        }

        if ($text === '') {
            throw AppError::validation(['input_text' => 'required']);
        }
        if (mb_strlen($text) > self::MAX_INPUT_CHARS) {
            throw AppError::validation(['input_text' => 'too_long']);
        }

        return $text;
    }

    /**
     * All examples for the admin page (staff only), oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function examples(string $actorUserId): array
    {
        TaskCenterService::assertStaff($actorUserId);

        return Db::select(
            'SELECT e.*, u.name AS created_by_name
             FROM ai_engine_examples e
             LEFT JOIN users u ON u.id = e.created_by_user_id
             ORDER BY e.registry ASC, e.ordinal ASC, e.created_at ASC',
        );
    }

    /**
     * Save an input/output pair as a few-shot example for a registry.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function saveExample(string $actorUserId, array $input): array
    {
        TaskCenterService::assertStaff($actorUserId);

        $errors = [];
        $registry = trim((string) ($input['registry'] ?? ''));
        if (!in_array($registry, self::REGISTRIES, true)) {
            $errors['registry'] = 'invalid_choice';
            $registry = self::REGISTRIES[0];
        }

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $errors['title'] = 'required';
        } elseif (mb_strlen($title) > self::MAX_TITLE) {
            $errors['title'] = 'too_long';
        }

        $inputText = trim((string) ($input['input_text'] ?? ''));
        if ($inputText === '') {
            $errors['input_text'] = 'required';
        } elseif (mb_strlen($inputText) > self::MAX_INPUT_CHARS) {
            $errors['input_text'] = 'too_long';
        }

        $outputText = trim((string) ($input['output_text'] ?? ''));
        if (mb_strlen($outputText) > self::MAX_OUTPUT_CHARS) {
            $errors['output_text'] = 'too_long';
        }

        if ($errors !== []) {
            throw AppError::validation($errors);
        }

        $ordinalRow = Db::selectOne('SELECT COALESCE(MAX(ordinal), 0) AS o FROM ai_engine_examples');
        $ordinal = (int) ($ordinalRow['o'] ?? 0) + 1;
        $id = Uuid::v4();

        Db::insert('ai_engine_examples', [
            'id' => $id,
            'registry' => $registry,
            'title' => $title,
            'input_text' => $inputText,
            'output_text' => $outputText !== '' ? $outputText : null,
            'enabled' => true,
            'ordinal' => $ordinal,
            'created_by_user_id' => $actorUserId,
        ]);

        Audit::record([
            'actor_user_id' => $actorUserId,
            'action' => 'ai_engine.example_saved',
            'target_type' => 'ai_engine_example',
            'target_id' => $id,
            'metadata' => [
                'registry' => $registry,
                'title' => $title,
                'input_chars' => mb_strlen($inputText),
                'output_chars' => mb_strlen($outputText),
            ],
        ]);

        $row = Db::selectOne('SELECT * FROM ai_engine_examples WHERE id = ?', [$id]);
        if ($row === null) {
            throw AppError::internal();
        }

        return $row;
    }

    /** Enable or disable one example (staff only). @return array<string, mixed> */
    public static function toggleExample(string $actorUserId, string $exampleId, bool $enabled): array
    {
        TaskCenterService::assertStaff($actorUserId);
        $example = self::requireExample($exampleId);

        Db::execute('UPDATE ai_engine_examples SET enabled = ? WHERE id = ?', [$enabled ? 1 : 0, $exampleId]);

        Audit::record([
            'actor_user_id' => $actorUserId,
            'action' => $enabled ? 'ai_engine.example_enabled' : 'ai_engine.example_disabled',
            'target_type' => 'ai_engine_example',
            'target_id' => $exampleId,
            'metadata' => ['registry' => (string) $example['registry'], 'title' => (string) $example['title']],
        ]);

        return self::requireExample($exampleId);
    }

    /** Delete one example (staff only). */
    public static function deleteExample(string $actorUserId, string $exampleId): void
    {
        TaskCenterService::assertStaff($actorUserId);
        $example = self::requireExample($exampleId);

        Db::execute('DELETE FROM ai_engine_examples WHERE id = ?', [$exampleId]);

        Audit::record([
            'actor_user_id' => $actorUserId,
            'action' => 'ai_engine.example_deleted',
            'target_type' => 'ai_engine_example',
            'target_id' => $exampleId,
            'metadata' => ['registry' => (string) $example['registry'], 'title' => (string) $example['title']],
        ]);
    }

    /**
     * Enabled examples for one registry, prompt-sized (internal drafting helper).
     *
     * @return list<array<string, mixed>>
     */
    public static function enabledExamples(string $registry): array
    {
        return Db::select(
            'SELECT id, registry, title, input_text, output_text, ordinal
             FROM ai_engine_examples
             WHERE registry = ? AND enabled = 1
             ORDER BY ordinal ASC, created_at ASC
             LIMIT ' . self::MAX_EXAMPLES_IN_PROMPT,
            [$registry],
        );
    }

    /**
     * The few-shot block injected into the drafting system prompt: enabled
     * examples for the registry, character-budget-capped, clearly labelled as
     * style-only. Empty string when there are none.
     */
    public static function examplesPromptBlock(string $registry): string
    {
        $examples = self::enabledExamples($registry);
        if ($examples === []) {
            return '';
        }

        $budget = self::MAX_EXAMPLE_PROMPT_CHARS;
        $blocks = [];
        foreach ($examples as $index => $example) {
            $title = trim((string) $example['title']);
            $input = trim((string) $example['input_text']);
            $output = trim((string) ($example['output_text'] ?? ''));
            $block = 'EXAMPLE ' . ($index + 1) . ($title !== '' ? ' — ' . $title : '') . "\n"
                . "SAMPLE INPUT:\n" . $input . "\n"
                . "STYLE EXAMPLE OUTPUT:\n" . ($output !== '' ? $output : '(no saved output)');
            if (mb_strlen($block) > $budget) {
                if ($budget < 200) {
                    break;
                }
                $block = mb_substr($block, 0, $budget);
            }
            $blocks[] = $block;
            $budget -= mb_strlen($block) + 2;
            if ($budget <= 0) {
                break;
            }
        }

        return implode("\n\n", $blocks);
    }

    public static function registryLabel(string $registry): string
    {
        return match ($registry) {
            'gold_standard' => 'Gold Standard',
            'verra_vcs' => 'Verra VCS',
            default => 'Registry',
        };
    }

    /** @return array<string, mixed> */
    private static function requireExample(string $exampleId): array
    {
        $example = Db::selectOne('SELECT * FROM ai_engine_examples WHERE id = ?', [$exampleId]);
        if ($example === null) {
            throw AppError::notFound('That example');
        }

        return $example;
    }

    private static function assertRegistry(string $registry): string
    {
        if (!in_array($registry, self::REGISTRIES, true)) {
            throw AppError::validation(['registry' => 'invalid_choice']);
        }

        return $registry;
    }

    /**
     * The bench prompt: use the registry skill, treat the pasted sample as the
     * only source of facts, and never invent figures.
     */
    private static function systemPrompt(string $registry): string
    {
        $settings = AiEngineService::settings();
        $global = trim(AiEngineService::globalInstructions($settings));
        $skill = trim(AiEngineService::skillForRegistry($registry, $settings));

        $prompt = "You are the drafting engine of an AI PDD Generator. You are running a STAFF TEST BENCH for the "
            . self::registryLabel($registry) . " drafting skill. Produce a short PDD-style draft section from the sample input.\n";
        $prompt .= "The output is a test sample for staff review, never an approved document and never a real project draft.\n\n";

        if ($global !== '') {
            $prompt .= "GLOBAL EDITORIAL INSTRUCTIONS (admin-managed):\n{$global}\n\n";
        }
        if ($skill !== '') {
            $prompt .= self::registryLabel($registry) . " GUIDANCE AND RULES (admin-managed skill):\n{$skill}\n\n";
        }

        $prompt .= "NON-NEGOTIABLE RULES:\n";
        $prompt .= "- Use ONLY the sample input in the user message. Never invent facts, figures, dates, names, locations, units or organisations.\n";
        $prompt .= "- Copy any number/date verbatim from the sample input; do not calculate or convert.\n";
        $prompt .= "- If the sample input is insufficient, say what is missing instead of guessing.\n";
        $prompt .= "- Follow the registry format rules (dates DD/MM/YYYY, thousands commas and decimal point, unique figure/table ids).\n";
        $prompt .= "- Return ONLY a JSON object, no markdown fences:\n";
        $prompt .= "{\"status\":\"generated\",\"content\":\"the drafted section text\"}\n";
        $prompt .= "or {\"status\":\"needs_input\",\"reason\":\"what is missing\"}\n";

        return $prompt;
    }

    private static function userPrompt(string $registry, string $inputText, string $expectedOutput): string
    {
        $prompt = 'REGISTRY: ' . self::registryLabel($registry) . "\n\n";
        $prompt .= "SAMPLE INPUT (the only authorised source):\n" . $inputText . "\n\n";
        if ($expectedOutput !== '') {
            $prompt .= "EXPECTED OUTPUT / NOTES FROM STAFF (guidance for style and length only; never a source of facts):\n" . $expectedOutput . "\n\n";
        }
        $prompt .= 'TASK: draft one PDD-style section for the sample input. Return JSON only.';

        return $prompt;
    }

    /** Parse the bench answer; fall back to raw text if the model ignored JSON. */
    private static function extractOutput(string $raw): string
    {
        $text = trim($raw);
        $stripped = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $stripped = preg_replace('/\s*```$/', '', $stripped) ?? $stripped;
        $start = strpos($stripped, '{');
        $end = strrpos($stripped, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($stripped, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                $content = $decoded['content'] ?? null;
                if (is_string($content) && trim($content) !== '') {
                    return trim($content);
                }
                $reason = $decoded['reason'] ?? null;
                if (is_string($reason) && trim($reason) !== '') {
                    return trim($reason);
                }
            }
        }

        return $text;
    }
}
