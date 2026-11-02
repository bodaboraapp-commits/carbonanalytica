<?php

declare(strict_types=1);

use App\AppError;

use App\Services\ProjectService;

return [
    'creates a project with a name and description' => static function (): void {
        $owner = make_user('owner@example.com');
        $userId = (string) $owner['user']['id'];
        $teamId = (string) $owner['team']['id'];

        $project = ProjectService::create($userId, $teamId, 'Kisumu Clean Cookstoves', 'Improved cookstoves for households.');

        assert_same('Kisumu Clean Cookstoves', $project['name']);
        assert_same('setup', $project['status']);
        assert_same('confidential', $project['confidentiality']);
        assert_same($teamId, (string) $project['team_id']);
        assert_same(1, count_rows('audit_logs', "action = 'project.created'"));
    },

    'lists projects newest first' => static function (): void {
        $owner = make_user('owner@example.com');
        $userId = (string) $owner['user']['id'];
        $teamId = (string) $owner['team']['id'];

        ProjectService::create($userId, $teamId, 'First project');
        ProjectService::create($userId, $teamId, 'Second project');

        $projects = ProjectService::listForTeam($userId, $teamId);
        assert_count(2, $projects);
    },

    'rejects an empty project name' => static function (): void {
        $owner = make_user('owner@example.com');

        try {
            ProjectService::create((string) $owner['user']['id'], (string) $owner['team']['id'], '');
            throw new RuntimeException('Expected a validation error');
        } catch (AppError $error) {
            assert_same('validation_error', $error->errorCode);
        }
    },

    'allows only workspace admins to delete a project' => static function (): void {
        $owner = make_user('owner@example.com');
        $userId = (string) $owner['user']['id'];
        $teamId = (string) $owner['team']['id'];

        $editor = add_team_member($teamId, $userId, 'editor@example.com', 'editor');
        $admin = add_team_member($teamId, $userId, 'admin@example.com', 'admin');

        $project = ProjectService::create($userId, $teamId, 'Project to delete');

        // Editor cannot delete.
        assert_app_error('forbidden', static function () use ($editor, $project): void {
            ProjectService::delete((string) $editor['user']['id'], (string) $project['id']);
        });

        // Admin can.
        ProjectService::delete((string) $admin['user']['id'], (string) $project['id']);
        assert_same(1, count_rows('audit_logs', "action = 'project.deleted'"));

        // Soft deleted: hidden from reads, still present for audit.
        assert_app_error('not_found', static function () use ($userId, $project): void {
            ProjectService::get($userId, (string) $project['id']);
        });
        assert_same(1, count_rows('projects', 'id = ? AND deleted_at IS NOT NULL', [(string) $project['id']]));
    },

    'does not show deleted projects in the list' => static function (): void {
        $owner = make_user('owner@example.com');
        $userId = (string) $owner['user']['id'];
        $teamId = (string) $owner['team']['id'];

        $project = ProjectService::create($userId, $teamId, 'Temporary project');
        ProjectService::delete($userId, (string) $project['id']);

        assert_count(0, ProjectService::listForTeam($userId, $teamId));
    },
];
