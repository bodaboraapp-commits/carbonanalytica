<?php

declare(strict_types=1);

namespace App\Services;

use App\AppError;
use App\Audit;
use App\Db;
use App\Repositories\ProjectRepository;
use App\Tenancy;

/**
 * Project operations. Every method starts from the tenant access layer, so the
 * team id used in SQL is always one the caller is allowed to use.
 */
final class ProjectService
{
    /** @return list<array<string, mixed>> */
    public static function listForTeam(string $userId, string $teamId): array
    {
        Tenancy::teamForUser($userId, $teamId, 'viewer');
        return ProjectRepository::listForTeam($teamId);
    }

    /** @return array{project: array<string, mixed>, team: array<string, mixed>, role: string} */
    public static function get(string $userId, string $projectId): array
    {
        return Tenancy::projectForUser($userId, $projectId, 'viewer');
    }

    public static function create(string $userId, string $teamId, string $name, ?string $description = null): array
    {
        // Validate here as well as in the controller: the service is the boundary
        // every caller (pages, API, CLI) shares.
        $name = trim($name);
        if ($name === '') {
            throw AppError::validation(['name' => 'required']);
        }
        if (mb_strlen($name) > 200) {
            throw AppError::validation(['name' => 'too_long']);
        }
        if ($description !== null && mb_strlen($description) > 2000) {
            throw AppError::validation(['description' => 'too_long']);
        }

        Tenancy::teamForUser($userId, $teamId, 'editor');

        $project = Db::transaction(static function () use ($userId, $teamId, $name, $description): array {
            $project = ProjectRepository::create($teamId, [
                'name' => $name,
                'description' => $description,
                'created_by_user_id' => $userId,
            ]);

            Audit::record([
                'team_id' => $teamId,
                'actor_user_id' => $userId,
                'action' => 'project.created',
                'target_type' => 'project',
                'target_id' => (string) $project['id'],
                'metadata' => ['name' => $name],
            ]);

            return $project;
        });

        return $project;
    }

    public static function delete(string $userId, string $projectId): void
    {
        $scope = Tenancy::projectForUser($userId, $projectId, 'admin');

        if (!ProjectRepository::softDelete($projectId, (string) $scope['project']['team_id'])) {
            throw AppError::notFound('That project');
        }

        Audit::record([
            'team_id' => (string) $scope['project']['team_id'],
            'actor_user_id' => $userId,
            'action' => 'project.deleted',
            'target_type' => 'project',
            'target_id' => $projectId,
        ]);
    }
}
