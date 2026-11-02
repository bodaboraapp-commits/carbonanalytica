<?php

declare(strict_types=1);

namespace App\Services;

use App\AppError;
use App\Audit;
use App\Db;
use App\Storage;

/**
 * Admin-curated template library: private source-document upload, publication
 * state and deletion of template versions. Staff-only; every change is audited.
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

    /** @return array<string, mixed> */
    private static function findVersion(string $versionId): array
    {
        $version = Db::selectOne(
            'SELECT tv.id, tv.template_id, tv.version_label, tv.status, tv.storage_key,
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
