<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit;
use App\Config;
use App\Db;
use App\I18n;
use App\Mailer;
use App\Repositories\TeamRepository;
use App\Repositories\UserRepository;
use App\AppError;
use App\Tenancy;
use App\Tokens;
use App\Uuid;

/**
 * Team workspaces: personal workspace creation, invitations, membership changes.
 *
 * Role rules enforced here (PRD F10):
 *  - every active member can read the team, its members and pending invitations;
 *  - admins and owners can invite editors/viewers and revoke invitations;
 *  - only the owner can invite another admin, change roles or remove members;
 *  - the owner's own membership cannot be demoted or deleted.
 */
final class TeamService
{
    private const INVITABLE_ROLES = ['admin', 'editor', 'viewer'];

    /** Create the personal workspace every account starts with. */
    public static function createPersonalTeam(string $userId, string $userName, string $locale): array
    {
        $base = self::slugify($userName);

        $slug = '';
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $candidate = $base . '-' . self::randomSuffix();
            if (!TeamRepository::slugExists($candidate)) {
                $slug = $candidate;
                break;
            }
        }
        if ($slug === '') {
            throw AppError::internal();
        }

        $name = $locale === 'fr' ? 'Espace de ' . $userName : $userName . '’s workspace';

        $team = TeamRepository::create([
            'name' => $name,
            'slug' => $slug,
            'owner_user_id' => $userId,
            'is_personal' => true,
            'plan' => 'free',
        ]);

        TeamRepository::addMember((string) $team['id'], $userId, 'owner');

        return $team;
    }

    /** @return list<array<string, mixed>> */
    public static function members(string $teamId): array
    {
        return TeamRepository::members($teamId);
    }

    /** @return list<array<string, mixed>> */
    public static function pendingInvitations(string $teamId): array
    {
        return TeamRepository::pendingInvitations($teamId);
    }

    /**
     * Create a single-use invitation and email it.
     *
     * @return array{invitation: array<string, mixed>, token: string}
     */
    public static function invite(string $actorUserId, string $teamId, string $email, string $role): array
    {
        $scope = Tenancy::teamForUser($actorUserId, $teamId, 'admin');

        if (!in_array($role, self::INVITABLE_ROLES, true)) {
            throw AppError::invalidRole();
        }
        if ($role === 'admin' && $scope['role'] !== 'owner') {
            // Only an owner may create another admin.
            throw AppError::invalidRole();
        }

        $email = mb_strtolower(trim($email));

        if (TeamRepository::findMemberByEmail($teamId, $email) !== null) {
            throw AppError::conflict('That person is already in this workspace.');
        }
        if (TeamRepository::findLiveInvitation($teamId, $email) !== null) {
            throw AppError::conflict('An invitation is already pending for that email address.');
        }

        $token = Tokens::generate();
        $ttl = (int) Config::get('auth.invitation_ttl');
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttl);

        $invitation = TeamRepository::createInvitation([
            'team_id' => $teamId,
            'email' => $email,
            'role' => $role,
            'token_hash' => Tokens::hash($token),
            'invited_by_user_id' => $actorUserId,
            'expires_at' => $expiresAt,
        ]);

        $inviter = UserRepository::findById($actorUserId);
        $inviterName = $inviter['name'] ?? 'A teammate';
        $inviterLocale = $inviter['locale'] ?? 'en';
        $url = absolute_url('/invite/' . $token);

        Mailer::send(
            $email,
            I18n::forLocale($inviterLocale, 'email.invite_subject', [
                'inviter' => $inviterName,
                'team' => (string) $scope['team']['name'],
            ]),
            I18n::forLocale($inviterLocale, 'email.invite_body', [
                'inviter' => $inviterName,
                'team' => (string) $scope['team']['name'],
                'role' => I18n::forLocale($inviterLocale, 'team.role_' . $role),
                'email' => $email,
                'url' => $url,
            ]),
            'team_invitation',
        );

        Audit::record([
            'team_id' => $teamId,
            'actor_user_id' => $actorUserId,
            'action' => 'team.invitation.created',
            'target_type' => 'team_invitation',
            'target_id' => (string) $invitation['id'],
            'metadata' => ['email' => $email, 'role' => $role],
        ]);

        return ['invitation' => $invitation, 'token' => $token];
    }

    /** Public preview for the invitation landing page. */
    public static function invitationPreview(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $invitation = TeamRepository::findInvitationByTokenHash(Tokens::hash($token));
        if ($invitation === null) {
            return null;
        }

        $team = TeamRepository::find((string) $invitation['team_id']);
        $inviter = $invitation['invited_by_user_id'] !== null
            ? UserRepository::findById((string) $invitation['invited_by_user_id'])
            : null;

        $state = 'valid';
        if ($invitation['revoked_at'] !== null) {
            $state = 'revoked';
        } elseif ($invitation['accepted_at'] !== null) {
            $state = 'used';
        } elseif (strtotime((string) $invitation['expires_at']) <= time()) {
            $state = 'expired';
        }

        return [
            'invitation' => $invitation,
            'team' => $team,
            'inviter_name' => $inviter['name'] ?? null,
            'state' => $state,
        ];
    }

    /**
     * Accept an invitation for the signed-in user. The email must match, so a
     * leaked link cannot be redeemed by a different account.
     *
     * @return array{team_id: string, team_name: string, role: string}
     */
    public static function acceptInvitation(string $userId, string $userEmail, string $token): array
    {
        $invitation = TeamRepository::findInvitationByTokenHash(Tokens::hash($token));
        if ($invitation === null) {
            throw AppError::invalidToken();
        }
        if ($invitation['revoked_at'] !== null || $invitation['accepted_at'] !== null) {
            throw AppError::invalidToken();
        }
        if (strtotime((string) $invitation['expires_at']) <= time()) {
            throw AppError::tokenExpired();
        }
        if (mb_strtolower((string) $invitation['email']) !== mb_strtolower($userEmail)) {
            throw AppError::inviteEmailMismatch();
        }

        $teamId = (string) $invitation['team_id'];
        $team = TeamRepository::find($teamId);
        if ($team === null) {
            throw AppError::invalidToken();
        }

        $role = (string) $invitation['role'];

        Db::transaction(static function () use ($invitation, $userId, $teamId, $role): void {
            $membership = TeamRepository::findMembership($teamId, $userId);

            if ($membership !== null && $membership['status'] === 'active') {
                // Already a member: just burn the invitation.
                TeamRepository::markInvitationAccepted((string) $invitation['id'], $userId);
                return;
            }

            if ($membership !== null) {
                TeamRepository::updateMemberRole((string) $membership['id'], $teamId, $role);
                Db::execute('UPDATE team_members SET status = ? WHERE id = ?', ['active', (string) $membership['id']]);
            } else {
                TeamRepository::addMember(
                    $teamId,
                    $userId,
                    $role,
                    $invitation['invited_by_user_id'] !== null ? (string) $invitation['invited_by_user_id'] : null,
                );
            }

            TeamRepository::markInvitationAccepted((string) $invitation['id'], $userId);

            // Brand-new accounts land in this workspace.
            Db::execute(
                'UPDATE users SET default_team_id = COALESCE(default_team_id, ?) WHERE id = ?',
                [$teamId, $userId],
            );
        });

        Audit::record([
            'team_id' => $teamId,
            'actor_user_id' => $userId,
            'action' => 'team.invitation.accepted',
            'target_type' => 'team_invitation',
            'target_id' => (string) $invitation['id'],
            'metadata' => ['role' => $role],
        ]);

        return ['team_id' => $teamId, 'team_name' => (string) $team['name'], 'role' => $role];
    }

    public static function revokeInvitation(string $actorUserId, string $teamId, string $invitationId): void
    {
        Tenancy::teamForUser($actorUserId, $teamId, 'admin');

        if (!TeamRepository::revokeInvitation($invitationId, $teamId)) {
            throw AppError::notFound('That invitation');
        }

        Audit::record([
            'team_id' => $teamId,
            'actor_user_id' => $actorUserId,
            'action' => 'team.invitation.revoked',
            'target_type' => 'team_invitation',
            'target_id' => $invitationId,
        ]);
    }

    /** Only the owner can change roles; the owner's role is immutable. */
    public static function changeMemberRole(
        string $actorUserId,
        string $teamId,
        string $membershipId,
        string $role,
    ): void {
        $scope = Tenancy::teamForUser($actorUserId, $teamId, 'viewer');
        if ($scope['role'] !== 'owner') {
            throw AppError::forbidden();
        }
        if (!in_array($role, self::INVITABLE_ROLES, true)) {
            throw AppError::invalidRole();
        }

        $member = TeamRepository::findMemberById($membershipId, $teamId);
        if ($member === null) {
            throw AppError::notFound('That member');
        }
        if ($member['role'] === 'owner') {
            throw AppError::lastOwner();
        }
        if ((string) $member['user_id'] === $actorUserId) {
            throw AppError::invalidRole();
        }

        TeamRepository::updateMemberRole($membershipId, $teamId, $role);

        Audit::record([
            'team_id' => $teamId,
            'actor_user_id' => $actorUserId,
            'action' => 'team.member.role_changed',
            'target_type' => 'team_member',
            'target_id' => $membershipId,
            'metadata' => ['from' => (string) $member['role'], 'to' => $role],
        ]);
    }

    /** Only the owner can remove members; the owner cannot be removed. */
    public static function removeMember(string $actorUserId, string $teamId, string $membershipId): void
    {
        $scope = Tenancy::teamForUser($actorUserId, $teamId, 'viewer');
        if ($scope['role'] !== 'owner') {
            throw AppError::forbidden();
        }

        $member = TeamRepository::findMemberById($membershipId, $teamId);
        if ($member === null) {
            throw AppError::notFound('That member');
        }
        if ($member['role'] === 'owner' || (string) $member['user_id'] === $actorUserId) {
            throw AppError::lastOwner();
        }

        TeamRepository::deleteMember($membershipId, $teamId);

        Audit::record([
            'team_id' => $teamId,
            'actor_user_id' => $actorUserId,
            'action' => 'team.member.removed',
            'target_type' => 'team_member',
            'target_id' => $membershipId,
            'metadata' => ['role' => (string) $member['role']],
        ]);
    }

    private static function slugify(string $value): string
    {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = $transliterated !== false ? $transliterated : $value;
        $value = strtolower($value);
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = trim($value, '-');
        $value = mb_substr($value, 0, 40);
        return $value !== '' ? $value : 'workspace';
    }

    private static function randomSuffix(): string
    {
        return substr(strtolower(str_replace(['-', '_'], '', Tokens::generate(4))), 0, 5) ?: substr((string) Uuid::v4(), 0, 5);
    }
}
