<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Db;
use App\Uuid;

/**
 * Team, membership and invitation rows. Authorisation decisions belong to
 * Services\TeamService; SQL here is always scoped by team id.
 */
final class TeamRepository
{
    /** @param array<string, mixed> $data */
    public static function create(array $data): array
    {
        $id = Uuid::v4();
        Db::insert('teams', [
            'id' => $id,
            'name' => $data['name'],
            'slug' => $data['slug'],
            'owner_user_id' => $data['owner_user_id'],
            'is_personal' => $data['is_personal'] ?? false,
            'plan' => $data['plan'] ?? 'free',
            'data_region' => $data['data_region'] ?? 'auto',
            'settings' => $data['settings'] ?? null,
        ]);

        $team = self::find($id);
        if ($team === null) {
            throw new \RuntimeException('Failed to create team');
        }
        return $team;
    }

    public static function find(string $teamId): ?array
    {
        return Db::selectOne('SELECT * FROM teams WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$teamId]);
    }

    public static function slugExists(string $slug): bool
    {
        return Db::selectOne('SELECT id FROM teams WHERE slug = ? LIMIT 1', [$slug]) !== null;
    }

    public static function addMember(
        string $teamId,
        string $userId,
        string $role,
        ?string $invitedByUserId = null,
        string $status = 'active',
    ): string {
        $id = Uuid::v4();
        Db::insert('team_members', [
            'id' => $id,
            'team_id' => $teamId,
            'user_id' => $userId,
            'role' => $role,
            'status' => $status,
            'invited_by_user_id' => $invitedByUserId,
        ]);
        return $id;
    }

    public static function findMembership(string $teamId, string $userId): ?array
    {
        return Db::selectOne(
            'SELECT * FROM team_members WHERE team_id = ? AND user_id = ? LIMIT 1',
            [$teamId, $userId],
        );
    }

    /** @return list<array<string, mixed>> */
    public static function members(string $teamId): array
    {
        return Db::select(
            "SELECT tm.id, tm.user_id, tm.role, tm.status, tm.joined_at, u.name, u.email
             FROM team_members tm
             INNER JOIN users u ON u.id = tm.user_id
             WHERE tm.team_id = ? AND u.deleted_at IS NULL
             ORDER BY FIELD(tm.role, 'owner', 'admin', 'editor', 'viewer'), u.name ASC",
            [$teamId],
        );
    }

    public static function countActiveMembers(string $teamId): int
    {
        $row = Db::selectOne(
            'SELECT COUNT(*) AS total FROM team_members WHERE team_id = ? AND status = ?',
            [$teamId, 'active'],
        );
        return (int) ($row['total'] ?? 0);
    }

    /** @param array<string, mixed> $data */
    public static function createInvitation(array $data): array
    {
        $id = Uuid::v4();
        Db::insert('team_invitations', [
            'id' => $id,
            'team_id' => $data['team_id'],
            'email' => $data['email'],
            'role' => $data['role'],
            'token_hash' => $data['token_hash'],
            'invited_by_user_id' => $data['invited_by_user_id'] ?? null,
            'expires_at' => $data['expires_at'],
        ]);

        $invitation = self::findInvitation($id);
        if ($invitation === null) {
            throw new \RuntimeException('Failed to create invitation');
        }
        return $invitation;
    }

    public static function findInvitation(string $id): ?array
    {
        return Db::selectOne('SELECT * FROM team_invitations WHERE id = ? LIMIT 1', [$id]);
    }

    public static function findInvitationByTokenHash(string $tokenHash): ?array
    {
        return Db::selectOne('SELECT * FROM team_invitations WHERE token_hash = ? LIMIT 1', [$tokenHash]);
    }

    public static function findLiveInvitation(string $teamId, string $email): ?array
    {
        return Db::selectOne(
            'SELECT * FROM team_invitations
             WHERE team_id = ? AND live_email = ? AND accepted_at IS NULL AND revoked_at IS NULL
             LIMIT 1',
            [$teamId, mb_strtolower(trim($email))],
        );
    }

    /** @return list<array<string, mixed>> */
    public static function pendingInvitations(string $teamId): array
    {
        return Db::select(
            "SELECT ti.*, u.name AS invited_by_name
             FROM team_invitations ti
             LEFT JOIN users u ON u.id = ti.invited_by_user_id
             WHERE ti.team_id = ? AND ti.accepted_at IS NULL AND ti.revoked_at IS NULL AND ti.expires_at > NOW(3)
             ORDER BY ti.created_at DESC",
            [$teamId],
        );
    }

    public static function revokeInvitation(string $invitationId, string $teamId): bool
    {
        return Db::execute(
            'UPDATE team_invitations SET revoked_at = NOW(3)
             WHERE id = ? AND team_id = ? AND accepted_at IS NULL AND revoked_at IS NULL',
            [$invitationId, $teamId],
        ) > 0;
    }

    public static function markInvitationAccepted(string $invitationId, string $userId): void
    {
        Db::execute(
            'UPDATE team_invitations SET accepted_at = NOW(3), accepted_by_user_id = ? WHERE id = ?',
            [$userId, $invitationId],
        );
    }

    public static function updateMemberRole(string $membershipId, string $teamId, string $role): bool
    {
        return Db::execute(
            'UPDATE team_members SET role = ? WHERE id = ? AND team_id = ?',
            [$role, $membershipId, $teamId],
        ) > 0;
    }

    public static function deleteMember(string $membershipId, string $teamId): bool
    {
        return Db::execute('DELETE FROM team_members WHERE id = ? AND team_id = ?', [$membershipId, $teamId]) > 0;
    }

    public static function findMemberById(string $membershipId, string $teamId): ?array
    {
        return Db::selectOne(
            'SELECT * FROM team_members WHERE id = ? AND team_id = ? LIMIT 1',
            [$membershipId, $teamId],
        );
    }

    public static function findMemberByEmail(string $teamId, string $email): ?array
    {
        return Db::selectOne(
            'SELECT tm.* FROM team_members tm
             INNER JOIN users u ON u.id = tm.user_id
             WHERE tm.team_id = ? AND u.email_lower = ? AND u.deleted_at IS NULL
             LIMIT 1',
            [$teamId, mb_strtolower(trim($email))],
        );
    }
}
