<?php

declare(strict_types=1);

use App\Db;
use App\Repositories\TeamRepository;
use App\Uuid;

return [
    'creates every expected table' => static function (): void {
        // `template_fields` arrives with migration 0002 (step 2, admin template
        // library); `organization_*` with 0003 (organization onboarding);
        // `review_tasks` with 0004 (staff Action Center). The runner applies every
        // file in migrations/ to the test DB, so this list tracks the whole set.
        $rows = Db::select(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()",
        );
        $tables = array_map(static fn (array $row): string => (string) $row['table_name'], $rows);

        $expected = [
            'users', 'accounts', 'verification_tokens', 'email_outbox', 'rate_limit_counters',
            'teams', 'team_members', 'team_invitations', 'methodologies',
            'templates', 'template_versions', 'template_sections', 'template_fields',
            'organization_profiles', 'organization_documents', 'review_tasks',
            'projects', 'inputs', 'facts', 'fact_sheet_versions',
            'drafts', 'draft_sections', 'draft_versions', 'comments',
            'gap_reports', 'gap_items', 'audit_logs', 'schema_migrations',
        ];

        foreach ($expected as $table) {
            assert_true(in_array($table, $tables, true), "missing table: {$table}");
        }
        assert_same(count($expected), count($tables));
    },

    'enforces unique emails case-insensitively' => static function (): void {
        $user = make_user('unique@example.com');

        try {
            Db::insert('users', [
                'id' => Uuid::v4(),
                'email' => 'UNIQUE@example.com',
                'name' => 'Duplicate',
                'locale' => 'en',
            ]);
            throw new RuntimeException('Expected a duplicate-key error');
        } catch (PDOException $error) {
            assert_same('23000', (string) $error->getCode());
        }

        assert_same(1, count_rows('users', 'id = ?', [$user['user']['id']]));
    },

    'allows at most one owner per team' => static function (): void {
        $owner = make_user('owner@example.com');
        $other = make_user('second@example.com');

        try {
            Db::insert('team_members', [
                'id' => Uuid::v4(),
                'team_id' => (string) $owner['team']['id'],
                'user_id' => (string) $other['user']['id'],
                'role' => 'owner',
            ]);
            throw new RuntimeException('Expected a duplicate-owner error');
        } catch (PDOException $error) {
            assert_same('23000', (string) $error->getCode());
        }
    },

    'allows only one live invitation per email and team' => static function (): void {
        $owner = make_user('owner@example.com');
        $teamId = (string) $owner['team']['id'];

        // Create one through the real service, then try a duplicate.
        \App\Services\TeamService::invite((string) $owner['user']['id'], $teamId, 'invitee@example.com', 'editor');
        assert_same(1, count_rows('team_invitations', 'team_id = ?', [$teamId]));

        try {
            Db::insert('team_invitations', [
                'id' => Uuid::v4(),
                'team_id' => $teamId,
                'email' => 'Invitee@Example.com',
                'role' => 'editor',
                'token_hash' => hash('sha256', 'another-token'),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
            ]);
            throw new RuntimeException('Expected a duplicate-invitation error');
        } catch (PDOException $error) {
            assert_same('23000', (string) $error->getCode());
        }
    },

    'deletes memberships and invitations with the team' => static function (): void {
        $owner = make_user('cascade@example.com');
        $teamId = (string) $owner['team']['id'];

        \App\Services\TeamService::invite((string) $owner['user']['id'], $teamId, 'cascade-invitee@example.com', 'viewer');

        Db::execute('DELETE FROM teams WHERE id = ?', [$teamId]);

        assert_same(0, count_rows('team_members', 'team_id = ?', [$teamId]));
        assert_same(0, count_rows('team_invitations', 'team_id = ?', [$teamId]));
    },

    'keeps database and application time zones aligned' => static function (): void {
        $row = Db::selectOne('SELECT NOW(3) AS now');
        $databaseNow = strtotime((string) $row['now']);
        assert_true(abs($databaseNow - time()) < 120, 'database NOW() must be within two minutes of PHP time()');
    },

    'has the single-owner index in place' => static function (): void {
        $rows = Db::select(
            "SELECT index_name FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'team_members'",
        );
        $indexes = array_map(static fn (array $row): string => (string) $row['index_name'], $rows);
        assert_true(in_array('uq_team_members_single_owner', $indexes, true));
    },
];
