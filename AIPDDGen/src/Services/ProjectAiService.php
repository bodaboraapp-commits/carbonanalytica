<?php

declare(strict_types=1);

namespace App\Services;

use App\AppError;
use App\Audit;
use App\Db;
use App\Storage;
use App\Tenancy;
use App\Uuid;

/**
 * Workspace side of AI drafting: the "submit input -> admin-configured engine
 * -> PDD draft" tunnel plus the real state of the project's drafting pipeline.
 *
 * Read-only where possible (DraftService / AiEngineService public APIs only) and
 * tenant-scoped everywhere: every query filters on the project's team_id after a
 * Tenancy gate, so another workspace's project is a 404 and a viewer is a 403.
 */
final class ProjectAiService
{
    /** Max characters submitted as input text (textarea or .txt/.md attachment). */
    public const MAX_INPUT_CHARS = 20000;

    /** Characters shown for one input excerpt on the project page. */
    public const INPUT_EXCERPT_CHARS = 160;

    /** Input rows listed on the project page. */
    public const LATEST_INPUTS = 5;

    /** Draft rows listed on the project page. */
    public const LATEST_DRAFTS = 3;

    /** Plain-text attachments accepted for the input tunnel. */
    public const ATTACHMENT_EXTENSIONS = ['txt', 'md'];

    /** The pipeline has five steps; gap report/export are later roadmap steps. */
    public const PIPELINE_TOTAL = 5;

    /**
     * Everything the project page needs: readiness conditions, the authoritative
     * blocker (DraftService::readiness, which also covers the per-template SKILL.md
     * requirement), submitted inputs, latest drafts and the real pipeline state.
     *
     * @param array<string, mixed> $project
     * @return array{
     *   can_edit: bool,
     *   allowed: bool,
     *   reason_key: ?string,
     *   reason_params: array<string, string|int|float>,
     *   conditions: array<string, array<string, mixed>>,
     *   latest_draft: ?array<string, mixed>,
     *   inputs: array{count: int, rows: list<array<string, mixed>>},
     *   drafts: list<array<string, mixed>>,
     *   pipeline: array{steps: list<array<string, mixed>>, done: int, total: int, current: ?string}
     * }
     */
    public static function overview(string $actorUserId, array $project, string $role): array
    {
        // Tenant gate first: another workspace's project is a 404.
        $scope = Tenancy::projectForUser($actorUserId, (string) $project['id'], 'viewer');
        $project = $scope['project'];
        $projectId = (string) $project['id'];
        $teamId = (string) $project['team_id'];

        $readiness = DraftService::readiness($project);
        $factSheet = DraftService::approvedFactSheet($projectId);
        $registry = (string) ($project['registry'] ?? '');
        $template = $registry !== ''
            ? DraftService::publishedTemplateVersion(
                $registry,
                $project['template_version_id'] !== null ? (string) $project['template_version_id'] : null,
            )
            : null;

        $engineSettings = AiEngineService::settings();
        $enginePublic = AiEngineService::publicSettings();

        $inputs = self::inputs($projectId, $teamId);
        $latestDraft = DraftService::latestForProject($projectId, $teamId);
        $drafts = self::drafts($projectId, $teamId);
        $canEdit = Tenancy::roleAtLeast($role, 'editor');

        return [
            'can_edit' => $canEdit,
            'allowed' => $readiness['allowed'],
            'reason_key' => $readiness['allowed'] ? null : 'error.' . (string) $readiness['error_code'],
            'reason_params' => $readiness['message_params'],
            'conditions' => [
                'engine' => [
                    'ok' => (int) $engineSettings['enabled'] === 1 && (bool) $enginePublic['configured'],
                ],
                'template' => [
                    'ok' => $template !== null,
                    'label' => $template !== null
                        ? trim((string) $template['template_name'] . ' ' . (string) $template['version_label'])
                        : null,
                ],
                'fact_sheet' => [
                    'ok' => $factSheet !== null,
                    'version' => $factSheet !== null ? (int) $factSheet['version'] : null,
                ],
                'skill' => [
                    'ok' => ($readiness['skill']['status'] ?? '') === AiEngineService::SKILL_STATUS_READY,
                    'status' => $readiness['skill']['status'] ?? null,
                ],
            ],
            'latest_draft' => $latestDraft,
            'inputs' => $inputs,
            'drafts' => $drafts,
            'pipeline' => self::pipeline($inputs['count'], $factSheet, $latestDraft),
        ];
    }

    /**
     * Add project input evidence as an editor+ member of the workspace.
     *
     * At least one of the textarea text or a .txt/.md attachment is required.
     * The attachment is stored privately AND its plaintext is read into the row's
     * source_text so DraftService::inputExcerpts() can feed it to the admin-configured
     * engine. One audit entry per submission; storage keys never leave the service.
     *
     * @param array<string, mixed> $file a $_FILES entry
     * @return array{input_ids: list<string>, has_attachment: bool, text_chars: int}
     */
    public static function addInput(string $actorUserId, string $projectId, string $text, array $file = []): array
    {
        $scope = Tenancy::projectForUser($actorUserId, $projectId, 'editor');
        $project = $scope['project'];

        $text = trim($text);
        if (mb_strlen($text) > self::MAX_INPUT_CHARS) {
            throw AppError::validation(['text' => 'too_long']);
        }

        $hasFile = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($text === '' && !$hasFile) {
            throw AppError::validation(['text' => 'required']);
        }

        // Read the plain-text attachment BEFORE storing it so an invalid encoding
        // never leaves a stored file behind.
        $fileText = '';
        if ($hasFile) {
            $fileText = self::readAttachmentText($file);
        }

        $stored = null;
        if ($hasFile) {
            $stored = Storage::putUploadedFile($file, 'inputs', self::ATTACHMENT_EXTENSIONS);
        }

        return self::persistInputs($actorUserId, $project, $text, $stored, $fileText);
    }

    /**
     * Same tunnel, but for an already-stored private file (imports/seeds/tests):
     * the caller supplies the Storage metadata and the plaintext already read.
     *
     * @param array{key: string, filename: string, mime_type: string, size_bytes: int, checksum_sha256: string} $stored
     * @return array{input_ids: list<string>, has_attachment: bool, text_chars: int}
     */
    public static function addStoredAttachmentInput(
        string $actorUserId,
        string $projectId,
        string $text,
        array $stored,
        string $fileText = '',
    ): array {
        $scope = Tenancy::projectForUser($actorUserId, $projectId, 'editor');
        $project = $scope['project'];

        $text = trim($text);
        if (mb_strlen($text) > self::MAX_INPUT_CHARS) {
            throw AppError::validation(['text' => 'too_long']);
        }
        if ($text === '' && (string) ($stored['key'] ?? '') === '') {
            throw AppError::validation(['text' => 'required']);
        }

        return self::persistInputs($actorUserId, $project, $text, $stored, mb_substr(trim($fileText), 0, self::MAX_INPUT_CHARS));
    }

    /**
     * Insert the input rows + the single audit entry for one submission.
     *
     * @param array<string, mixed> $project
     * @param array{key: string, filename: string, mime_type: string, size_bytes: int, checksum_sha256: string}|null $stored
     * @return array{input_ids: list<string>, has_attachment: bool, text_chars: int}
     */
    private static function persistInputs(string $actorUserId, array $project, string $text, ?array $stored, string $fileText): array
    {
        $inputIds = [];
        try {
            Db::transaction(static function () use ($actorUserId, $project, $text, $stored, $fileText, &$inputIds): void {
                if ($text !== '') {
                    $textId = Uuid::v4();
                    Db::insert('inputs', [
                        'id' => $textId,
                        'project_id' => (string) $project['id'],
                        'team_id' => (string) $project['team_id'],
                        'kind' => 'text',
                        'status' => 'uploaded',
                        'language' => (string) ($project['language'] ?? 'en'),
                        'source_text' => $text,
                        'uploaded_by_user_id' => $actorUserId,
                    ]);
                    $inputIds[] = $textId;
                }

                if ($stored !== null) {
                    $documentId = Uuid::v4();
                    Db::insert('inputs', [
                        'id' => $documentId,
                        'project_id' => (string) $project['id'],
                        'team_id' => (string) $project['team_id'],
                        'kind' => 'document',
                        'filename' => (string) $stored['filename'],
                        'mime_type' => (string) $stored['mime_type'],
                        'size_bytes' => (int) $stored['size_bytes'],
                        'storage_key' => (string) $stored['key'],
                        'checksum_sha256' => (string) $stored['checksum_sha256'],
                        'status' => 'uploaded',
                        'language' => (string) ($project['language'] ?? 'en'),
                        'source_text' => $fileText !== '' ? $fileText : null,
                        'uploaded_by_user_id' => $actorUserId,
                    ]);
                    $inputIds[] = $documentId;
                }

                Audit::record([
                    'team_id' => (string) $project['team_id'],
                    'actor_user_id' => $actorUserId,
                    'action' => 'project.input_added',
                    'target_type' => 'project',
                    'target_id' => (string) $project['id'],
                    'metadata' => [
                        'input_ids' => $inputIds,
                        'text_chars' => mb_strlen($text),
                        'filename' => $stored !== null ? (string) $stored['filename'] : null,
                        'size_bytes' => $stored !== null ? (int) $stored['size_bytes'] : null,
                    ],
                ]);
            });
        } catch (\Throwable $error) {
            // Never leave an orphaned upload when the DB write fails.
            if ($stored !== null) {
                Storage::delete((string) $stored['key']);
            }

            throw $error;
        }

        return [
            'input_ids' => $inputIds,
            'has_attachment' => $stored !== null,
            'text_chars' => mb_strlen($text),
        ];
    }

    /**
     * Plain text of a .txt/.md attachment: BOM stripped, UTF-8 validated and
     * capped to MAX_INPUT_CHARS. Never parses/translates the file.
     *
     * @param array<string, mixed> $file
     */
    public static function readAttachmentText(array $file): string
    {
        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_file($tmpPath)) {
            throw AppError::validation(['attachment' => 'upload_failed']);
        }

        // Read a bounded prefix (4 bytes per char covers a worst-case UTF-8 char).
        $raw = @file_get_contents($tmpPath, false, null, 0, self::MAX_INPUT_CHARS * 4 + 4);
        if (!is_string($raw)) {
            throw AppError::validation(['attachment' => 'upload_failed']);
        }

        // Strip a UTF-8 byte-order mark, then cut at a character boundary.
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        $raw = mb_strcut($raw, 0, self::MAX_INPUT_CHARS * 4, 'UTF-8');
        if ($raw !== '' && !mb_check_encoding($raw, 'UTF-8')) {
            throw AppError::validation(['attachment' => 'invalid_type']);
        }

        return mb_substr(trim($raw), 0, self::MAX_INPUT_CHARS);
    }

    /**
     * Latest input rows (excerpts only) plus the real count for this project.
     *
     * @return array{count: int, rows: list<array<string, mixed>>}
     */
    private static function inputs(string $projectId, string $teamId): array
    {
        $count = (int) (Db::selectOne(
            'SELECT COUNT(*) AS total FROM inputs WHERE project_id = ? AND team_id = ? AND deleted_at IS NULL',
            [$projectId, $teamId],
        )['total'] ?? 0);

        $rows = Db::select(
            'SELECT id, kind, filename, source_text, created_at
             FROM inputs
             WHERE project_id = ? AND team_id = ? AND deleted_at IS NULL
             ORDER BY created_at DESC, id DESC
             LIMIT ' . self::LATEST_INPUTS,
            [$projectId, $teamId],
        );

        $latest = [];
        foreach ($rows as $row) {
            $text = trim((string) ($row['source_text'] ?? ''));
            $latest[] = [
                'id' => (string) $row['id'],
                'kind' => (string) $row['kind'],
                'filename' => $row['filename'] !== null ? (string) $row['filename'] : null,
                'excerpt' => $text === ''
                    ? ''
                    : mb_strimwidth($text, 0, self::INPUT_EXCERPT_CHARS, '…', 'UTF-8'),
                'created_at' => (string) $row['created_at'],
            ];
        }

        return ['count' => $count, 'rows' => $latest];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function drafts(string $projectId, string $teamId): array
    {
        $rows = Db::select(
            'SELECT id, status, version, is_labeled_draft, created_at
             FROM drafts
             WHERE project_id = ? AND team_id = ?
             ORDER BY created_at DESC, version DESC
             LIMIT ' . self::LATEST_DRAFTS,
            [$projectId, $teamId],
        );

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'status' => (string) $row['status'],
            'version' => (int) $row['version'],
            'is_labeled_draft' => (string) $row['is_labeled_draft'],
            'created_at' => (string) $row['created_at'],
        ], $rows);
    }

    /**
     * Real pipeline progress: inputs, approved fact sheet and latest draft are
     * real rows; gap report and export are honestly future roadmap steps.
     *
     * @param array<string, mixed>|null $factSheet
     * @param array<string, mixed>|null $latestDraft
     * @return array{steps: list<array<string, mixed>>, done: int, total: int, current: ?string}
     */
    private static function pipeline(int $inputCount, ?array $factSheet, ?array $latestDraft): array
    {
        $steps = [
            ['key' => 'inputs', 'done' => $inputCount > 0, 'future' => false, 'detail' => $inputCount],
            ['key' => 'facts', 'done' => $factSheet !== null, 'future' => false, 'detail' => $factSheet !== null ? (int) $factSheet['version'] : null],
            ['key' => 'draft', 'done' => $latestDraft !== null, 'future' => false, 'detail' => $latestDraft !== null ? (int) $latestDraft['version'] : null],
            ['key' => 'check', 'done' => false, 'future' => true, 'detail' => null],
            ['key' => 'export', 'done' => false, 'future' => true, 'detail' => null],
        ];

        $done = 0;
        $current = null;
        foreach ($steps as $index => $step) {
            if ($step['done']) {
                $done++;
            } elseif ($current === null) {
                $current = (string) $step['key'];
            }
            $steps[$index]['index'] = $index + 1;
        }

        return [
            'steps' => $steps,
            'done' => $done,
            'total' => self::PIPELINE_TOTAL,
            'current' => $current,
        ];
    }
}
