<?php

declare(strict_types=1);

namespace App\Services;

use App\AppError;
use App\Audit;
use App\Db;
use App\Tenancy;
use App\Uuid;

/**
 * PDD drafting: turns an approved fact sheet plus the project's input excerpts
 * into a section-by-section draft that follows a published registry template.
 *
 * Safety contract (PRD hard rules):
 *  - drafting needs an existing APPROVED fact sheet version;
 *  - drafting needs a PUBLISHED template version for the project's registry
 *    (the seeded Gold Standard v1.4 structure stays a draft until step-2
 *    publishing exists, so generation is refused with a clear message);
 *  - confirmed facts are the only source of numbers: a generated section whose
 *    numbers cannot be traced to the facts/inputs is downgraded to `needs_input`;
 *  - every generated section cites confirmed fact ids; no citation means
 *    `needs_input`, never an uncited claim;
 *  - drafts are always labelled (`drafts.is_labeled_draft = 'draft'`).
 *
 * The LLM client is injectable so tests can run offline with a mocked transport.
 */
final class DraftService
{
    /** Max characters of input text sent to the provider (per draft). */
    public const MAX_INPUT_EXCERPT_CHARS = 4000;

    /** Max confirmed facts included in one prompt. */
    public const MAX_FACTS = 200;

    /** Section statuses written by the generator. */
    public const SECTION_GENERATED = 'generated';
    public const SECTION_NEEDS_INPUT = 'needs_input';

    /**
     * Generate a new draft for a project.
     *
     * @return array{draft: array<string, mixed>, sections: list<array<string, mixed>>, counts: array{generated: int, needs_input: int}, model: string}
     */
    public static function generate(string $actorUserId, string $projectId, ?LlmClient $client = null): array
    {
        // Tenant gate first: another workspace's project is a 404, a viewer is 403.
        $scope = Tenancy::projectForUser($actorUserId, $projectId, 'editor');
        $project = $scope['project'];
        $teamId = (string) $project['team_id'];

        $readiness = self::readiness($project, $scope);
        if (!$readiness['allowed']) {
            $errorCode = (string) $readiness['error_code'];
            $fields = ['reason_key' => 'error.' . $errorCode];
            foreach ($readiness['message_params'] as $key => $value) {
                $fields[(string) $key] = (string) $value;
            }
            throw new AppError($errorCode, (int) $readiness['status'], (string) $readiness['message'], $fields);
        }

        $templateVersion = $readiness['template_version'];
        $factSheetVersion = $readiness['fact_sheet'];
        $settings = AiEngineService::settings();
        // Throws a clear not-configured error if no usable key exists.
        $clientSettings = AiEngineService::clientSettings();
        $client ??= LlmClient::forSettings($clientSettings);

        $sections = self::templateSections((string) $templateVersion['id']);
        if ($sections === []) {
            throw new AppError('ai_engine_template_empty', 503, 'The published template has no sections.', [
                'reason_key' => 'error.ai_engine_template_empty',
            ]);
        }

        $facts = self::confirmedFacts($projectId, $teamId);
        $inputs = self::inputExcerpts($projectId, $teamId);
        $systemPrompt = self::systemPrompt($project, $settings);

        // No confirmed facts and no usable input text: every section is a gap.
        // Do not ask a model to fill it — that is exactly how inventions happen.
        if ($facts === [] && $inputs === []) {
            $results = array_map(
                static fn (array $section): array => [
                    'section' => $section,
                    'result' => self::needsInput('no_facts', ''),
                ],
                $sections,
            );
            $model = 'none';
        } else {
            $results = [];
            $model = (string) $clientSettings['model'];
            foreach ($sections as $section) {
                $messages = [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => self::sectionPrompt($project, $section, $facts, $inputs)],
                ];
                $response = $client->chat($clientSettings, $messages, (int) $clientSettings['max_tokens']);
                $model = (string) $response['model'];
                $results[] = [
                    'section' => $section,
                    'result' => self::normalizeSectionResult(
                        (string) $response['content'],
                        $facts,
                        self::allowedNumberCorpus($project, $section, $facts, $inputs),
                    ),
                ];
            }
        }

        return Db::transaction(static function () use (
            $actorUserId,
            $project,
            $teamId,
            $templateVersion,
            $factSheetVersion,
            $results,
            $model,
        ): array {
            $projectId = (string) $project['id'];
            $draftId = Uuid::v4();
            $versionRow = Db::selectOne('SELECT COALESCE(MAX(version), 0) AS v FROM drafts WHERE project_id = ?', [$projectId]);
            $draftVersion = (int) ($versionRow['v'] ?? 0) + 1;

            Db::insert('drafts', [
                'id' => $draftId,
                'project_id' => $projectId,
                'team_id' => $teamId,
                'template_version_id' => (string) $templateVersion['id'],
                'fact_sheet_version_id' => (string) $factSheetVersion['id'],
                'language' => (string) $project['language'],
                'status' => 'draft',
                'version' => $draftVersion,
                // AI output is always a draft (hard rule).
                'is_labeled_draft' => 'draft',
                'created_by_user_id' => $actorUserId,
            ]);

            $sectionRows = [];
            $generated = 0;
            $needsInput = 0;
            $now = gmdate('Y-m-d H:i:s');

            foreach ($results as $entry) {
                $section = $entry['section'];
                $result = $entry['result'];
                $isGenerated = $result['status'] === self::SECTION_GENERATED;
                $isGenerated ? $generated++ : $needsInput++;

                $contentJson = $isGenerated
                    ? ['type' => 'text', 'text' => $result['content']]
                    : ['type' => 'needs_input', 'reason' => $result['reason_code'], 'note' => $result['note']];

                $row = [
                    'id' => Uuid::v4(),
                    'draft_id' => $draftId,
                    'template_section_id' => (string) $section['id'],
                    'heading' => (string) $section['heading'],
                    'content_json' => json_encode($contentJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'content_text' => $isGenerated ? $result['content'] : null,
                    'status' => $result['status'],
                    'citations' => json_encode($result['citations'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'generated_at' => $now,
                ];
                Db::insert('draft_sections', $row);
                $sectionRows[] = [
                    'id' => $row['id'],
                    'heading' => $row['heading'],
                    'status' => $row['status'],
                    'content_text' => $row['content_text'],
                    'citations' => $result['citations'],
                ];
            }

            $snapshot = [
                'draft_id' => $draftId,
                'project_id' => $projectId,
                'template_version_id' => (string) $templateVersion['id'],
                'fact_sheet_version_id' => (string) $factSheetVersion['id'],
                'model' => $model,
                'generated_at' => $now,
                'sections' => $sectionRows,
            ];
            Db::insert('draft_versions', [
                'id' => Uuid::v4(),
                'draft_id' => $draftId,
                'version' => 1,
                'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_by_user_id' => $actorUserId,
            ]);

            // The project moves into drafting unless it already went further.
            Db::execute(
                "UPDATE projects SET status = 'drafting'
                 WHERE id = ? AND status IN ('setup', 'collecting_inputs', 'extracting_facts', 'facts_pending_approval')",
                [$projectId],
            );

            Audit::record([
                'team_id' => $teamId,
                'actor_user_id' => $actorUserId,
                'action' => 'draft.generated',
                'target_type' => 'draft',
                'target_id' => $draftId,
                'metadata' => [
                    'project_id' => $projectId,
                    'template_version_id' => (string) $templateVersion['id'],
                    'fact_sheet_version_id' => (string) $factSheetVersion['id'],
                    'sections' => count($results),
                    'generated' => $generated,
                    'needs_input' => $needsInput,
                    'model' => $model,
                ],
            ]);

            $draft = Db::selectOne('SELECT * FROM drafts WHERE id = ?', [$draftId]);
            if ($draft === null) {
                throw AppError::internal();
            }

            return [
                'draft' => $draft,
                'sections' => $sectionRows,
                'counts' => ['generated' => $generated, 'needs_input' => $needsInput],
                'model' => $model,
            ];
        });
    }

    /**
     * A draft and its sections for the read-only draft page.
     *
     * @return array{draft: array<string, mixed>, project: array<string, mixed>, sections: list<array<string, mixed>>, template: array<string, mixed>, fact_sheet: array<string, mixed>, counts: array{generated: int, needs_input: int}}
     */
    public static function view(string $actorUserId, string $draftId): array
    {
        $draft = Db::selectOne('SELECT * FROM drafts WHERE id = ? LIMIT 1', [$draftId]);
        if ($draft === null) {
            throw AppError::notFound('That draft');
        }

        // Cross-tenant drafts look exactly like missing drafts (404).
        Tenancy::teamForUser($actorUserId, (string) $draft['team_id'], 'viewer');

        $project = Db::selectOne('SELECT * FROM projects WHERE id = ? AND deleted_at IS NULL LIMIT 1', [(string) $draft['project_id']]);
        if ($project === null || (string) $project['team_id'] !== (string) $draft['team_id']) {
            throw AppError::notFound('That draft');
        }

        $sections = Db::select(
            'SELECT ds.*, ts.ordinal, ts.stable_key, ts.level, ts.is_required
             FROM draft_sections ds
             INNER JOIN template_sections ts ON ts.id = ds.template_section_id
             WHERE ds.draft_id = ?
             ORDER BY ts.ordinal ASC',
            [$draftId],
        );
        foreach ($sections as $index => $section) {
            $citations = json_decode((string) ($section['citations'] ?? '[]'), true);
            $content = json_decode((string) ($section['content_json'] ?? '{}'), true);
            $sections[$index]['citations_list'] = is_array($citations) ? $citations : [];
            $sections[$index]['content'] = is_array($content) ? $content : [];
        }

        $template = Db::selectOne(
            'SELECT tv.version_label, tv.status, t.name AS template_name, t.registry
             FROM template_versions tv
             INNER JOIN templates t ON t.id = tv.template_id
             WHERE tv.id = ? LIMIT 1',
            [(string) $draft['template_version_id']],
        );
        $factSheet = Db::selectOne(
            'SELECT version, approved_at, created_at FROM fact_sheet_versions WHERE id = ? LIMIT 1',
            [(string) $draft['fact_sheet_version_id']],
        );

        $generated = 0;
        $needsInput = 0;
        foreach ($sections as $section) {
            if ((string) $section['status'] === self::SECTION_GENERATED) {
                $generated++;
            } elseif ((string) $section['status'] === self::SECTION_NEEDS_INPUT) {
                $needsInput++;
            }
        }

        return [
            'draft' => $draft,
            'project' => $project,
            'sections' => $sections,
            'template' => $template ?? [],
            'fact_sheet' => $factSheet ?? [],
            'counts' => ['generated' => $generated, 'needs_input' => $needsInput],
        ];
    }

    /**
     * Read-only state for the project page: whether the caller can generate, why
     * not (translated via a message key), and the project's latest draft.
     *
     * @param array<string, mixed> $project
     * @return array{can_edit: bool, allowed: bool, reason_key: ?string, reason_params: array<string, string|int|float>, latest_draft: ?array<string, mixed>}
     */
    public static function uiState(string $actorUserId, array $project, string $role): array
    {
        $canEdit = Tenancy::roleAtLeast($role, 'editor');
        $readiness = self::readiness($project);
        $latest = Db::selectOne(
            'SELECT id, status, version, created_at FROM drafts WHERE project_id = ? AND team_id = ? ORDER BY created_at DESC, version DESC LIMIT 1',
            [(string) $project['id'], (string) $project['team_id']],
        );

        $allowed = $canEdit && $readiness['allowed'];

        return [
            'can_edit' => $canEdit,
            'allowed' => $allowed,
            'reason_key' => $allowed ? null : ($canEdit
                ? 'error.' . (string) $readiness['error_code']
                : 'project.generate_viewer_note'),
            'reason_params' => $readiness['message_params'],
            'latest_draft' => $latest,
        ];
    }

    /**
     * The latest draft for a project (used by the project page); null when none.
     *
     * @return array<string, mixed>|null
     */
    public static function latestForProject(string $projectId, string $teamId): ?array
    {
        return Db::selectOne(
            'SELECT id, status, version, created_at FROM drafts WHERE project_id = ? AND team_id = ? ORDER BY created_at DESC, version DESC LIMIT 1',
            [$projectId, $teamId],
        );
    }

    /**
     * Why generation is or is not possible right now. Never throws: the project
     * page uses the reason to disable the button, generate() turns it into an
     * AppError with the same message.
     *
     * @param array<string, mixed> $project
     * @param array{team: array<string, mixed>, role: string}|null $scope
     * @return array{allowed: bool, error_code: ?string, status: int, message: string, message_params: array<string, string|int|float>, template_version: ?array<string, mixed>, fact_sheet: ?array<string, mixed>}
     */
    public static function readiness(array $project, ?array $scope = null): array
    {
        $registry = (string) ($project['registry'] ?? '');
        if ($registry === '') {
            return self::notReady('ai_engine_registry_missing', 422, 'Choose a registry for this project before generating a draft.');
        }

        $templateVersion = self::publishedTemplateVersion($registry, $project['template_version_id'] !== null ? (string) $project['template_version_id'] : null);
        if ($templateVersion === null) {
            return self::notReady(
                'ai_engine_no_published_template',
                422,
                self::registryName($registry) . ' has no published PDD template yet.',
                ['registry' => self::registryName($registry)],
            );
        }

        $factSheet = self::approvedFactSheet((string) $project['id']);
        if ($factSheet === null) {
            return self::notReady('ai_engine_no_approved_fact_sheet', 422, 'Approve the project fact sheet before generating a draft.');
        }

        $settings = AiEngineService::settings();
        if ((int) $settings['enabled'] !== 1) {
            return self::notReady('ai_engine_disabled', 503, 'The AI engine is switched off.');
        }
        $row = AiEngineService::publicSettings();
        if (!$row['configured']) {
            return self::notReady('ai_engine_not_configured', 503, 'The AI engine has no API key configured.');
        }

        return [
            'allowed' => true,
            'error_code' => null,
            'status' => 200,
            'message' => '',
            'message_params' => [],
            'template_version' => $templateVersion,
            'fact_sheet' => $factSheet,
        ];
    }

    /**
     * The published PDD template version for a registry: the project's recorded
     * version when it is published, otherwise the newest published one.
     *
     * @return array<string, mixed>|null
     */
    public static function publishedTemplateVersion(string $registry, ?string $preferredId = null): ?array
    {
        if ($preferredId !== null && $preferredId !== '') {
            $row = Db::selectOne(
                "SELECT tv.*, t.name AS template_name, t.registry
                 FROM template_versions tv
                 INNER JOIN templates t ON t.id = tv.template_id
                 WHERE tv.id = ? AND t.registry = ? AND t.kind = 'pdd_template' AND tv.status = 'published'
                 LIMIT 1",
                [$preferredId, $registry],
            );
            if ($row !== null) {
                return $row;
            }
        }

        return Db::selectOne(
            "SELECT tv.*, t.name AS template_name, t.registry
             FROM template_versions tv
             INNER JOIN templates t ON t.id = tv.template_id
             WHERE t.registry = ? AND t.kind = 'pdd_template' AND tv.status = 'published'
             ORDER BY COALESCE(tv.published_at, tv.effective_date) DESC, tv.created_at DESC
             LIMIT 1",
            [$registry],
        );
    }

    /** @return array<string, mixed>|null */
    public static function approvedFactSheet(string $projectId): ?array
    {
        return Db::selectOne(
            "SELECT * FROM fact_sheet_versions
             WHERE project_id = ? AND status = 'approved'
             ORDER BY version DESC LIMIT 1",
            [$projectId],
        );
    }

    /**
     * Confirmed facts (the only authorised numbers). Rejected/suggested facts are
     * never used for drafting.
     *
     * @return list<array<string, mixed>>
     */
    public static function confirmedFacts(string $projectId, string $teamId): array
    {
        $rows = Db::select(
            "SELECT id, fact_key, label, category, value_type, value_text, value_numeric, value_date, value_json, unit, status
             FROM facts
             WHERE project_id = ? AND team_id = ? AND status IN ('confirmed', 'corrected')
             ORDER BY category ASC, fact_key ASC
             LIMIT " . self::MAX_FACTS,
            [$projectId, $teamId],
        );

        return array_map(static function (array $row): array {
            $value = match ((string) $row['value_type']) {
                'number' => self::formatNumber($row['value_numeric']) . ($row['unit'] !== null && $row['unit'] !== '' ? ' ' . (string) $row['unit'] : ''),
                'date' => self::formatDate($row['value_date']),
                'boolean' => (string) ($row['value_text'] ?? $row['value_json'] ?? ''),
                'list' => (string) ($row['value_json'] ?? $row['value_text'] ?? ''),
                default => (string) ($row['value_text'] ?? ''),
            };

            return [
                'id' => (string) $row['id'],
                'key' => (string) $row['fact_key'],
                'label' => (string) $row['label'],
                'category' => (string) $row['category'],
                'value_type' => (string) $row['value_type'],
                'value' => trim($value),
                'unit' => $row['unit'] !== null ? (string) $row['unit'] : '',
            ];
        }, $rows);
    }

    /**
     * Parsed input text as context excerpts (never used for numbers without a
     * matching confirmed fact; the prompt says so).
     *
     * @return list<array{id: string, kind: string, filename: string, excerpt: string}>
     */
    public static function inputExcerpts(string $projectId, string $teamId): array
    {
        $rows = Db::select(
            "SELECT id, kind, filename, source_text
             FROM inputs
             WHERE project_id = ? AND team_id = ? AND deleted_at IS NULL
               AND source_text IS NOT NULL AND source_text <> ''
             ORDER BY created_at ASC",
            [$projectId, $teamId],
        );

        $excerpts = [];
        $remaining = self::MAX_INPUT_EXCERPT_CHARS;
        foreach ($rows as $row) {
            if ($remaining <= 0) {
                break;
            }
            $text = trim((string) $row['source_text']);
            if ($text === '') {
                continue;
            }
            $excerpt = mb_substr($text, 0, $remaining);
            $remaining -= mb_strlen($excerpt);
            $excerpts[] = [
                'id' => (string) $row['id'],
                'kind' => (string) $row['kind'],
                'filename' => (string) ($row['filename'] ?? ''),
                'excerpt' => $excerpt,
            ];
        }

        return $excerpts;
    }

    /** @return list<array<string, mixed>> template sections with their fields */
    private static function templateSections(string $templateVersionId): array
    {
        $sections = Db::select(
            'SELECT * FROM template_sections WHERE template_version_id = ? ORDER BY ordinal ASC',
            [$templateVersionId],
        );
        if ($sections === []) {
            return [];
        }

        $ids = array_map(static fn (array $section): string => (string) $section['id'], $sections);
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $fields = Db::select(
            'SELECT * FROM template_fields WHERE template_section_id IN (' . $placeholders . ') ORDER BY ordinal ASC',
            $ids,
        );
        $fieldsBySection = [];
        foreach ($fields as $field) {
            $fieldsBySection[(string) $field['template_section_id']][] = $field;
        }

        foreach ($sections as $index => $section) {
            $sections[$index]['fields'] = $fieldsBySection[(string) $section['id']] ?? [];
        }

        return $sections;
    }

    /** @param array<string, mixed> $project */
    private static function systemPrompt(array $project, array $settings): string
    {
        $registry = self::registryName((string) $project['registry']);
        $language = (string) $project['language'] === 'fr' ? 'French' : 'English';
        $global = trim(AiEngineService::globalInstructions($settings));
        $skill = trim(AiEngineService::skillForRegistry((string) $project['registry'], $settings));

        $prompt = "You are the drafting engine of an AI PDD Generator. You draft one section of a {$registry} Project Design Document (PDD) at a time.\n";
        $prompt .= "The output is always a working draft for expert review, never an approved or verified document. Write in {$language}.\n\n";

        if ($global !== '') {
            $prompt .= "GLOBAL EDITORIAL INSTRUCTIONS (admin-managed):\n{$global}\n\n";
        }
        if ($skill !== '') {
            $prompt .= "{$registry} GUIDANCE AND RULES (admin-managed skill):\n{$skill}\n\n";
        }

        // Few-shot style examples saved by staff (in-context tuning, not
        // fine-tuning). They illustrate tone/structure only: copying their facts
        // into a real project would break the citation rules, so say so.
        $examples = AiEngineTestbenchService::examplesPromptBlock((string) $project['registry']);
        if ($examples !== '') {
            $prompt .= "FEW-SHOT STYLE EXAMPLES (staff-reviewed samples for this registry; follow their tone, structure and level of detail ONLY — never copy facts, figures, names, locations or claims from them into this project):\n";
            $prompt .= $examples . "\n\n";
        }

        // Safety rules come last so they are the strongest instruction in context.
        $prompt .= "NON-NEGOTIABLE RULES:\n";
        $prompt .= "- Use ONLY the confirmed facts and input excerpts provided in the user message. Never invent or infer facts, figures, dates, names, locations, units or organisations.\n";
        $prompt .= "- Never write a number, date or coordinate that is not copied from a confirmed fact in the list. Do not calculate, round or convert numbers.\n";
        $prompt .= "- Cite the ids of the confirmed facts that support the text. If no confirmed fact supports the section, return needs_input.\n";
        $prompt .= "- If the information is missing or insufficient, say so instead of guessing: return status needs_input with a short reason.\n";
        $prompt .= "- Follow the section heading and its format rules. Dates are DD/MM/YYYY, thousands use commas and decimals use a point, figures/tables get unique ids. Sections that do not apply are stated as intentionally blank.\n";
        $prompt .= "- Return ONLY a JSON object with this shape and no markdown fences:\n";
        $prompt .= "{\"status\":\"generated\",\"content\":\"the drafted section text\",\"citations\":[\"<fact-id>\"]}\n";
        $prompt .= "or\n";
        $prompt .= "{\"status\":\"needs_input\",\"reason\":\"what is missing and which fact would resolve it\"}\n";

        return $prompt;
    }

    /**
     * @param array<string, mixed> $project
     * @param array<string, mixed> $section
     * @param list<array<string, mixed>> $facts
     * @param list<array<string, mixed>> $inputs
     */
    private static function sectionPrompt(array $project, array $section, array $facts, array $inputs): string
    {
        $prompt = "PROJECT\n";
        $prompt .= 'Name: ' . (string) $project['name'] . "\n";
        $prompt .= 'Registry: ' . self::registryName((string) $project['registry']) . "\n";
        $prompt .= 'Country: ' . ($project['country_code'] !== null && $project['country_code'] !== '' ? (string) $project['country_code'] : 'not set') . "\n";
        $prompt .= 'Project type: ' . ($project['project_type'] !== null && $project['project_type'] !== '' ? str_replace('_', ' ', (string) $project['project_type']) : 'not set') . "\n";
        $prompt .= 'Language: ' . (string) $project['language'] . "\n\n";

        $prompt .= "SECTION TO DRAFT\n";
        $prompt .= 'Stable key: ' . (string) $section['stable_key'] . "\n";
        $prompt .= 'Heading: ' . (string) $section['heading'] . "\n";
        $prompt .= 'Level: ' . (string) $section['level'] . "\n";
        $prompt .= 'Required: ' . ((int) $section['is_required'] === 1 ? 'yes' : 'no — may be intentionally blank') . "\n";
        if ((string) ($section['guidance'] ?? '') !== '') {
            $prompt .= 'Guidance: ' . (string) $section['guidance'] . "\n";
        }
        if ((string) ($section['format_rules'] ?? '') !== '') {
            $prompt .= 'Format rules: ' . (string) $section['format_rules'] . "\n";
        }
        $fields = $section['fields'] ?? [];
        if (is_array($fields) && $fields !== []) {
            $prompt .= "Template fields:\n";
            foreach ($fields as $field) {
                $prompt .= '- ' . ((int) $field['is_required'] === 1 ? '[required] ' : '[optional] ')
                    . (string) $field['label'] . ' (' . (string) $field['field_type'] . ')'
                    . ((string) ($field['guidance'] ?? '') !== '' ? ': ' . (string) $field['guidance'] : '')
                    . "\n";
            }
        }
        $prompt .= "\n";

        if ($facts === []) {
            $prompt .= "CONFIRMED FACTS\n(none — return needs_input for anything that needs facts)\n\n";
        } else {
            $prompt .= "CONFIRMED FACTS (the only authorised source of figures; cite these ids)\n";
            foreach ($facts as $fact) {
                $prompt .= '- id=' . $fact['id'] . ' key=' . $fact['key'] . ' label=' . $fact['label']
                    . ' category=' . $fact['category'] . ' value=' . $fact['value']
                    . ($fact['unit'] !== '' ? ' unit=' . $fact['unit'] : '') . "\n";
            }
            $prompt .= "\n";
        }

        if ($inputs !== []) {
            $prompt .= "INPUT EXCERPTS (context only — do not invent numbers from them; cite confirmed facts)\n";
            foreach ($inputs as $input) {
                $prompt .= '- id=' . $input['id'] . ' kind=' . $input['kind']
                    . ($input['filename'] !== '' ? ' file=' . $input['filename'] : '')
                    . ":\n" . $input['excerpt'] . "\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "TASK\nDraft the section \"{$section['heading']}\" for this project. ";
        $prompt .= "If the confirmed facts support it, return status generated with citations to the fact ids used. ";
        $prompt .= "Otherwise return status needs_input with a short reason. Return JSON only.";

        return $prompt;
    }

    /**
     * Normalise one model answer into the persisted result shape.
     *
     * @param list<array<string, mixed>> $facts
     * @param string $allowedNumbers text containing every number the model may use
     * @return array{status: string, content: string, citations: list<array<string, string>>, reason_code: string, note: string}
     */
    private static function normalizeSectionResult(string $raw, array $facts, string $allowedNumbers): array
    {
        $decoded = self::decodeJsonObject($raw);
        if ($decoded === null) {
            return self::needsInput('invalid_json', '');
        }

        $status = is_string($decoded['status'] ?? null) ? strtolower((string) $decoded['status']) : '';
        $content = is_string($decoded['content'] ?? null) ? trim((string) $decoded['content']) : '';
        $modelReason = is_string($decoded['reason'] ?? null) ? trim((string) $decoded['reason']) : '';

        $factsById = [];
        foreach ($facts as $fact) {
            $factsById[(string) $fact['id']] = $fact;
        }

        $citations = [];
        $rawCitations = $decoded['citations'] ?? [];
        if (is_array($rawCitations)) {
            foreach ($rawCitations as $citation) {
                $id = null;
                if (is_string($citation)) {
                    $id = trim($citation);
                } elseif (is_array($citation)) {
                    $id = $citation['factId'] ?? $citation['fact_id'] ?? $citation['id'] ?? null;
                    $id = is_string($id) ? trim($id) : null;
                }
                if ($id !== null && isset($factsById[$id])) {
                    $citations[$id] = [
                        'factId' => $id,
                        'factKey' => (string) $factsById[$id]['key'],
                        'label' => (string) $factsById[$id]['label'],
                    ];
                }
            }
        }
        $citations = array_values($citations);

        if ($status !== self::SECTION_GENERATED) {
            return self::needsInput('model_needs_input', self::safeNote($modelReason));
        }
        if ($content === '') {
            return self::needsInput('empty_content', '');
        }
        if ($citations === []) {
            // A generated claim without a citation breaks the citation hard rule.
            return self::needsInput('missing_citation', '');
        }
        if (!self::numbersSupported($content, $allowedNumbers)) {
            // A number the confirmed facts cannot back is treated as invented.
            return self::needsInput('unsupported_number', '');
        }

        return [
            'status' => self::SECTION_GENERATED,
            'content' => $content,
            'citations' => $citations,
            'reason_code' => '',
            'note' => '',
        ];
    }

    /** @return array{status: string, content: string, citations: list<array<string,string>>, reason_code: string, note: string} */
    private static function needsInput(string $reasonCode, string $note): array
    {
        return [
            'status' => self::SECTION_NEEDS_INPUT,
            'content' => '',
            'citations' => [],
            'reason_code' => $reasonCode,
            'note' => self::safeNote($note),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function decodeJsonObject(string $raw): ?array
    {
        $text = trim($raw);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Numbers in the generated text must exist in the facts/inputs/template text.
     * This is the enforcement of "the model never creates numbers".
     */
    private static function numbersSupported(string $content, string $allowedNumbers): bool
    {
        // Ignore list markers ("1." / "2)") so ordinary prose enumerations are not
        // mistaken for invented figures.
        $checked = preg_replace('/^[ \t]*\d+[.)][ \t]+/m', '', $content) ?? $content;
        $allowed = [];
        foreach (self::numberTokens($allowedNumbers) as $token) {
            $allowed[$token] = true;
        }
        foreach (self::numberTokens($checked) as $token) {
            if (!isset($allowed[$token])) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> canonical numeric tokens (commas removed, no trailing zeros) */
    private static function numberTokens(string $text): array
    {
        preg_match_all('/\d[\d,]*(?:\.\d+)?/', $text, $matches);
        $tokens = [];
        foreach ($matches[0] as $raw) {
            $clean = str_replace(',', '', $raw);
            if (str_contains($clean, '.')) {
                $clean = rtrim(rtrim($clean, '0'), '.');
            }
            if ($clean !== '') {
                $tokens[] = $clean;
            }
        }

        return $tokens;
    }

    /**
     * The corpus of evidence a section may draw numbers from.
     *
     * @param array<string, mixed> $project
     * @param array<string, mixed> $section
     * @param list<array<string, mixed>> $facts
     * @param list<array<string, mixed>> $inputs
     */
    private static function allowedNumberCorpus(array $project, array $section, array $facts, array $inputs): string
    {
        $parts = [
            (string) $project['name'],
            (string) ($project['country_code'] ?? ''),
            (string) ($project['project_type'] ?? ''),
            (string) $section['stable_key'],
            (string) $section['heading'],
            (string) ($section['guidance'] ?? ''),
            (string) ($section['format_rules'] ?? ''),
        ];
        foreach ($section['fields'] ?? [] as $field) {
            $parts[] = (string) $field['label'];
            $parts[] = (string) ($field['guidance'] ?? '');
            $parts[] = (string) ($field['rules'] ?? '');
        }
        foreach ($facts as $fact) {
            $parts[] = (string) $fact['value'];
            $parts[] = (string) $fact['key'];
            $parts[] = (string) $fact['label'];
            $parts[] = (string) $fact['unit'];
        }
        foreach ($inputs as $input) {
            $parts[] = (string) $input['excerpt'];
        }

        return implode("\n", $parts);
    }

    /** @param array<string, string|int|float> $params */
    private static function notReady(string $errorCode, int $status, string $message, array $params = []): array
    {
        return [
            'allowed' => false,
            'error_code' => $errorCode,
            'status' => $status,
            'message' => $message,
            'message_params' => $params,
            'template_version' => null,
            'fact_sheet' => null,
        ];
    }

    private static function registryName(string $registry): string
    {
        return match ($registry) {
            'gold_standard' => 'Gold Standard',
            'verra_vcs' => 'Verra VCS',
            default => 'Registry',
        };
    }

    private static function formatNumber(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $raw = (string) $value;
        // DECIMAL(18,4) arrives with four decimals: drop only trailing decimals.
        if (str_contains($raw, '.')) {
            $raw = rtrim(rtrim($raw, '0'), '.');
        }

        return $raw === '' || $raw === '-' ? (string) $value : $raw;
    }

    private static function formatDate(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }
        $timestamp = strtotime($value);

        return $timestamp === false ? $value : date('d/m/Y', $timestamp);
    }

    private static function safeNote(string $note): string
    {
        return mb_substr(trim($note), 0, 300);
    }
}
