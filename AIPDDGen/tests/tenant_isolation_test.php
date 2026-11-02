<?php

declare(strict_types=1);

use App\AppError;

use App\Audit;
use App\Repositories\ProjectRepository;
use App\Services\ProjectService;
use App\Tenancy;

return [
    'keeps projects invisible across workspaces' => static function (): void {
        $alice = make_user('alice@example.com');
        $bob = make_user('bob@example.com');

        $aliceProject = ProjectService::create((string) $alice['user']['id'], (string) $alice['team']['id'], 'Alice project');

        // Bob cannot read it, even with the exact id.
        assert_app_error('not_found', static function () use ($bob, $aliceProject): void {
            ProjectService::get((string) $bob['user']['id'], (string) $aliceProject['id']);
        });
    },

    'keeps workspace listing scoped to membership' => static function (): void {
        $alice = make_user('alice@example.com');
        $bob = make_user('bob@example.com');

        ProjectService::create((string) $alice['user']['id'], (string) $alice['team']['id'], 'Alice project');

        assert_app_error('not_found', static function () use ($bob, $alice): void {
            ProjectService::listForTeam((string) $bob['user']['id'], (string) $alice['team']['id']);
        });

        assert_count(1, ProjectService::listForTeam((string) $alice['user']['id'], (string) $alice['team']['id']));
        assert_count(0, ProjectService::listForTeam((string) $bob['user']['id'], (string) $bob['team']['id']));
    },

    'refuses to create or delete in another workspace' => static function (): void {
        $alice = make_user('alice@example.com');
        $bob = make_user('bob@example.com');
        $aliceProject = ProjectService::create((string) $alice['user']['id'], (string) $alice['team']['id'], 'Alice project');

        assert_app_error('not_found', static function () use ($bob, $alice): void {
            ProjectService::create((string) $bob['user']['id'], (string) $alice['team']['id'], 'Injected project');
        });

        assert_app_error('not_found', static function () use ($bob, $aliceProject): void {
            ProjectService::delete((string) $bob['user']['id'], (string) $aliceProject['id']);
        });

        // Alice's project is untouched.
        $stillThere = ProjectService::get((string) $alice['user']['id'], (string) $aliceProject['id']);
        assert_not_null($stillThere['project']);
    },

    'rejects a random project id' => static function (): void {
        $alice = make_user('alice@example.com');

        assert_app_error('not_found', static function () use ($alice): void {
            ProjectService::get((string) $alice['user']['id'], '00000000-0000-4000-8000-000000000000');
        });
    },

    'filters repository queries by team id' => static function (): void {
        $alice = make_user('alice@example.com');
        $bob = make_user('bob@example.com');

        $aliceProject = ProjectService::create((string) $alice['user']['id'], (string) $alice['team']['id'], 'Alice project');
        ProjectService::create((string) $bob['user']['id'], (string) $bob['team']['id'], 'Bob project');

        assert_count(1, ProjectRepository::listForTeam((string) $alice['team']['id']));
        assert_count(1, ProjectRepository::listForTeam((string) $bob['team']['id']));

        assert_null(ProjectRepository::findForTeam((string) $aliceProject['id'], (string) $bob['team']['id']));
        assert_not_null(ProjectRepository::findForTeam((string) $aliceProject['id'], (string) $alice['team']['id']));
    },

    'refuses cross-tenant team access' => static function (): void {
        $alice = make_user('alice@example.com');
        $bob = make_user('bob@example.com');

        assert_app_error('not_found', static function () use ($bob, $alice): void {
            Tenancy::teamForUser((string) $bob['user']['id'], (string) $alice['team']['id'], 'viewer');
        });
    },

    'keeps audit entries scoped to their workspace' => static function (): void {
        $alice = make_user('alice@example.com');
        $bob = make_user('bob@example.com');

        ProjectService::create((string) $alice['user']['id'], (string) $alice['team']['id'], 'Alice project');
        ProjectService::create((string) $bob['user']['id'], (string) $bob['team']['id'], 'Bob project');

        $aliceAudit = Audit::forTeam((string) $alice['team']['id']);
        $bobAudit = Audit::forTeam((string) $bob['team']['id']);

        foreach ($aliceAudit as $entry) {
            assert_same((string) $alice['team']['id'], (string) $entry['team_id']);
        }
        foreach ($bobAudit as $entry) {
            assert_same((string) $bob['team']['id'], (string) $entry['team_id']);
        }

        $aliceActions = array_column($aliceAudit, 'action');
        assert_true(in_array('project.created', $aliceActions, true));
    },

    'returns not found rather than forbidden across tenants' => static function (): void {
        $alice = make_user('alice@example.com');
        $bob = make_user('bob@example.com');
        $aliceProject = ProjectService::create((string) $alice['user']['id'], (string) $alice['team']['id'], 'Alice project');

        try {
            ProjectService::get((string) $bob['user']['id'], (string) $aliceProject['id']);
            throw new RuntimeException('Expected an error');
        } catch (AppError $error) {
            // 404, not 403: a probe must not learn that the id exists.
            assert_same('not_found', $error->errorCode);
            assert_same(404, $error->status);
        }
    },
];
