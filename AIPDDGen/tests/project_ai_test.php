<?php

declare(strict_types=1);

use App\AppError;
use App\Config;
use App\Db;
use App\Repositories\UserRepository;
use App\Services\AiEngineService;
use App\Services\DraftService;
use App\Services\ProjectAiService;
use App\Services\ProjectService;
use App\Storage;
use App\Uuid;
use App\View;

/**
 * Workspace-side AI drafting tunnel: readiness, input submission (text + .txt/.md
 * with plaintext context), the honest blocker from DraftService::readiness(), the
 * real drafting pipeline progress and the rendered project page.
 *
 * Offline only: the LLM transport is never called (generation is only asserted for
 * its refused paths here; the mocked happy path lives in tests/ai_engine_test.php).
 */

function pai_config_secret(): void
{
    putenv('AI_ENGINE_SECRET=pai-project-ai-test-secret-77ad1c');
    putenv('AI_ENGINE_API_KEY');
    Config::load(require APP_ROOT . '/config/config.php');
}

/** A platform-admin account (needed to configure the shared engine). */
function pai_staff(string $email = 'pai-staff@example.com'): array
{
    $account = make_user($email, 'Project AI Staff');
    Db::execute('UPDATE users SET is_platform_admin = 1 WHERE id = ?', [(string) $account['user']['id']]);
    $account['user'] = UserRepository::findById((string) $account['user']['id']);
    if ($account['user'] === null) {
        throw new RuntimeException('Failed to load staff user');
    }

    return $account;
}

/** Save a working engine (encrypted key, enabled) with registry fallback skills. */
function pai_configure_engine(array $staff, bool $withSkill = true): array
{
    pai_config_secret();

    return AiEngineService::save((string) $staff['user']['id'], [
        'provider' => 'openai',
        'base_url' => 'https://api.openai.com/v1',
        'model' => 'gpt-4o-mini',
        'temperature' => '0.2',
        'max_tokens' => '600',
        'api_key' => 'sk-test-secret-1234',
        'enabled' => '1',
        'skill_gold_standard' => $withSkill ? 'Follow the Gold Standard Template Guide v1.4 headings exactly.' : '',
        'skill_verra_vcs' => '',
        'global_instructions' => '',
    ]);
}

/**
 * Seed a published PDD template + one section.
 *
 * @return array{template_id: string, version_id: string, section_id: string}
 */
function pai_template(string $registry = 'gold_standard', string $status = 'published'): array
{
    $templateId = Uuid::v4();
    $versionId = Uuid::v4();
    Db::insert('templates', [
        'id' => $templateId,
        'name' => 'PAI ' . $registry . ' PDD template',
        'kind' => 'pdd_template',
        'registry' => $registry,
        'language' => 'en',
    ]);
    Db::insert('template_versions', [
        'id' => $versionId,
        'template_id' => $templateId,
        'version_label' => 'v1.0',
        'language' => 'en',
        'status' => $status,
    ]);
    $sectionId = Uuid::v4();
    Db::insert('template_sections', [
        'id' => $sectionId,
        'template_version_id' => $versionId,
        'ordinal' => 1,
        'stable_key' => 'A.1',
        'heading' => 'Purpose and general description',
        'level' => 2,
    ]);

    return ['template_id' => $templateId, 'version_id' => $versionId, 'section_id' => $sectionId];
}

/** Give a template version its admin-managed SKILL.md row. */
function pai_skill(string $versionId, string $templateId, string $registry, string $status = 'ready'): void
{
    Db::insert('ai_engine_skills', [
        'id' => Uuid::v4(),
        'template_id' => $templateId,
        'template_version_id' => $versionId,
        'registry' => $registry,
        'name' => 'PAI skill',
        'skill_text' => $status === 'ready' ? 'Follow the template exactly.' : '',
        'status' => $status,
        'enabled' => 1,
    ]);
}

/** A workspace project configured for a registry (optionally with a template). */
function pai_project(array $owner, ?string $templateVersionId = null, string $registry = 'gold_standard'): array
{
    $project = ProjectService::create(
        (string) $owner['user']['id'],
        (string) $owner['team']['id'],
        'PAI project',
    );
    Db::execute(
        'UPDATE projects SET registry = ?, template_version_id = ?, country_code = ?, project_type = ? WHERE id = ?',
        [$registry, $templateVersionId, 'KE', 'cookstoves', (string) $project['id']],
    );

    return Db::selectOne('SELECT * FROM projects WHERE id = ?', [(string) $project['id']]) ?? $project;
}

/** Insert confirmed facts + an approved fact-sheet version for a project. */
function pai_fact_sheet(string $projectId, string $teamId, string $userId): string
{
    $factId = Uuid::v4();
    Db::insert('facts', [
        'id' => $factId,
        'project_id' => $projectId,
        'team_id' => $teamId,
        'category' => 'project',
        'fact_key' => 'project_name',
        'label' => 'Project name',
        'value_type' => 'string',
        'value_text' => 'PAI cookstoves project',
        'status' => 'confirmed',
        'confirmed_by_user_id' => $userId,
        'confirmed_at' => gmdate('Y-m-d H:i:s'),
    ]);

    $factSheetId = Uuid::v4();
    Db::insert('fact_sheet_versions', [
        'id' => $factSheetId,
        'project_id' => $projectId,
        'version' => 1,
        'status' => 'approved',
        'snapshot' => json_encode(['fact_ids' => [$factId]]),
        'created_by_user_id' => $userId,
        'approved_by_user_id' => $userId,
        'approved_at' => gmdate('Y-m-d H:i:s'),
    ]);

    return $factSheetId;
}

/** Insert a real draft row for the project. */
function pai_draft(string $projectId, string $teamId, string $templateVersionId, string $factSheetId, int $version = 1): string
{
    $draftId = Uuid::v4();
    Db::insert('drafts', [
        'id' => $draftId,
        'project_id' => $projectId,
        'team_id' => $teamId,
        'template_version_id' => $templateVersionId,
        'fact_sheet_version_id' => $factSheetId,
        'language' => 'en',
        'status' => 'draft',
        'version' => $version,
        'is_labeled_draft' => 'draft',
    ]);

    return $draftId;
}

/** Store a real local file privately (test twin of an uploaded attachment). */
function pai_store_file(string $extension = 'md', string $content = "# Evidence\nProject evidence text.\n"): array
{
    $directory = APP_ROOT . '/storage/tests';
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
    $path = $directory . '/' . Uuid::v4() . '.' . $extension;
    file_put_contents($path, $content);

    return Storage::putLocalFile($path, 'evidence.' . $extension, 'inputs', ProjectAiService::ATTACHMENT_EXTENSIONS);
}

function pai_temp_file(string $extension, string $content): string
{
    $directory = APP_ROOT . '/storage/tests';
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
    $path = $directory . '/' . Uuid::v4() . '.' . $extension;
    file_put_contents($path, $content);

    return $path;
}

return [
    'readiness reports each unmet condition and the real authoritative blocker' => static function (): void {
        // Pin the config: a leaked env fallback key from another test file must not
        // make the engine look configured before this test saves its own key.
        pai_config_secret();
        $owner = make_user('pai-readiness@example.com');
        $project = pai_project($owner);

        $ai = ProjectAiService::overview((string) $owner['user']['id'], $project, 'owner');
        assert_true($ai['can_edit'], 'the owner is an editor');
        assert_false($ai['allowed'], 'a bare project cannot draft');
        assert_same('error.ai_engine_no_published_template', $ai['reason_key'], 'the first blocker is the missing published template');
        assert_false($ai['conditions']['engine']['ok'], 'engine is not configured on a fresh test DB');
        assert_false($ai['conditions']['template']['ok'], 'there is no published template');
        assert_false($ai['conditions']['fact_sheet']['ok'], 'there is no approved fact sheet');
        assert_same(0, $ai['pipeline']['done'], 'fresh pipeline has no completed steps');
        assert_same(5, $ai['pipeline']['total']);
        assert_same('inputs', $ai['pipeline']['current'], 'inputs is the current step');
        assert_same([], $ai['drafts']);
        assert_same(0, $ai['inputs']['count']);

        // A real draft needs an approved fact sheet and a ready SKILL.md too.
        $staff = pai_staff('pai-readiness-staff@example.com');
        pai_configure_engine($staff);
        $template = pai_template();
        pai_skill($template['version_id'], $template['template_id'], 'gold_standard', 'ready');
        Db::execute('UPDATE projects SET template_version_id = ? WHERE id = ?', [$template['version_id'], (string) $project['id']]);
        pai_fact_sheet((string) $project['id'], (string) $owner['team']['id'], (string) $owner['user']['id']);

        $ready = ProjectAiService::overview((string) $owner['user']['id'], $project, 'owner');
        assert_true($ready['allowed'], 'engine + published template + ready skill + approved fact sheet allow drafting');
        assert_true($ready['conditions']['engine']['ok']);
        assert_true($ready['conditions']['template']['ok']);
        assert_true($ready['conditions']['fact_sheet']['ok']);
        assert_same(1, $ready['pipeline']['done'], 'only the fact-sheet step is done before any input/draft');
        assert_same('inputs', $ready['pipeline']['current']);

        // Once input + draft exist, three of five steps are done.
        ProjectAiService::addInput((string) $owner['user']['id'], (string) $project['id'], 'Survey notes: 220 cookstoves distributed.');
        pai_draft(
            (string) $project['id'],
            (string) $owner['team']['id'],
            $template['version_id'],
            (string) Db::selectOne('SELECT id FROM fact_sheet_versions WHERE project_id = ?', [(string) $project['id']])['id'],
        );

        $withDraft = ProjectAiService::overview((string) $owner['user']['id'], $project, 'owner');
        assert_same(3, $withDraft['pipeline']['done'], 'inputs + facts + draft are three real completed steps');
        assert_same('check', $withDraft['pipeline']['current'], 'the gap report is the next step');
        $future = array_values(array_filter($withDraft['pipeline']['steps'], static fn (array $step): bool => (bool) $step['future']));
        assert_same(2, count($future), 'gap report and export are the two future steps');
        foreach ($future as $step) {
            assert_false($step['done'], $step['key'] . ' must never be reported done');
        }
    },

    'editor submits text input and it becomes drafting context' => static function (): void {
        $owner = make_user('pai-text@example.com');
        $userId = (string) $owner['user']['id'];
        $teamId = (string) $owner['team']['id'];
        $project = pai_project($owner);

        $result = ProjectAiService::addInput($userId, (string) $project['id'], 'Household survey: 220 cookstoves in Kisumu.');
        assert_same(1, count($result['input_ids']));
        assert_false($result['has_attachment']);

        $row = Db::selectOne('SELECT * FROM inputs WHERE id = ?', [$result['input_ids'][0]]);
        assert_not_null($row);
        assert_same('text', (string) $row['kind']);
        assert_same('uploaded', (string) $row['status']);
        assert_same($userId, (string) $row['uploaded_by_user_id']);
        assert_same($teamId, (string) $row['team_id'], 'the input is scoped to the project workspace');
        assert_contains('220 cookstoves', (string) $row['source_text']);
        assert_same(1, count_rows('audit_logs', "action = 'project.input_added'"), 'one audit entry per submission');

        $excerpts = DraftService::inputExcerpts((string) $project['id'], $teamId);
        assert_same(1, count($excerpts));
        assert_contains('220 cookstoves', $excerpts[0]['excerpt'], 'submitted text reaches the drafting prompt context');

        $ai = ProjectAiService::overview($userId, $project, 'owner');
        assert_same(1, $ai['inputs']['count']);
        assert_true($ai['pipeline']['steps'][0]['done'], 'the inputs pipeline step is done');
    },

    'attachment plaintext is read into source_text and stored privately' => static function (): void {
        $owner = make_user('pai-file@example.com');
        $userId = (string) $owner['user']['id'];
        $project = pai_project($owner);

        // BOM + CRLF + French accents: plaintext is read UTF-8 safely, not parsed.
        $stored = pai_store_file('md', "\xEF\xBB\xBF# Preuves\r\n220 foyers équipés.\r\n");
        try {
            $result = ProjectAiService::addStoredAttachmentInput($userId, (string) $project['id'], '', $stored, "# Preuves\n220 foyers équipés.");
            assert_true($result['has_attachment']);

            $row = Db::selectOne('SELECT * FROM inputs WHERE id = ?', [$result['input_ids'][0]]);
            assert_not_null($row);
            assert_same('document', (string) $row['kind']);
            assert_same('evidence.md', (string) $row['filename']);
            assert_same((string) $stored['key'], (string) $row['storage_key'], 'the file is stored privately under the uploads directory');
            assert_same((string) $stored['checksum_sha256'], (string) $row['checksum_sha256']);
            assert_contains('220 foyers équipés', (string) $row['source_text'], 'attachment plaintext feeds the draft context');
            assert_same(1, count_rows('audit_logs', "action = 'project.input_added'"));

            // Both text and attachment in one submission ⇒ two rows, one audit entry.
            $second = pai_store_file('txt', "Second evidence line.\n");
            try {
                $both = ProjectAiService::addStoredAttachmentInput(
                    $userId,
                    (string) $project['id'],
                    'Textarea evidence.',
                    $second,
                    'Second evidence line.',
                );
                assert_same(2, count($both['input_ids']), 'text + attachment create two rows');
                assert_same(2, count_rows('audit_logs', "action = 'project.input_added'"), 'still one audit entry per submission');
            } finally {
                Storage::delete((string) $second['key']);
            }
        } finally {
            Storage::delete((string) $stored['key']);
        }

        // BOM stripping, UTF-8 validation and the plaintext cap are enforced.
        $bomPath = pai_temp_file('md', "\xEF\xBB\xBFBOM evidence");
        try {
            assert_same('BOM evidence', ProjectAiService::readAttachmentText(['tmp_name' => $bomPath]));
        } finally {
            @unlink($bomPath);
        }
        $binaryPath = pai_temp_file('txt', "\xC3\x28 invalid utf-8");
        try {
            assert_app_error('validation_error', static function () use ($binaryPath): void {
                ProjectAiService::readAttachmentText(['tmp_name' => $binaryPath]);
            }, 'invalid UTF-8 attachments are refused');
        } finally {
            @unlink($binaryPath);
        }
        $longPath = pai_temp_file('txt', str_repeat('a', ProjectAiService::MAX_INPUT_CHARS + 50));
        try {
            assert_same(
                ProjectAiService::MAX_INPUT_CHARS,
                mb_strlen(ProjectAiService::readAttachmentText(['tmp_name' => $longPath])),
                'attachment plaintext is capped to the input limit',
            );
        } finally {
            @unlink($longPath);
        }
    },

    'input submission validates empty and oversized text' => static function (): void {
        $owner = make_user('pai-validate@example.com');
        $userId = (string) $owner['user']['id'];
        $project = pai_project($owner);

        assert_app_error('validation_error', static function () use ($userId, $project): void {
            ProjectAiService::addInput($userId, (string) $project['id'], '   ');
        }, 'empty text without an attachment is refused');
        assert_app_error('validation_error', static function () use ($userId, $project): void {
            ProjectAiService::addInput($userId, (string) $project['id'], str_repeat('x', ProjectAiService::MAX_INPUT_CHARS + 1));
        }, 'oversized text is refused');
        assert_same(0, count_rows('inputs'), 'no row is written on validation failure');
    },

    'input submission enforces editor role and tenant isolation' => static function (): void {
        $owner = make_user('pai-roles@example.com');
        $ownerId = (string) $owner['user']['id'];
        $teamId = (string) $owner['team']['id'];
        $project = pai_project($owner);

        $viewer = add_team_member($teamId, $ownerId, 'pai-viewer@example.com', 'viewer');
        $outsider = make_user('pai-outsider@example.com');

        assert_app_error('forbidden', static function () use ($viewer, $project): void {
            ProjectAiService::addInput((string) $viewer['user']['id'], (string) $project['id'], 'Viewer evidence');
        }, 'a viewer cannot submit project input');
        assert_app_error('not_found', static function () use ($outsider, $project): void {
            ProjectAiService::addInput((string) $outsider['user']['id'], (string) $project['id'], 'Foreign evidence');
        }, 'another workspace cannot submit into this project');
        assert_app_error('not_found', static function () use ($outsider, $project): void {
            ProjectAiService::overview((string) $outsider['user']['id'], $project, 'owner');
        }, 'another workspace cannot read this project AI state');
        assert_same(0, count_rows('inputs'));
    },

    'generation is refused with the real reason when the project is not ready' => static function (): void {
        $owner = make_user('pai-generate@example.com');
        $ownerId = (string) $owner['user']['id'];
        $teamId = (string) $owner['team']['id'];

        // No published template yet.
        $noTemplate = pai_project($owner);
        assert_app_error('ai_engine_no_published_template', static function () use ($ownerId, $noTemplate): void {
            DraftService::generate($ownerId, (string) $noTemplate['id']);
        }, 'the existing generate path refuses without a published template');

        // A published template whose SKILL.md is still pending is blocked too.
        $staff = pai_staff('pai-generate-staff@example.com');
        pai_configure_engine($staff);
        $template = pai_template();
        pai_skill($template['version_id'], $template['template_id'], 'gold_standard', 'pending');
        $pending = pai_project($owner, $template['version_id']);
        pai_fact_sheet((string) $pending['id'], $teamId, $ownerId);
        assert_app_error('ai_engine_skill_pending', static function () use ($ownerId, $pending): void {
            DraftService::generate($ownerId, (string) $pending['id']);
        }, 'the pinned skill-pending reason blocks generation');

        $ai = ProjectAiService::overview($ownerId, $pending, 'owner');
        assert_false($ai['allowed']);
        assert_same('error.ai_engine_skill_pending', $ai['reason_key'], 'the card surfaces the skill-pending reason');
        assert_false($ai['conditions']['skill']['ok']);
        assert_true(array_key_exists('template', $ai['reason_params']), 'the reason names the template');
    },

    'project page renders the AI card, inputs, drafts and real pipeline progress' => static function (): void {
        $owner = make_user('pai-page@example.com');
        $userId = (string) $owner['user']['id'];
        $teamId = (string) $owner['team']['id'];

        $staff = pai_staff('pai-page-staff@example.com');
        pai_configure_engine($staff);
        $template = pai_template();
        pai_skill($template['version_id'], $template['template_id'], 'gold_standard', 'ready');
        $project = pai_project($owner, $template['version_id']);
        $factSheetId = pai_fact_sheet((string) $project['id'], $teamId, $userId);

        $stored = pai_store_file();
        try {
            ProjectAiService::addStoredAttachmentInput(
                $userId,
                (string) $project['id'],
                'Baseline survey evidence.',
                $stored,
                'Baseline survey evidence.',
            );
            pai_draft((string) $project['id'], $teamId, $template['version_id'], $factSheetId);

            $markup = View::capture('pages/project', [
                'user' => $owner['user'],
                'project' => $project,
                'team' => $owner['team'],
                'role' => 'owner',
                'canEdit' => true,
                'teams' => [],
            ]);

            assert_contains(t('project.ai_title'), $markup, 'the AI drafting card renders');
            assert_contains(t('project.ai_condition_engine'), $markup);
            assert_contains(t('project.ai_condition_template'), $markup);
            assert_contains(t('project.ai_condition_fact_sheet'), $markup);
            assert_contains(t('project.ai_condition_ok'), $markup);
            assert_contains(t('project.ai_ready'), $markup, 'the ready state is shown honestly');
            assert_contains('/projects/' . (string) $project['id'] . '/draft/generate', $markup, 'the CTA reuses the existing generate endpoint');
            assert_contains('/projects/' . (string) $project['id'] . '/inputs', $markup, 'the input tunnel posts to the new route');
            assert_contains('enctype="multipart/form-data"', $markup, 'the input form accepts attachments');
            assert_contains('name="text"', $markup);
            assert_contains('name="attachment"', $markup);
            assert_contains('name="_csrf"', $markup);
            assert_contains(t('project.ai_inputs_latest'), $markup);
            assert_contains(t('project.ai_drafts_title'), $markup);
            assert_contains('/drafts/', $markup, 'the latest drafts link to the draft page');
            assert_contains(t('common.draft_badge'), $markup, 'drafts are labelled as drafts');

            // Real pipeline: 3 of 5 complete, current step = gap report.
            assert_contains('<progress', $markup, 'the pipeline exposes a progress element');
            assert_same(1, preg_match('/<progress[^>]*max="5"[^>]*value="3"/', $markup), 'progress reflects three real completed steps');
            assert_contains(t('project.ai_pipeline_progress', ['done' => '3', 'total' => '5']), $markup);
            assert_same(5, preg_match_all('/<li class="pipeline-step(?: [^"]*)?"/', $markup), 'five pipeline steps render');
            assert_same(3, preg_match_all('/class="pipeline-step is-done"/', $markup), 'three steps are marked done');
            assert_same(1, preg_match_all('/aria-current="step"/', $markup), 'exactly one current step is highlighted');
            assert_contains(t('project.ai_pipeline_current'), $markup);
            assert_same(2, preg_match_all('/' . preg_quote(t('project.pipeline_coming'), '/') . '/', $markup), 'gap report and export are honestly marked later');

            // No provider key or private storage key ever reaches the HTML.
            assert_false(str_contains($markup, 'sk-test-secret-1234'), 'the provider key must never render');
            assert_false(str_contains($markup, (string) $stored['key']), 'the private storage key must never render');

            // A viewer sees the same state but no submission/generation controls.
            $viewer = add_team_member($teamId, $userId, 'pai-page-viewer@example.com', 'viewer');
            $viewerMarkup = View::capture('pages/project', [
                'user' => $viewer['user'],
                'project' => $project,
                'team' => $owner['team'],
                'role' => 'viewer',
                'canEdit' => false,
                'teams' => [],
            ]);
            assert_contains(t('project.ai_title'), $viewerMarkup, 'viewers still see the readiness card');
            assert_contains(t('project.generate_viewer_note'), $viewerMarkup);
            assert_false(str_contains($viewerMarkup, 'name="attachment"'), 'viewers get no input form');
        } finally {
            Storage::delete((string) $stored['key']);
        }
    },

    'project page shows the honest blocker and a disabled CTA when not ready' => static function (): void {
        $owner = make_user('pai-blocked@example.com');
        $project = pai_project($owner);

        $markup = View::capture('pages/project', [
            'user' => $owner['user'],
            'project' => $project,
            'team' => $owner['team'],
            'role' => 'owner',
            'canEdit' => true,
            'teams' => [],
        ]);

        assert_contains(t('project.ai_blocked_title'), $markup);
        assert_contains(t('error.ai_engine_no_published_template', ['registry' => t('landing.registry_gs')]), $markup, 'the real blocker reason is translated');
        assert_contains('disabled aria-describedby="project-ai-blocked"', $markup, 'the generate CTA is disabled while blocked');
        assert_contains('<progress', $markup);
        assert_same(1, preg_match('/<progress[^>]*max="5"[^>]*value="0"/', $markup), 'a fresh project shows 0 of 5 complete');
    },
];
