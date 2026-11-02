<?php

declare(strict_types=1);

use App\Repositories\TeamRepository;
use App\Services\ProjectService;
use App\Services\TeamService;

return [
    'viewers cannot create projects; editors can' => static function (): void {
        $owner = make_user('owner@example.com');
        $teamId = (string) $owner['team']['id'];

        $editor = add_team_member($teamId, (string) $owner['user']['id'], 'editor@example.com', 'editor');
        $viewer = add_team_member($teamId, (string) $owner['user']['id'], 'viewer@example.com', 'viewer');

        assert_app_error('forbidden', static function () use ($viewer, $teamId): void {
            ProjectService::create((string) $viewer['user']['id'], $teamId, 'Viewer project');
        });

        $project = ProjectService::create((string) $editor['user']['id'], $teamId, 'Editor project');
        assert_not_null($project['id']);
    },

    'lets an admin invite but not change roles' => static function (): void {
        $owner = make_user('owner@example.com');
        $teamId = (string) $owner['team']['id'];
        $admin = add_team_member($teamId, (string) $owner['user']['id'], 'admin@example.com', 'admin');
        $editor = add_team_member($teamId, (string) $owner['user']['id'], 'editor@example.com', 'editor');

        // Admin can invite.
        TeamService::invite((string) $admin['user']['id'], $teamId, 'newbie@example.com', 'viewer');

        // Admin cannot change roles: only the owner can.
        assert_app_error('forbidden', static function () use ($admin, $teamId, $editor): void {
            TeamService::changeMemberRole(
                (string) $admin['user']['id'],
                $teamId,
                (string) $editor['membership']['id'],
                'viewer',
            );
        });
    },

    'lets only the owner change a role' => static function (): void {
        $owner = make_user('owner@example.com');
        $teamId = (string) $owner['team']['id'];
        $editor = add_team_member($teamId, (string) $owner['user']['id'], 'editor@example.com', 'editor');

        TeamService::changeMemberRole((string) $owner['user']['id'], $teamId, (string) $editor['membership']['id'], 'viewer');

        $updated = TeamRepository::findMembership($teamId, (string) $editor['user']['id']);
        assert_same('viewer', $updated['role']);
        assert_same(1, count_rows('audit_logs', "action = 'team.member.role_changed'"));
    },

    'never changes the owner role' => static function (): void {
        $owner = make_user('owner@example.com');
        $teamId = (string) $owner['team']['id'];
        $ownerMembership = TeamRepository::findMembership($teamId, (string) $owner['user']['id']);

        assert_app_error('last_owner', static function () use ($owner, $teamId, $ownerMembership): void {
            TeamService::changeMemberRole(
                (string) $owner['user']['id'],
                $teamId,
                (string) $ownerMembership['id'],
                'editor',
            );
        });
    },

    'rejects assigning the owner role through the role endpoint' => static function (): void {
        $owner = make_user('owner@example.com');
        $teamId = (string) $owner['team']['id'];
        $editor = add_team_member($teamId, (string) $owner['user']['id'], 'editor@example.com', 'editor');

        assert_app_error('invalid_role', static function () use ($owner, $teamId, $editor): void {
            TeamService::changeMemberRole(
                (string) $owner['user']['id'],
                $teamId,
                (string) $editor['membership']['id'],
                'owner',
            );
        });
    },

    'lets only the owner remove members' => static function (): void {
        $owner = make_user('owner@example.com');
        $teamId = (string) $owner['team']['id'];
        $admin = add_team_member($teamId, (string) $owner['user']['id'], 'admin@example.com', 'admin');
        $editor = add_team_member($teamId, (string) $owner['user']['id'], 'editor@example.com', 'editor');

        assert_app_error('forbidden', static function () use ($admin, $teamId, $editor): void {
            TeamService::removeMember((string) $admin['user']['id'], $teamId, (string) $editor['membership']['id']);
        });

        TeamService::removeMember((string) $owner['user']['id'], $teamId, (string) $editor['membership']['id']);

        assert_null(TeamRepository::findMembership($teamId, (string) $editor['user']['id']));
        assert_same(1, count_rows('audit_logs', "action = 'team.member.removed'"));
    },

    'never removes the owner' => static function (): void {
        $owner = make_user('owner@example.com');
        $teamId = (string) $owner['team']['id'];
        $ownerMembership = TeamRepository::findMembership($teamId, (string) $owner['user']['id']);

        assert_app_error('last_owner', static function () use ($owner, $teamId, $ownerMembership): void {
            TeamService::removeMember((string) $owner['user']['id'], $teamId, (string) $ownerMembership['id']);
        });
    },

    'suspended members lose access' => static function (): void {
        $owner = make_user('owner@example.com');
        $teamId = (string) $owner['team']['id'];
        $editor = add_team_member($teamId, (string) $owner['user']['id'], 'suspended@example.com', 'editor');

        \App\Db::execute('UPDATE team_members SET status = ? WHERE id = ?', ['suspended', (string) $editor['membership']['id']]);

        assert_app_error('not_found', static function () use ($editor, $teamId): void {
            ProjectService::create((string) $editor['user']['id'], $teamId, 'Suspended project');
        });
    },
];
