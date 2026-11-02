<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Db;
use App\Uuid;

/**
 * Organization onboarding rows: one profile per team and one current file per
 * required document kind. Every query filters on the team id supplied by the
 * tenant access layer.
 */
final class OrganizationRepository
{
    public static function findProfile(string $teamId): ?array
    {
        return Db::selectOne('SELECT * FROM organization_profiles WHERE team_id = ? LIMIT 1', [$teamId]);
    }

    /**
     * Platform review lookup: staff review across tenants, so this is not
     * tenant-scoped. Callers are the staff-only review service/controller.
     */
    public static function findProfileById(string $profileId): ?array
    {
        return Db::selectOne('SELECT * FROM organization_profiles WHERE id = ? LIMIT 1', [$profileId]);
    }

    /**
     * Record an admin decision on a submitted profile. The status guard makes
     * the transition single-shot: a stale form cannot overwrite a decision.
     *
     * @param 'verified'|'rejected' $status
     */
    public static function markReviewed(string $profileId, string $status, ?string $note): bool
    {
        if (!in_array($status, ['verified', 'rejected'], true)) {
            throw new \InvalidArgumentException('Invalid organization review status');
        }

        return Db::execute(
            "UPDATE organization_profiles
             SET status = ?, reviewed_at = NOW(3), review_note = ?
             WHERE id = ? AND status = 'submitted'",
            [$status, $note, $profileId],
        ) > 0;
    }

    /** Create the profile row on first save (idempotent under concurrent requests). */
    public static function ensureProfile(string $teamId): array
    {
        $profile = self::findProfile($teamId);
        if ($profile !== null) {
            return $profile;
        }

        Db::execute(
            'INSERT IGNORE INTO organization_profiles (id, team_id, status) VALUES (?, ?, ?)',
            [Uuid::v4(), $teamId, 'draft'],
        );

        $profile = self::findProfile($teamId);
        if ($profile === null) {
            throw new \RuntimeException('Failed to create organization profile');
        }
        return $profile;
    }

    /** @param array<string, mixed> $fields */
    public static function updateProfile(string $teamId, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $assignments = implode(', ', array_map(static fn (string $column): string => "`{$column}` = :{$column}", array_keys($fields)));
        $statement = Db::pdo()->prepare("UPDATE organization_profiles SET {$assignments} WHERE team_id = :team_id");
        $statement->bindValue(':team_id', $teamId);
        foreach ($fields as $column => $value) {
            $statement->bindValue(':' . $column, $value);
        }
        $statement->execute();
    }

    public static function markSubmitted(string $teamId, string $userId): void
    {
        Db::execute(
            "UPDATE organization_profiles
             SET status = 'submitted', submitted_at = NOW(3), attested_at = NOW(3), attested_by_user_id = ?
             WHERE team_id = ?",
            [$userId, $teamId],
        );
    }

    /** @return list<array<string, mixed>> */
    public static function documentsForTeam(string $teamId): array
    {
        return Db::select(
            'SELECT * FROM organization_documents WHERE team_id = ? ORDER BY FIELD(kind, ?, ?, ?, ?), created_at ASC',
            [$teamId, 'registration_certificate', 'ownership_structure', 'declaration', 'tax_compliance'],
        );
    }

    public static function findDocument(string $teamId, string $documentId): ?array
    {
        return Db::selectOne(
            'SELECT * FROM organization_documents WHERE id = ? AND team_id = ? LIMIT 1',
            [$documentId, $teamId],
        );
    }

    public static function findDocumentByKind(string $teamId, string $kind): ?array
    {
        return Db::selectOne(
            'SELECT * FROM organization_documents WHERE team_id = ? AND kind = ? LIMIT 1',
            [$teamId, $kind],
        );
    }

    /**
     * Store the current file for a document kind, replacing an earlier upload.
     *
     * @param array<string, mixed> $data
     */
    public static function saveDocument(string $teamId, array $data): array
    {
        $existing = self::findDocumentByKind($teamId, (string) $data['kind']);

        if ($existing === null) {
            Db::insert('organization_documents', [
                'id' => Uuid::v4(),
                'team_id' => $teamId,
                'kind' => $data['kind'],
                'original_filename' => $data['original_filename'],
                'storage_key' => $data['storage_key'],
                'mime_type' => $data['mime_type'],
                'size_bytes' => $data['size_bytes'],
                'checksum_sha256' => $data['checksum_sha256'],
                'uploaded_by_user_id' => $data['uploaded_by_user_id'] ?? null,
            ]);
        } else {
            Db::execute(
                'UPDATE organization_documents
                 SET original_filename = ?, storage_key = ?, mime_type = ?, size_bytes = ?,
                     checksum_sha256 = ?, uploaded_by_user_id = ?, updated_at = NOW(3)
                 WHERE id = ? AND team_id = ?',
                [
                    $data['original_filename'],
                    $data['storage_key'],
                    $data['mime_type'],
                    $data['size_bytes'],
                    $data['checksum_sha256'],
                    $data['uploaded_by_user_id'] ?? null,
                    (string) $existing['id'],
                    $teamId,
                ],
            );
        }

        $document = self::findDocumentByKind($teamId, (string) $data['kind']);
        if ($document === null) {
            throw new \RuntimeException('Failed to save organization document');
        }
        return $document;
    }

    public static function deleteDocument(string $teamId, string $documentId): bool
    {
        return Db::execute(
            'DELETE FROM organization_documents WHERE id = ? AND team_id = ?',
            [$documentId, $teamId],
        ) > 0;
    }
}
