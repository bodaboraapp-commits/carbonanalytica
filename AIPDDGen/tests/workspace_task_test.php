<?php

declare(strict_types=1);

use App\Db;
use App\Repositories\UserRepository;
use App\Services\TaskCenterService;
use App\Services\WorkspaceTaskService;

/**
 * Workspace admin job queue: workspace-scoped review tasks.
 *
 * Platform tasks (organisation KYB, template QA) stay with Super Admin; the
 * workspace queue only shows this workspace's validation / fact-sheet / draft
 * work to owner/admin roles.
 */
function make_ws_staff(string $email = 'ws-task-staff@example.com'): array
{
    $account = make_user($email, 'Platform Reviewer');
    Db::execute('UPDATE users SET is_platform_admin = 1 WHERE id = ?', [(string) $account['user']['id']]);
    $account['user'] = UserRepository::findById((string) $account['user']['id']);
    return $account;
}

/** @param array<string, mixed> $overrides */
function make_ws_task(string $staffId, string $teamId, array $overrides = []): array
{
    return TaskCenterService::createTask($staffId, array_merge([
        'work_type' => 'project_validation',
        'title' => 'Workspace review',
        'priority' => 'normal',
        'team_id' => $teamId,
    ], $overrides));
}

return [
    'workspace queue shows only this workspace and only workspace task types' => static function (): void {
        $staff = make_ws_staff('ws-queue-staff@example.com');
        $owner = make_user('ws-queue-owner@example.com');
        $other = make_user('ws-queue-other@example.com');
        $teamId = (string) $owner['team']['id'];
        $staffId = (string) $staff['user']['id'];

        make_ws_task($staffId, $teamId, ['title' => 'Validate project boundary']);
        make_ws_task($staffId, $teamId, [
            'title' => 'Approve fact sheet',
            'work_type' => 'fact_sheet_review',
        ]);
        // Another workspace must never leak into this queue.
        make_ws_task($staffId, (string) $other['team']['id'], ['title' => 'Other workspace task']);
        // Platform-only work stays with Super Admin.
        make_ws_task($staffId, $teamId, [
            'title' => 'Platform template QA',
            'work_type' => 'template_review',
        ]);

        $queue = WorkspaceTaskService::queue((string) $owner['user']['id'], $teamId, []);

        assert_same(2, $queue['total']);
        $titles = array_column($queue['rows'], 'title');
        sort($titles);
        assert_same(['Approve fact sheet', 'Validate project boundary'], $titles);

        $types = array_column($queue['by_work_type'], 'total', 'work_type');
        assert_same(1, $types['project_validation'] ?? 0);
        assert_same(1, $types['fact_sheet_review'] ?? 0);
        assert_true(!isset($types['template_review']), 'platform tasks must not appear in the workspace queue');
    },

    'platform queue excludes workspace-scoped tasks' => static function (): void {
        $staff = make_ws_staff('ws-queue-platform@example.com');
        $owner = make_user('ws-queue-platform-owner@example.com');
        $teamId = (string) $owner['team']['id'];
        $staffId = (string) $staff['user']['id'];

        make_ws_task($staffId, $teamId, ['title' => 'Workspace validation']);
        make_ws_task($staffId, $teamId, [
            'title' => 'Platform KYC',
            'work_type' => 'organisation_kyb',
        ]);

        $queue = TaskCenterService::queue($staffId, ['scope' => 'all']);

        assert_same(1, $queue['total']);
        assert_same('Platform KYC', (string) $queue['rows'][0]['title']);
    },

    'owner and admins can open the workspace queue, editors and viewers cannot' => static function (): void {
        $owner = make_user('ws-queue-roles-owner@example.com');
        $teamId = (string) $owner['team']['id'];

        $editor = add_team_member($teamId, (string) $owner['user']['id'], 'ws-queue-editor@example.com', 'editor');
        $viewer = add_team_member($teamId, (string) $owner['user']['id'], 'ws-queue-viewer@example.com', 'viewer');
        $admin = add_team_member($teamId, (string) $owner['user']['id'], 'ws-queue-admin@example.com', 'admin');

        foreach ([$editor, $viewer] as $member) {
            // Legitimate workspace members without the admin role get 403 from
            // the tenant gate; the HTTP page hides itself with a 404.
            assert_app_error('forbidden', static function () use ($member, $teamId): void {
                WorkspaceTaskService::queue((string) $member['user']['id'], $teamId, []);
            });
        }

        // Someone outside the workspace sees nothing at all (404).
        $outsider = make_user('ws-queue-outsider@example.com');
        assert_app_error('not_found', static function () use ($outsider, $teamId): void {
            WorkspaceTaskService::queue((string) $outsider['user']['id'], $teamId, []);
        });

        $queue = WorkspaceTaskService::queue((string) $admin['user']['id'], $teamId, []);
        assert_same(0, $queue['total']);
    },

    'owner can assign, unassign and complete a workspace task' => static function (): void {
        $staff = make_ws_staff('ws-queue-actions@example.com');
        $owner = make_user('ws-queue-actions-owner@example.com');
        $teamId = (string) $owner['team']['id'];
        $ownerId = (string) $owner['user']['id'];
        $task = make_ws_task((string) $staff['user']['id'], $teamId, ['title' => 'Actionable']);

        $assigned = WorkspaceTaskService::assignToSelf((string) $task['id'], $ownerId, $teamId);
        assert_same($ownerId, (string) $assigned['assigned_user_id']);
        assert_same('in_progress', (string) $assigned['status']);
        assert_same(1, count_rows('audit_logs', "action = 'review_task.assigned'"));

        $released = WorkspaceTaskService::unassign((string) $task['id'], $ownerId, $teamId);
        assert_null($released['assigned_user_id']);
        assert_same('unassigned', (string) $released['status']);

        $completed = WorkspaceTaskService::complete((string) $task['id'], $ownerId, $teamId);
        assert_same('completed', (string) $completed['status']);
        assert_same($ownerId, (string) $completed['completed_by_user_id']);
        assert_same(1, count_rows('audit_logs', "action = 'review_task.completed'"));
    },

    'refuses tasks from another workspace or platform-only work' => static function (): void {
        $staff = make_ws_staff('ws-queue-guard@example.com');
        $owner = make_user('ws-queue-guard-owner@example.com');
        $other = make_user('ws-queue-guard-other@example.com');
        $teamId = (string) $owner['team']['id'];
        $ownerId = (string) $owner['user']['id'];
        $staffId = (string) $staff['user']['id'];

        $foreignTask = make_ws_task($staffId, (string) $other['team']['id'], ['title' => 'Foreign']);
        $platformTask = make_ws_task($staffId, $teamId, [
            'title' => 'Platform only',
            'work_type' => 'organisation_kyb',
        ]);

        assert_app_error('not_found', static function () use ($foreignTask, $ownerId, $teamId): void {
            WorkspaceTaskService::assignToSelf((string) $foreignTask['id'], $ownerId, $teamId);
        });

        assert_app_error('not_found', static function () use ($platformTask, $ownerId, $teamId): void {
            WorkspaceTaskService::complete((string) $platformTask['id'], $ownerId, $teamId);
        });
    },
];
