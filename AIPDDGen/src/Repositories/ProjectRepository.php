<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Db;
use App\Uuid;

/**
 * Project rows. Every read and write takes a team id from the tenant access
 * layer, so no query can silently cross workspaces.
 */
final class ProjectRepository
{
    /** @return list<array<string, mixed>> */
    public static function listForTeam(string $teamId, int $limit = 100): array
    {
        $limit = max(1, min($limit, 200));
        return Db::select(
            "SELECT id, name, description, status, registry, country_code, project_type, language,
                    confidentiality, created_at, created_by_user_id
             FROM projects
             WHERE team_id = ? AND deleted_at IS NULL
             ORDER BY created_at DESC
             LIMIT {$limit}",
            [$teamId],
        );
    }

    public static function findForTeam(string $projectId, string $teamId): ?array
    {
        return Db::selectOne(
            'SELECT * FROM projects WHERE id = ? AND team_id = ? AND deleted_at IS NULL LIMIT 1',
            [$projectId, $teamId],
        );
    }

    /** @param array<string, mixed> $data */
    public static function create(string $teamId, array $data): array
    {
        $id = Uuid::v4();
        Db::insert('projects', [
            'id' => $id,
            'team_id' => $teamId,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'country_code' => $data['country_code'] ?? null,
            'registry' => $data['registry'] ?? null,
            'project_type' => $data['project_type'] ?? null,
            'methodology_id' => $data['methodology_id'] ?? null,
            'template_version_id' => $data['template_version_id'] ?? null,
            'status' => $data['status'] ?? 'setup',
            'language' => $data['language'] ?? 'en',
            'confidentiality' => $data['confidentiality'] ?? 'confidential',
            'created_by_user_id' => $data['created_by_user_id'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ]);

        $project = self::findForTeam($id, $teamId);
        if ($project === null) {
            throw new \RuntimeException('Failed to create project');
        }
        return $project;
    }

    /** @param array<string, mixed> $fields */
    public static function update(string $projectId, string $teamId, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $assignments = implode(', ', array_map(static fn (string $column): string => "`{$column}` = :{$column}", array_keys($fields)));
        $statement = Db::pdo()->prepare(
            "UPDATE projects SET {$assignments} WHERE id = :id AND team_id = :team_id AND deleted_at IS NULL"
        );
        $statement->bindValue(':id', $projectId);
        $statement->bindValue(':team_id', $teamId);
        foreach ($fields as $column => $value) {
            $statement->bindValue(':' . $column, $value);
        }
        $statement->execute();
    }

    /** Soft delete: rows stay for audit and the 30-day deletion window. */
    public static function softDelete(string $projectId, string $teamId): bool
    {
        return Db::execute(
            'UPDATE projects SET deleted_at = NOW(3) WHERE id = ? AND team_id = ? AND deleted_at IS NULL',
            [$projectId, $teamId],
        ) > 0;
    }
}
