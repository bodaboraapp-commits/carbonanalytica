<?php

declare(strict_types=1);

namespace App\Services;

use App\AppError;
use App\Audit;
use App\Db;
use App\Storage;
use App\Uuid;

/**
 * Admin-curated template library: private source-document upload, publication
 * state, deletion of template versions and the per-template SKILL.md that the
 * drafting engine uses for that version. Staff-only; every change is audited.
 *
 * Adding a template version also creates its AI Engine skill requirement in
 * status 'pending' until an admin uploads the SKILL.md ("ready"). Drafting for a
 * version whose skill is still pending is refused. The skill text lives in the
 * database only (admin guidance, never customer data, never a public file).
 *
 * Parsing an uploaded document into sections/fields/rules is a separate step-2
 * task. This service stores the source file privately (checksum + metadata) and
 * exposes it to the later parser; it never returns a filesystem path.
 */
final class TemplateLibraryService
{
    /** Document types an admin may upload as a template version source. */
    public const SOURCE_EXTENSIONS = ['pdf', 'docx', 'md', 'txt'];

    /** Private storage directory (keys under storage/uploads, never public). */
    private const SOURCE_DIRECTORY = 'templates';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_RETIRED = 'retired';

    /** Registry values accepted for a new PDD template (sync with the ENUM). */
    public const TEMPLATE_REGISTRIES = ['gold_standard', 'verra_vcs'];
    public const TEMPLATE_LANGUAGES = ['en', 'fr'];

    /**
     * SKILL.md upload: markdown only, read into the database (never stored as a
     * public file). Caps mirror the AI Engine skill limits.
     */
    public const SKILL_EXTENSIONS = ['md'];
    public const SKILL_MAX_BYTES = 1048576;
    public const SKILL_MAX_CHARS = 100000;

    public const SKILL_STATUS_PENDING = 'pending';
    public const SKILL_STATUS_READY = 'ready';

    /**
     * Upload (or replace) the private source document of one template version.
     *
     * @param array<string, mixed> $file a $_FILES entry
     * @return array<string, mixed> the refreshed version row
     */
    public static function uploadSource(string $actorUserId, string $versionId, array $file): array
    {
        TaskCenterService::assertStaff($actorUserId);
        $version = self::findVersion($versionId);

        $stored = Storage::putUploadedFile($file, self::SOURCE_DIRECTORY, self::SOURCE_EXTENSIONS);

        return self::applyStoredSource($actorUserId, $version, $stored);
    }

    /**
     * Attach an already-stored private file to a version. Used by uploadSource,
     * and exposed for imports/seeds so they share the same swap + audit rules.
     *
     * @param array{key: string, filename: string, mime_type: string, size_bytes: int, checksum_sha256: string} $stored
     * @return array<string, mixed> the refreshed version row
     */
    public static function attachStoredSource(string $actorUserId, string $versionId, array $stored): array
    {
        TaskCenterService::assertStaff($actorUserId);
        $version = self::findVersion($versionId);

        return self::applyStoredSource($actorUserId, $version, $stored);
    }

    /**
     * Create a new PDD template with its first draft version, its private source
     * document and a PENDING SKILL.md requirement. Staff-only; audited.
     *
     * The SKILL.md is not required in the same submit: the version starts with a
     * pending skill row and drafting is refused for it until uploadSkill()/
     * putSkillText() marks that skill ready.
     *
     * @param array<string, mixed> $input name, registry, language, version_label,
     *                                    methodology_code, description
     * @param array<string, mixed> $file a $_FILES entry for the source document
     * @return array{template: array<string, mixed>, version: array<string, mixed>, skill: array<string, mixed>}
     */
    public static function createTemplateWithVersion(string $actorUserId, array $input, array $file): array
    {
        TaskCenterService::assertStaff($actorUserId);

        $stored = Storage::putUploadedFile($file, self::SOURCE_DIRECTORY, self::SOURCE_EXTENSIONS);

        return self::createFromStoredSource($actorUserId, $input, $stored);
    }

    /**
     * Create a template from an already-stored private source file. Used by
     * createTemplateWithVersion(), seeds and tests so they share the same
     * validation, rows and audit rules.
     *
     * @param array<string, mixed> $input
     * @param array{key: string, filename: string, mime_type: string, size_bytes: int, checksum_sha256: string} $stored
     * @return array{template: array<string, mixed>, version: array<string, mixed>, skill: array<string, mixed>}
     */
    public static function createTemplateWithStoredSource(string $actorUserId, array $input, array $stored): array
    {
        TaskCenterService::assertStaff($actorUserId);

        return self::createFromStoredSource($actorUserId, $input, $stored);
    }

    /**
     * Upload the SKILL.md for one template version and mark its skill ready.
     * The file is validated (.md, size cap), read into the database and never
     * kept as a public file. Staff-only; audited.
     *
     * @param array<string, mixed> $file a $_FILES entry for the SKILL.md
     * @return array<string, mixed> the refreshed skill row
     */
    public static function uploadSkill(string $actorUserId, string $versionId, array $file): array
    {
        TaskCenterService::assertStaff($actorUserId);
        $version = self::findVersion($versionId);

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw AppError::validation(['skill' => 'required']);
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw AppError::validation(['skill' => 'too_large']);
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw AppError::validation(['skill' => 'upload_failed']);
        }

        $filename = (string) ($file['name'] ?? '');
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, self::SKILL_EXTENSIONS, true)) {
            throw AppError::validation(['skill' => 'invalid_type']);
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw AppError::validation(['skill' => 'empty']);
        }
        if ($size > self::SKILL_MAX_BYTES) {
            throw AppError::validation(['skill' => 'too_large']);
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmpPath)) {
            throw AppError::validation(['skill' => 'upload_failed']);
        }
        $text = @file_get_contents($tmpPath);
        if (!is_string($text)) {
            throw AppError::validation(['skill' => 'upload_failed']);
        }

        return self::saveSkillText($actorUserId, $version, $text, [
            'filename' => mb_substr(basename($filename), 0, 255),
            'mime_type' => 'text/markdown',
            'size_bytes' => $size,
        ]);
    }

    /**
     * Store an already-read SKILL.md text for a version and mark the skill ready.
     * Used by uploadSkill(), seeds and tests; same validation and audit.
     *
     * @param array{filename?: string, mime_type?: string, size_bytes?: int} $metadata
     * @return array<string, mixed> the refreshed skill row
     */
    public static function putSkillText(string $actorUserId, string $versionId, string $text, array $metadata = []): array
    {
        TaskCenterService::assertStaff($actorUserId);
        $version = self::findVersion($versionId);

        return self::saveSkillText($actorUserId, $version, $text, $metadata);
    }

    /**
     * Read-only drafting signal: the SKILL.md status for a template version
     * ('pending' | 'ready'), or null when no skill row exists yet (older/directly
     * inserted versions fall back to the registry-level skill).
     *
     * Deliberately not staff-gated: tenant drafting calls it for readiness.
     */
    public static function skillStatusForVersion(string $versionId): ?string
    {
        $row = Db::selectOne(
            'SELECT status FROM ai_engine_skills WHERE template_version_id = ? LIMIT 1',
            [$versionId],
        );

        return $row === null ? null : (string) $row['status'];
    }

    /**
     * Move a version through the only two allowed transitions:
     * draft → published (needs a stored source) and published → retired.
     *
     * @return array<string, mixed> the refreshed version row
     */
    public static function updateStatus(string $actorUserId, string $versionId, string $status): array
    {
        TaskCenterService::assertStaff($actorUserId);
        $version = self::findVersion($versionId);
        $current = (string) $version['status'];

        if (!in_array($status, [self::STATUS_PUBLISHED, self::STATUS_RETIRED], true)) {
            throw new AppError('template_status_invalid', 409, 'Only draft → published and published → retired are allowed.');
        }
        if ($status === $current) {
            throw new AppError('template_status_unchanged', 409, 'That template version already has this status.');
        }
        if ($current === self::STATUS_DRAFT && $status === self::STATUS_PUBLISHED) {
            if ((string) ($version['storage_key'] ?? '') === '') {
                throw new AppError('template_source_required', 409, 'Upload a source document before publishing this version.');
            }

            self::recordStatus($actorUserId, $version, self::STATUS_PUBLISHED, 'template_version.published', true);

            return self::findVersion((string) $version['id']);
        }
        if ($current === self::STATUS_PUBLISHED && $status === self::STATUS_RETIRED) {
            self::recordStatus($actorUserId, $version, self::STATUS_RETIRED, 'template_version.retired', false);

            return self::findVersion((string) $version['id']);
        }

        throw new AppError('template_status_invalid', 409, 'Only draft → published and published → retired are allowed.');
    }

    /**
     * Delete a draft or retired version. Published versions and versions that a
     * project or draft still references are refused. Sections/fields cascade and
     * the private source file is removed after the row is gone.
     */
    public static function deleteVersion(string $actorUserId, string $versionId): void
    {
        TaskCenterService::assertStaff($actorUserId);
        $version = self::findVersion($versionId);
        $status = (string) $version['status'];

        if ($status === self::STATUS_PUBLISHED) {
            throw new AppError('template_delete_published', 409, 'A published version cannot be deleted. Retire it first.');
        }
        if (!in_array($status, [self::STATUS_DRAFT, self::STATUS_RETIRED], true)) {
            throw new AppError('template_status_invalid', 409, 'Only draft or retired versions can be deleted.');
        }

        $projects = (int) (Db::selectOne(
            'SELECT COUNT(*) AS total FROM projects WHERE template_version_id = ?',
            [(string) $version['id']],
        )['total'] ?? 0);
        $drafts = (int) (Db::selectOne(
            'SELECT COUNT(*) AS total FROM drafts WHERE template_version_id = ?',
            [(string) $version['id']],
        )['total'] ?? 0);
        if ($projects > 0 || $drafts > 0) {
            throw new AppError('template_delete_in_use', 409, 'This template version is used by a project or draft and cannot be deleted.');
        }

        $storageKey = (string) ($version['storage_key'] ?? '');

        Db::transaction(static function () use ($actorUserId, $version): void {
            Db::execute('DELETE FROM template_versions WHERE id = ?', [(string) $version['id']]);

            Audit::record([
                'actor_user_id' => $actorUserId,
                'action' => 'template_version.deleted',
                'target_type' => 'template_version',
                'target_id' => (string) $version['id'],
                'metadata' => [
                    'template_id' => (string) $version['template_id'],
                    'version_label' => (string) $version['version_label'],
                    'status' => (string) $version['status'],
                ],
            ]);
        });

        if ($storageKey !== '') {
            Storage::delete($storageKey);
        }
    }

    /**
     * @param array<string, mixed> $version
     * @param array{key: string, filename: string, mime_type: string, size_bytes: int, checksum_sha256: string} $stored
     * @return array<string, mixed>
     */
    private static function applyStoredSource(string $actorUserId, array $version, array $stored): array
    {
        $previousKey = (string) ($version['storage_key'] ?? '');
        $newKey = (string) ($stored['key'] ?? '');
        if ($newKey === '') {
            throw AppError::validation(['file' => 'invalid_type']);
        }

        try {
            Db::transaction(static function () use ($actorUserId, $version, $stored, $newKey): void {
                Db::execute(
                    'UPDATE template_versions
                     SET storage_key = ?, source_filename = ?, source_mime_type = ?, source_size_bytes = ?, checksum_sha256 = ?
                     WHERE id = ?',
                    [
                        $newKey,
                        (string) $stored['filename'],
                        (string) $stored['mime_type'],
                        (int) $stored['size_bytes'],
                        (string) $stored['checksum_sha256'],
                        (string) $version['id'],
                    ],
                );

                Audit::record([
                    'actor_user_id' => $actorUserId,
                    'action' => 'template_version.source_uploaded',
                    'target_type' => 'template_version',
                    'target_id' => (string) $version['id'],
                    'metadata' => [
                        'template_id' => (string) $version['template_id'],
                        'version_label' => (string) $version['version_label'],
                        'filename' => (string) $stored['filename'],
                        'size_bytes' => (int) $stored['size_bytes'],
                        'checksum_sha256' => (string) $stored['checksum_sha256'],
                    ],
                ]);
            });
        } catch (\Throwable $error) {
            // Never leave a freshly stored orphan behind when the DB write fails.
            if ($newKey !== '' && $newKey !== $previousKey) {
                Storage::delete($newKey);
            }

            throw $error;
        }

        if ($previousKey !== '' && $previousKey !== $newKey) {
            Storage::delete($previousKey);
        }

        return self::findVersion((string) $version['id']);
    }

    /**
     * @param array<string, mixed> $version
     */
    private static function recordStatus(
        string $actorUserId,
        array $version,
        string $status,
        string $action,
        bool $published,
    ): void {
        $now = gmdate('Y-m-d H:i:s');

        Db::transaction(static function () use ($actorUserId, $version, $status, $action, $published, $now): void {
            if ($published) {
                Db::execute(
                    'UPDATE template_versions SET status = ?, published_at = ?, retired_at = NULL WHERE id = ?',
                    [$status, $now, (string) $version['id']],
                );
            } else {
                Db::execute(
                    'UPDATE template_versions SET status = ?, retired_at = ? WHERE id = ?',
                    [$status, $now, (string) $version['id']],
                );
            }

            Audit::record([
                'actor_user_id' => $actorUserId,
                'action' => $action,
                'target_type' => 'template_version',
                'target_id' => (string) $version['id'],
                'metadata' => [
                    'template_id' => (string) $version['template_id'],
                    'version_label' => (string) $version['version_label'],
                    'from' => (string) $version['status'],
                    'to' => $status,
                ],
            ]);
        });
    }

    /**
     * @param array<string, mixed> $input
     * @param array{key: string, filename: string, mime_type: string, size_bytes: int, checksum_sha256: string} $stored
     * @return array{template: array<string, mixed>, version: array<string, mixed>, skill: array<string, mixed>}
     */
    private static function createFromStoredSource(string $actorUserId, array $input, array $stored): array
    {
        $fields = self::validateCreate($input);

        $storageKey = (string) ($stored['key'] ?? '');
        if ($storageKey === '') {
            throw AppError::validation(['source' => 'required']);
        }

        $templateId = Uuid::v4();
        $versionId = Uuid::v4();
        $skillId = Uuid::v4();
        $skillName = mb_substr(trim($fields['name'] . ' ' . $fields['version_label']), 0, 160);

        try {
            Db::transaction(static function () use ($actorUserId, $fields, $stored, $storageKey, $templateId, $versionId, $skillId, $skillName): void {
                Db::insert('templates', [
                    'id' => $templateId,
                    'name' => $fields['name'],
                    'kind' => 'pdd_template',
                    'registry' => $fields['registry'],
                    'methodology_code' => $fields['methodology_code'],
                    'language' => $fields['language'],
                    'description' => $fields['description'],
                    'created_by_user_id' => $actorUserId,
                ]);

                Db::insert('template_versions', [
                    'id' => $versionId,
                    'template_id' => $templateId,
                    'version_label' => $fields['version_label'],
                    'language' => $fields['language'],
                    'status' => self::STATUS_DRAFT,
                    'storage_key' => $storageKey,
                    'source_filename' => (string) $stored['filename'],
                    'source_mime_type' => (string) $stored['mime_type'],
                    'source_size_bytes' => (int) $stored['size_bytes'],
                    'checksum_sha256' => (string) $stored['checksum_sha256'],
                ]);

                // Every added template version owns a SKILL.md requirement; it is
                // pending until an admin uploads the document.
                Db::insert('ai_engine_skills', [
                    'id' => $skillId,
                    'template_id' => $templateId,
                    'template_version_id' => $versionId,
                    'registry' => $fields['registry'],
                    'name' => $skillName,
                    'skill_text' => null,
                    'status' => self::SKILL_STATUS_PENDING,
                    'enabled' => 1,
                    'created_by_user_id' => $actorUserId,
                ]);

                Audit::record([
                    'actor_user_id' => $actorUserId,
                    'action' => 'template.created',
                    'target_type' => 'template',
                    'target_id' => $templateId,
                    'metadata' => [
                        'name' => $fields['name'],
                        'kind' => 'pdd_template',
                        'registry' => $fields['registry'],
                        'language' => $fields['language'],
                        'version_label' => $fields['version_label'],
                        'filename' => (string) $stored['filename'],
                        'size_bytes' => (int) $stored['size_bytes'],
                        'checksum_sha256' => (string) $stored['checksum_sha256'],
                        'skill_status' => self::SKILL_STATUS_PENDING,
                    ],
                ]);

                Audit::record([
                    'actor_user_id' => $actorUserId,
                    'action' => 'ai_engine.skill_pending',
                    'target_type' => 'ai_engine_skill',
                    'target_id' => $skillId,
                    'metadata' => [
                        'template_id' => $templateId,
                        'template_version_id' => $versionId,
                        'registry' => $fields['registry'],
                    ],
                ]);
            });
        } catch (\Throwable $error) {
            // Never leave a freshly stored orphan behind when the DB write fails.
            Storage::delete($storageKey);
            throw $error;
        }

        return [
            'template' => Db::selectOne('SELECT * FROM templates WHERE id = ?', [$templateId]) ?? [],
            'version' => self::findVersion($versionId),
            'skill' => self::refreshSkillRow($skillId),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{name: string, registry: string, language: string, version_label: string, methodology_code: string|null, description: string|null}
     */
    private static function validateCreate(array $input): array
    {
        $errors = [];

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'required';
        } elseif (mb_strlen($name) > 200) {
            $errors['name'] = 'too_long';
        }

        $registry = trim((string) ($input['registry'] ?? ''));
        if (!in_array($registry, self::TEMPLATE_REGISTRIES, true)) {
            $errors['registry'] = 'invalid_choice';
        }

        $language = trim((string) ($input['language'] ?? 'en'));
        if (!in_array($language, self::TEMPLATE_LANGUAGES, true)) {
            $errors['language'] = 'invalid_choice';
        }

        $versionLabel = trim((string) ($input['version_label'] ?? 'v1.0'));
        if ($versionLabel === '') {
            $versionLabel = 'v1.0';
        }
        if (mb_strlen($versionLabel) > 40) {
            $errors['version_label'] = 'too_long';
        }

        $methodologyCode = trim((string) ($input['methodology_code'] ?? ''));
        if (mb_strlen($methodologyCode) > 40) {
            $errors['methodology_code'] = 'too_long';
        }

        $description = trim((string) ($input['description'] ?? ''));
        if (mb_strlen($description) > 2000) {
            $errors['description'] = 'too_long';
        }

        if ($errors !== []) {
            throw AppError::validation($errors);
        }

        return [
            'name' => $name,
            'registry' => $registry,
            'language' => $language,
            'version_label' => $versionLabel,
            'methodology_code' => $methodologyCode !== '' ? $methodologyCode : null,
            'description' => $description !== '' ? $description : null,
        ];
    }

    /**
     * @param array<string, mixed> $version
     * @param array{filename?: string, mime_type?: string, size_bytes?: int} $metadata
     * @return array<string, mixed> the refreshed skill row
     */
    private static function saveSkillText(string $actorUserId, array $version, string $text, array $metadata): array
    {
        $text = trim($text);
        if ($text === '') {
            throw AppError::validation(['skill' => 'required']);
        }
        if (mb_strlen($text) > self::SKILL_MAX_CHARS) {
            throw AppError::validation(['skill' => 'too_long']);
        }

        $filename = (string) ($metadata['filename'] ?? '');
        $mimeType = (string) ($metadata['mime_type'] ?? 'text/markdown');
        $sizeBytes = (int) ($metadata['size_bytes'] ?? strlen($text));
        $versionId = (string) $version['id'];
        $existing = self::skillRowForVersion($versionId);
        $skillId = $existing !== null ? (string) $existing['id'] : Uuid::v4();
        $chars = mb_strlen($text);

        Db::transaction(static function () use ($actorUserId, $version, $versionId, $existing, $skillId, $text, $filename, $mimeType, $sizeBytes, $chars): void {
            if ($existing !== null) {
                Db::execute(
                    'UPDATE ai_engine_skills
                     SET skill_text = ?, status = ?, source_filename = ?, source_mime_type = ?, source_size_bytes = ?
                     WHERE id = ?',
                    [$text, self::SKILL_STATUS_READY, $filename !== '' ? $filename : null, $mimeType, $sizeBytes, $skillId],
                );
            } else {
                Db::insert('ai_engine_skills', [
                    'id' => $skillId,
                    'template_id' => (string) $version['template_id'],
                    'template_version_id' => $versionId,
                    'registry' => (string) ($version['registry'] ?? ''),
                    'name' => mb_substr(trim(((string) ($version['template_name'] ?? '')) . ' ' . ((string) ($version['version_label'] ?? ''))), 0, 160),
                    'skill_text' => $text,
                    'source_filename' => $filename !== '' ? $filename : null,
                    'source_mime_type' => $mimeType,
                    'source_size_bytes' => $sizeBytes,
                    'status' => self::SKILL_STATUS_READY,
                    'enabled' => 1,
                    'created_by_user_id' => $actorUserId,
                ]);
            }

            Audit::record([
                'actor_user_id' => $actorUserId,
                'action' => 'ai_engine.skill_ready',
                'target_type' => 'ai_engine_skill',
                'target_id' => $skillId,
                'metadata' => [
                    'template_id' => (string) $version['template_id'],
                    'template_version_id' => $versionId,
                    'registry' => (string) ($version['registry'] ?? ''),
                    'filename' => $filename,
                    'chars' => $chars,
                ],
            ]);
        });

        return self::refreshSkillRow($skillId);
    }

    /** @return array<string, mixed>|null */
    private static function skillRowForVersion(string $versionId): ?array
    {
        return Db::selectOne('SELECT * FROM ai_engine_skills WHERE template_version_id = ? LIMIT 1', [$versionId]);
    }

    /** @return array<string, mixed> */
    private static function refreshSkillRow(string $skillId): array
    {
        $row = Db::selectOne('SELECT * FROM ai_engine_skills WHERE id = ? LIMIT 1', [$skillId]);
        if ($row === null) {
            throw AppError::internal();
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private static function findVersion(string $versionId): array
    {
        $version = Db::selectOne(
            'SELECT tv.id, tv.template_id, tv.version_label, tv.status, tv.storage_key,
                    tv.source_filename, tv.source_mime_type, tv.source_size_bytes, tv.checksum_sha256,
                    tv.published_at, tv.retired_at, tpl.name AS template_name, tpl.registry, tpl.kind
             FROM template_versions tv
             INNER JOIN templates tpl ON tpl.id = tv.template_id
             WHERE tv.id = ?
             LIMIT 1',
            [$versionId],
        );

        if ($version === null) {
            throw AppError::notFound('That template version');
        }

        return $version;
    }
}
