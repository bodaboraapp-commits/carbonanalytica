<?php

declare(strict_types=1);

use App\Db;
use App\Services\ProjectService;
use App\Services\TeamService;
use App\Services\WorkspaceDashboardService;
use App\Uuid;

/**
 * Tenant-facing dashboard: all numbers come from WorkspaceDashboardService and
 * must be scoped to the caller's workspace.
 */
return [
    'counts projects, facts, members and invitations for one workspace' => static function (): void {
        $owner = make_user('ws-owner@example.com');
        $userId = (string) $owner['user']['id'];
        $teamId = (string) $owner['team']['id'];

        $a = ProjectService::create($userId, $teamId, 'Project A');
        $b = ProjectService::create($userId, $teamId, 'Project B');
        $c = ProjectService::create($userId, $teamId, 'Project C');
        $d = ProjectService::create($userId, $teamId, 'Project D');

        Db::execute("UPDATE projects SET status = 'facts_pending_approval' WHERE id = ?", [(string) $a['id']]);
        Db::execute("UPDATE projects SET status = 'review' WHERE id = ?", [(string) $b['id']]);
        Db::execute("UPDATE projects SET status = 'setup' WHERE id = ?", [(string) $c['id']]);
        Db::execute("UPDATE projects SET status = 'complete' WHERE id = ?", [(string) $d['id']]);

        // Two confirmed facts + one unconfirmed.
        foreach ([['confirmed', 'confirmed'], ['confirmed', 'confirmed'], ['ai_suggested', 'suggested']] as $index => [$status, $key]) {
            Db::insert('facts', [
                'id' => Uuid::v4(),
                'project_id' => (string) $a['id'],
                'team_id' => $teamId,
                'category' => 'project',
                'fact_key' => 'demo.' . $key . '.' . $index,
                'label' => 'Demo fact',
                'value_type' => 'string',
                'status' => $status,
            ]);
        }

        TeamService::invite($userId, $teamId, 'invitee@example.com', 'editor');

        $dashboard = WorkspaceDashboardService::forTeam($userId, ['id' => $teamId]);
        $metrics = $dashboard['metrics'];

        assert_same(4, $metrics['projects']);
        assert_same(1, $metrics['projects_setup']);
        assert_same(1, $metrics['fact_sheets_pending']);
        assert_same(1, $metrics['projects_in_review']);
        assert_same(2, $metrics['facts_confirmed']);
        assert_same(1, $metrics['invitations']);
        // The invitation is pending, so only the owner is an active member.
        assert_same(1, $metrics['members']);
        assert_true($dashboard['can_edit'], 'owner can edit');
        assert_true($dashboard['can_manage_members'], 'owner can manage members');

        $statuses = array_column($dashboard['by_status'], 'total', 'status');
        assert_same(1, $statuses['setup'] ?? 0);
        assert_same(1, $statuses['facts_pending_approval'] ?? 0);
        assert_same(1, $statuses['review'] ?? 0);
        assert_same(1, $dashboard['attention']['setup']);
        assert_same(1, $dashboard['attention']['fact_sheets']);
    },

    'never counts another workspace' => static function (): void {
        $alice = make_user('ws-alice@example.com');
        $bob = make_user('ws-bob@example.com');

        ProjectService::create((string) $alice['user']['id'], (string) $alice['team']['id'], 'Alice project');
        ProjectService::create((string) $bob['user']['id'], (string) $bob['team']['id'], 'Bob project');
        ProjectService::create((string) $bob['user']['id'], (string) $bob['team']['id'], 'Bob project 2');

        $aliceMetrics = WorkspaceDashboardService::forTeam((string) $alice['user']['id'], ['id' => (string) $alice['team']['id']])['metrics'];
        $bobMetrics = WorkspaceDashboardService::forTeam((string) $bob['user']['id'], ['id' => (string) $bob['team']['id']])['metrics'];

        assert_same(1, $aliceMetrics['projects']);
        assert_same(2, $bobMetrics['projects']);
    },

    'refuses a workspace the user does not belong to' => static function (): void {
        $alice = make_user('ws-outsider@example.com');
        $bob = make_user('ws-owner2@example.com');

        assert_app_error('not_found', static function () use ($alice, $bob): void {
            WorkspaceDashboardService::forTeam((string) $alice['user']['id'], ['id' => (string) $bob['team']['id']]);
        });
    },

    'viewers see numbers but cannot edit' => static function (): void {
        $owner = make_user('ws-owner3@example.com');
        $teamId = (string) $owner['team']['id'];
        ProjectService::create((string) $owner['user']['id'], $teamId, 'Owner project');

        $viewer = add_team_member($teamId, (string) $owner['user']['id'], 'ws-viewer@example.com', 'viewer');

        $dashboard = WorkspaceDashboardService::forTeam((string) $viewer['user']['id'], ['id' => $teamId]);

        assert_same(1, $dashboard['metrics']['projects']);
        assert_false($dashboard['can_edit']);
        assert_false($dashboard['can_manage_members']);
    },
];
