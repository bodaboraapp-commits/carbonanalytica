<?php

declare(strict_types=1);

use App\Db;
use App\Repositories\TeamRepository;
use App\Services\TeamService;

return [
    'creates an invitation, queues the email and audits it' => static function (): void {
        $owner = make_user('owner@example.com');
        $teamId = (string) $owner['team']['id'];

        $result = TeamService::invite((string) $owner['user']['id'], $teamId, 'invitee@example.com', 'editor');

        assert_not_null($result['invitation']['id']);
        assert_same('editor', $result['invitation']['role']);
        assert_same(1, count_rows('team_invitations', 'team_id = ?', [$teamId]));
        assert_same(1, count_rows('audit_logs', "action = 'team.invitation.created'"));

        $email = latest_email('invitee@example.com', 'team_invitation');
        assert_not_null($email);
        assert_contains('/invite/', (string) $email['body_text']);
    },

    'accepts an invitation into the invited workspace' => static function (): void {
        $owner = make_user('owner@example.com');
        $teamId = (string) $owner['team']['id'];
        TeamService::invite((string) $owner['user']['id'], $teamId, 'joined@example.com', 'editor');

        $email = latest_email('joined@example.com', 'team_invitation');
        $token = invitation_token_from_email((string) $email['body_text']);
        assert_not_null($token);

        $invitee = make_user('joined@example.com');
        $result = TeamService::acceptInvitation((string) $invitee['user']['id'], 'joined@example.com', $token);

        assert_same($teamId, $result['team_id']);
        assert_same('editor', $result['role']);

        $membership = TeamRepository::findMembership($teamId, (string) $invitee['user']['id']);
        assert_not_null($membership);
        assert_same('editor', $membership['role']);

        assert_same(2, count_rows('team_members', 'team_id = ?', [$teamId]));
        assert_same(1, count_rows('audit_logs', "action = 'team.invitation.accepted'"));

        $invitationRow = Db::selectOne(
            'SELECT * FROM team_invitations WHERE team_id = ? AND email = ? LIMIT 1',
            [$teamId, 'joined@example.com'],
        );
        assert_not_null($invitationRow['accepted_at']);
    },

    'refuses an invitation sent to a different email address' => static function (): void {
        $owner = make_user('owner@example.com');
        $teamId = (string) $owner['team']['id'];
        TeamService::invite((string) $owner['user']['id'], $teamId, 'intended@example.com', 'editor');

        $email = latest_email('intended@example.com', 'team_invitation');
        $token = invitation_token_from_email((string) $email['body_text']);

        $other = make_user('other@example.com');

        assert_app_error('invite_email_mismatch', static function () use ($other, $token): void {
            TeamService::acceptInvitation((string) $other['user']['id'], 'other@example.com', (string) $token);
        });
    },

    'refuses a revoked invitation' => static function (): void {
        $owner = make_user('owner@example.com');
        $teamId = (string) $owner['team']['id'];
        $result = TeamService::invite((string) $owner['user']['id'], $teamId, 'revoked@example.com', 'viewer');

        TeamService::revokeInvitation((string) $owner['user']['id'], $teamId, (string) $result['invitation']['id']);

        $invitee = make_user('revoked@example.com');

        assert_app_error('invalid_token', static function () use ($invitee, $result): void {
            TeamService::acceptInvitation((string) $invitee['user']['id'], 'revoked@example.com', $result['token']);
        });
    },

    'refuses an expired invitation' => static function (): void {
        $owner = make_user('owner@example.com');
        $teamId = (string) $owner['team']['id'];
        $result = TeamService::invite((string) $owner['user']['id'], $teamId, 'expired@example.com', 'viewer');

        Db::execute(
            'UPDATE team_invitations SET expires_at = ? WHERE id = ?',
            [gmdate('Y-m-d H:i:s', time() - 60), (string) $result['invitation']['id']],
        );

        $invitee = make_user('expired@example.com');

        assert_app_error('token_expired', static function () use ($invitee, $result): void {
            TeamService::acceptInvitation((string) $invitee['user']['id'], 'expired@example.com', $result['token']);
        });
    },

    'refuses a second live invitation for the same email' => static function (): void {
        $owner = make_user('owner@example.com');
        $teamId = (string) $owner['team']['id'];

        TeamService::invite((string) $owner['user']['id'], $teamId, 'twice@example.com', 'editor');

        assert_app_error('conflict', static function () use ($owner, $teamId): void {
            TeamService::invite((string) $owner['user']['id'], $teamId, 'twice@example.com', 'viewer');
        });
    },

    'refuses to invite an existing member' => static function (): void {
        $owner = make_user('owner@example.com');
        $teamId = (string) $owner['team']['id'];
        $member = add_team_member($teamId, (string) $owner['user']['id'], 'member@example.com', 'editor');

        assert_app_error('conflict', static function () use ($owner, $teamId): void {
            TeamService::invite((string) $owner['user']['id'], $teamId, 'member@example.com', 'viewer');
        });
    },

    'lets an admin invite editors but not another admin' => static function (): void {
        $owner = make_user('owner@example.com');
        $teamId = (string) $owner['team']['id'];
        $admin = add_team_member($teamId, (string) $owner['user']['id'], 'admin@example.com', 'admin');

        $invited = TeamService::invite((string) $admin['user']['id'], $teamId, 'byadmin@example.com', 'editor');
        assert_same('editor', $invited['invitation']['role']);

        assert_app_error('invalid_role', static function () use ($admin, $teamId): void {
            TeamService::invite((string) $admin['user']['id'], $teamId, 'anotheradmin@example.com', 'admin');
        });
    },

    'stops an editor from inviting anyone' => static function (): void {
        $owner = make_user('owner@example.com');
        $teamId = (string) $owner['team']['id'];
        $editor = add_team_member($teamId, (string) $owner['user']['id'], 'editor@example.com', 'editor');

        assert_app_error('forbidden', static function () use ($editor, $teamId): void {
            TeamService::invite((string) $editor['user']['id'], $teamId, 'nope@example.com', 'viewer');
        });
    },
];
