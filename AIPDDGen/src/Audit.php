<?php

declare(strict_types=1);

namespace App;

/**
 * Append-only audit trail (PRD F10). Written for every security-relevant
 * action: sign-up, sign-in, failed sign-in, role changes, invitations, project
 * creation.
 */
final class Audit
{
    /** @param array<string, mixed> $input */
    public static function record(array $input): void
    {
        Db::insert('audit_logs', [
            'id' => Uuid::v4(),
            'team_id' => $input['team_id'] ?? null,
            'actor_user_id' => $input['actor_user_id'] ?? null,
            'action' => (string) $input['action'],
            'target_type' => $input['target_type'] ?? null,
            'target_id' => isset($input['target_id']) ? (string) $input['target_id'] : null,
            'metadata' => $input['metadata'] ?? null,
            'ip_address' => $input['ip_address'] ?? Http::ip(),
            'user_agent' => $input['user_agent'] ?? Http::userAgent(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public static function forTeam(string $teamId, int $limit = 100): array
    {
        return Db::select(
            'SELECT * FROM audit_logs WHERE team_id = ? ORDER BY created_at DESC LIMIT ' . max(1, min($limit, 500)),
            [$teamId],
        );
    }
}
