<?php

declare(strict_types=1);

use App\Db;
use App\Repositories\ReviewTaskRepository;
use App\Repositories\UserRepository;
use App\Services\TaskCenterService;
use App\Uuid;

/**
 * Create a platform-admin (staff) account. The flag is separate from tenant
 * roles: staff can review work without belonging to the customer workspace.
 *
 * @return array{user: array<string, mixed>, team: array<string, mixed>, password: string}
 */
function make_staff(string $email = 'staff@example.com'): array
{
    $account = make_user($email, 'Staff Reviewer');
    Db::execute('UPDATE users SET is_platform_admin = 1 WHERE id = ?', [(string) $account['user']['id']]);
    $account['user'] = UserRepository::findById((string) $account['user']['id']);
    return $account;
}

/** @return array<string, mixed> a task created for the demo workspace */
function make_task(string $actorId, string $teamId, array $overrides = []): array
{
    return TaskCenterService::createTask($actorId, array_merge([
        'work_type' => 'project_validation',
        'title' => 'Review something',
        'priority' => 'normal',
        'team_id' => $teamId,
    ], $overrides));
}

return [
    'hides the console from non-staff accounts' => static function (): void {
        $account = make_user('tenant@example.com');

        assert_app_error('not_found', static function () use ($account): void {
            TaskCenterService::dashboard((string) $account['user']['id']);
        });

        assert_app_error('not_found', static function () use ($account): void {
            TaskCenterService::queue((string) $account['user']['id'], []);
        });
    },

    'creates tasks and reports the action counters' => static function (): void {
        $staff = make_staff('counts@example.com');
        $teamId = (string) $staff['team']['id'];
        $staffId = (string) $staff['user']['id'];

        make_task($staffId, $teamId, [
            'title' => 'Mine, due soon',
            'assigned_user_id' => $staffId,
            'due_at' => gmdate('Y-m-d H:i:s', time() + 2 * 86400),
        ]);
        make_task($staffId, $teamId, [
            'title' => 'Nobody owns this',
            'due_at' => gmdate('Y-m-d H:i:s', time() + 3 * 86400),
        ]);
        make_task($staffId, $teamId, [
            'title' => 'Overdue and assigned',
            'assigned_user_id' => $staffId,
            'due_at' => gmdate('Y-m-d H:i:s', time() - 2 * 86400),
        ]);

        $summary = TaskCenterService::dashboard($staffId)['summary'];

        assert_same(3, $summary['open_total']);
        assert_same(2, $summary['assigned_to_me']);
        assert_same(1, $summary['unassigned']);
        assert_same(1, $summary['breached']);
    },

    'groups open work by type' => static function (): void {
        $staff = make_staff('groups@example.com');
        $teamId = (string) $staff['team']['id'];

        make_task((string) $staff['user']['id'], $teamId, ['work_type' => 'organisation_kyb', 'title' => 'KYB 1']);
        make_task((string) $staff['user']['id'], $teamId, ['work_type' => 'organisation_kyb', 'title' => 'KYB 2']);
        make_task((string) $staff['user']['id'], $teamId, ['work_type' => 'template_review', 'title' => 'Template QA']);

        $byType = TaskCenterService::dashboard((string) $staff['user']['id'])['by_work_type'];
        $counts = [];
        foreach ($byType as $row) {
            $counts[$row['work_type']] = $row['total'];
        }

        assert_same(2, $counts['organisation_kyb'] ?? 0);
        assert_same(1, $counts['template_review'] ?? 0);
    },

    'assigns a task to the current staff member' => static function (): void {
        $staff = make_staff('assign@example.com');
        $staffId = (string) $staff['user']['id'];
        $task = make_task($staffId, (string) $staff['team']['id']);

        $assigned = TaskCenterService::assignToSelf((string) $task['id'], $staffId);

        assert_same($staffId, (string) $assigned['assigned_user_id']);
        assert_same('in_progress', (string) $assigned['status']);
        assert_same(1, count_rows('audit_logs', "action = 'review_task.assigned'"));
    },

    'refuses to steal a task assigned to someone else' => static function (): void {
        $first = make_staff('first-staff@example.com');
        $second = make_staff('second-staff@example.com');
        $task = make_task((string) $first['user']['id'], (string) $first['team']['id'], [
            'title' => 'Owned task',
            'assigned_user_id' => (string) $first['user']['id'],
        ]);

        assert_app_error('conflict', static function () use ($task, $second): void {
            TaskCenterService::assignToSelf((string) $task['id'], (string) $second['user']['id']);
        });
    },

    'unassigns a task and returns it to the queue' => static function (): void {
        $staff = make_staff('unassign@example.com');
        $staffId = (string) $staff['user']['id'];
        $task = make_task($staffId, (string) $staff['team']['id']);

        TaskCenterService::assignToSelf((string) $task['id'], $staffId);
        $released = TaskCenterService::unassign((string) $task['id'], $staffId);

        assert_null($released['assigned_user_id']);
        assert_same('unassigned', (string) $released['status']);
        assert_same(1, count_rows('audit_logs', "action = 'review_task.unassigned'"));
    },

    'completes a task and clears the SLA breach' => static function (): void {
        $staff = make_staff('complete@example.com');
        $staffId = (string) $staff['user']['id'];
        $task = make_task($staffId, (string) $staff['team']['id'], [
            'title' => 'Overdue task',
            'assigned_user_id' => $staffId,
            'due_at' => gmdate('Y-m-d H:i:s', time() - 3 * 86400),
        ]);

        $completed = TaskCenterService::complete((string) $task['id'], $staffId);

        assert_same('completed', (string) $completed['status']);
        assert_not_null($completed['completed_at']);
        assert_same($staffId, (string) $completed['completed_by_user_id']);

        $summary = TaskCenterService::dashboard($staffId)['summary'];
        assert_same(0, $summary['breached']);
        assert_same(1, $summary['completed_by_me']);
        assert_same(1, count_rows('audit_logs', "action = 'review_task.completed'"));
    },

    'refuses to complete a closed task' => static function (): void {
        $staff = make_staff('closed@example.com');
        $staffId = (string) $staff['user']['id'];
        $task = make_task($staffId, (string) $staff['team']['id']);

        TaskCenterService::complete((string) $task['id'], $staffId);

        assert_app_error('conflict', static function () use ($task, $staffId): void {
            TaskCenterService::complete((string) $task['id'], $staffId);
        });
    },

    'creates one system task per submitted organisation profile' => static function (): void {
        $staff = make_staff('sync@example.com');
        $owner = make_user('org-owner@example.com');
        $teamId = (string) $owner['team']['id'];

        Db::insert('organization_profiles', [
            'id' => Uuid::v4(),
            'team_id' => $teamId,
            'legal_name' => 'Acme Carbon Ltd',
            'status' => 'submitted',
            'submitted_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $first = TaskCenterService::syncSystemTasks((string) $staff['user']['id']);
        $second = TaskCenterService::syncSystemTasks((string) $staff['user']['id']);

        assert_same(1, $first, 'first sync should create the KBY task');
        assert_same(0, $second, 'second sync must not duplicate it');
        assert_same(1, count_rows('review_tasks', "target_type = 'organization_profile'"));
    },

    'filters the queue by scope and work type' => static function (): void {
        $staff = make_staff('filters@example.com');
        $staffId = (string) $staff['user']['id'];
        $teamId = (string) $staff['team']['id'];

        make_task($staffId, $teamId, [
            'title' => 'Mine',
            'assigned_user_id' => $staffId,
            'due_at' => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);
        make_task($staffId, $teamId, [
            'title' => 'Unassigned',
            'due_at' => gmdate('Y-m-d H:i:s', time() + 2 * 86400),
        ]);
        make_task($staffId, $teamId, [
            'title' => 'Breached',
            'work_type' => 'template_review',
            'due_at' => gmdate('Y-m-d H:i:s', time() - 86400),
        ]);

        $mine = TaskCenterService::queue($staffId, ['scope' => 'mine']);
        assert_count(1, $mine['rows']);
        assert_same('Mine', (string) $mine['rows'][0]['title']);

        $unassigned = TaskCenterService::queue($staffId, ['scope' => 'unassigned']);
        // Both the plain unassigned task and the overdue one are unassigned.
        assert_count(2, $unassigned['rows']);
        assert_true(in_array('Unassigned', array_column($unassigned['rows'], 'title'), true));

        $breached = TaskCenterService::queue($staffId, ['scope' => 'breached']);
        assert_count(1, $breached['rows']);
        assert_same('Breached', (string) $breached['rows'][0]['title']);

        $templates = TaskCenterService::queue($staffId, ['scope' => 'all', 'work_type' => 'template_review']);
        assert_count(1, $templates['rows']);

        $search = TaskCenterService::queue($staffId, ['scope' => 'all', 'q' => 'Unassigned']);
        assert_count(1, $search['rows']);
    },

    'rejects an unknown work type' => static function (): void {
        $staff = make_staff('badtype@example.com');

        assert_app_error('validation_error', static function () use ($staff): void {
            TaskCenterService::createTask((string) $staff['user']['id'], [
                'work_type' => 'not_a_real_type',
                'title' => 'Invalid',
            ]);
        });
    },

    'reports platform metrics for the dashboard tiles' => static function (): void {
        $staff = make_staff('metrics@example.com');
        $metrics = TaskCenterService::dashboard((string) $staff['user']['id'])['metrics'];

        foreach (['workspaces', 'projects', 'people', 'drafts', 'drafts_in_review', 'templates_published', 'open_tasks'] as $key) {
            assert_true(array_key_exists($key, $metrics), "missing metric: {$key}");
            assert_true(is_int($metrics[$key]) && $metrics[$key] >= 0, "metric {$key} must be a non-negative integer");
        }

        assert_true($metrics['people'] >= 1);
        assert_true($metrics['workspaces'] >= 1);
    },

    'records the workspace on the task for traceability' => static function (): void {
        $staff = make_staff('trace@example.com');
        $owner = make_user('traced-owner@example.com');
        $task = make_task((string) $staff['user']['id'], (string) $owner['team']['id'], ['title' => 'Traced task']);

        assert_same((string) $owner['team']['id'], (string) $task['team_id']);
        assert_not_null($task['team_name']);
    },

    'repository filters by assignee and overdue state' => static function (): void {
        $staff = make_staff('repo@example.com');
        $staffId = (string) $staff['user']['id'];
        $teamId = (string) $staff['team']['id'];

        make_task($staffId, $teamId, [
            'title' => 'Repo mine',
            'assigned_user_id' => $staffId,
            'due_at' => gmdate('Y-m-d H:i:s', time() - 86400),
        ]);

        $page = ReviewTaskRepository::paginate($staffId, ['scope' => 'breached']);
        assert_same(1, $page['total']);
        assert_same('Repo mine', (string) $page['rows'][0]['title']);
    },
];
