<?php

declare(strict_types=1);

namespace App\Services;

use App\AppError;
use App\Audit;
use App\Db;
use App\I18n;
use App\Mailer;
use App\Repositories\OrganizationRepository;
use App\Repositories\ReviewTaskRepository;

/**
 * Platform-side KYC decisions on submitted organisation profiles.
 *
 * One decision = one transaction: mark the profile verified/rejected, close its
 * open organisation_kyb review task, and audit the decision. The outcome email
 * is sent AFTER the transaction commits: a broken mailer must never roll back a
 * recorded decision (Mailer still stores the failed attempt in email_outbox).
 *
 * Staff-only. Controller routes are admin-guarded; this service re-asserts the
 * platform-admin flag as defence in depth. The customer workspace can never call
 * these paths (tenant accounts get a 404 from the staff guard).
 */
final class OrganizationReviewService
{
    /** Maximum characters for an approval note or a rejection reason. */
    public const NOTE_MAX = 500;

    /**
     * Approve a submitted organisation profile.
     *
     * @return array{profile: array<string, mixed>, email: array{ok: bool, error: string|null}|null}
     */
    public static function verify(string $reviewerId, string $profileId, string $note = ''): array
    {
        return self::decide($reviewerId, $profileId, 'verified', $note);
    }

    /**
     * Reject a submitted organisation profile: a reason is required so the
     * workspace knows what to fix before resubmitting.
     *
     * @return array{profile: array<string, mixed>, email: array{ok: bool, error: string|null}|null}
     */
    public static function reject(string $reviewerId, string $profileId, string $reason): array
    {
        return self::decide($reviewerId, $profileId, 'rejected', $reason);
    }

    /**
     * @param 'verified'|'rejected' $decision
     * @return array{profile: array<string, mixed>, email: array{ok: bool, error: string|null}|null}
     */
    private static function decide(string $reviewerId, string $profileId, string $decision, string $note): array
    {
        TaskCenterService::assertStaff($reviewerId);

        $profile = OrganizationRepository::findProfileById($profileId);
        if ($profile === null) {
            throw AppError::notFound('That organisation profile');
        }
        if ((string) $profile['status'] !== 'submitted') {
            throw AppError::conflict('That organisation profile is no longer waiting for review.');
        }

        $note = trim($note);
        if (mb_strlen($note) > self::NOTE_MAX) {
            throw AppError::validation(['review_note' => 'too_long']);
        }
        if ($decision === 'rejected' && $note === '') {
            throw AppError::validation(['review_note' => 'required']);
        }

        $teamId = (string) $profile['team_id'];
        $closedTaskIds = [];
        $action = $decision === 'verified' ? 'organization.verified' : 'organization.rejected';

        Db::transaction(static function () use ($reviewerId, $profileId, $decision, $note, $teamId, &$closedTaskIds, $action): void {
            // Guarded update: only a profile still waiting for review changes.
            $changed = OrganizationRepository::markReviewed($profileId, $decision, $note === '' ? null : $note);
            if (!$changed) {
                throw AppError::conflict('That organisation profile is no longer waiting for review.');
            }

            $closedTaskIds = ReviewTaskRepository::closeOpenForTarget('organization_profile', $profileId, $reviewerId);
            foreach ($closedTaskIds as $taskId) {
                Audit::record([
                    'team_id' => $teamId,
                    'actor_user_id' => $reviewerId,
                    'action' => 'review_task.completed',
                    'target_type' => 'review_task',
                    'target_id' => $taskId,
                    'metadata' => ['work_type' => 'organisation_kyb', 'by' => 'kyc_decision'],
                ]);
            }

            Audit::record([
                'team_id' => $teamId,
                'actor_user_id' => $reviewerId,
                'action' => $action,
                'target_type' => 'organization_profile',
                'target_id' => $profileId,
                'metadata' => [
                    'note_length' => mb_strlen($note),
                    'tasks_closed' => count($closedTaskIds),
                ],
            ]);
        });

        // Post-commit: never let email delivery affect the recorded decision.
        $email = self::emailOutcome($profileId, $decision, $note);

        $updated = OrganizationRepository::findProfileById($profileId);
        if ($updated === null) {
            throw AppError::internal();
        }

        return ['profile' => $updated, 'email' => $email];
    }

    /**
     * Send the outcome to the profile's primary contact, in the workspace
     * owner's locale. Returns null when there is no contact email or when the
     * transport fails after the decision was committed.
     *
     * @param 'verified'|'rejected' $decision
     * @return array{ok: bool, error: string|null}|null
     */
    private static function emailOutcome(string $profileId, string $decision, string $note): ?array
    {
        try {
            $context = Db::selectOne(
                "SELECT op.legal_name, op.contact_name, op.contact_email, op.team_id, t.name AS team_name
                 FROM organization_profiles op
                 INNER JOIN teams t ON t.id = op.team_id
                 WHERE op.id = ? LIMIT 1",
                [$profileId],
            );
            if ($context === null) {
                return null;
            }

            $to = trim((string) ($context['contact_email'] ?? ''));
            if ($to === '') {
                return null;
            }

            $owner = Db::selectOne(
                "SELECT u.locale FROM team_members tm
                 INNER JOIN users u ON u.id = tm.user_id
                 WHERE tm.team_id = ? AND tm.role = 'owner' AND tm.status = 'active'
                 ORDER BY tm.created_at ASC LIMIT 1",
                [(string) $context['team_id']],
            );
            $locale = (string) ($owner['locale'] ?? 'en');
            $locale = in_array($locale, ['en', 'fr'], true) ? $locale : 'en';

            $verified = $decision === 'verified';
            $legalName = (string) ($context['legal_name'] ?? '');
            $contactName = trim((string) ($context['contact_name'] ?? ''));

            $subject = I18n::forLocale(
                $locale,
                $verified ? 'email.kyc_verified_subject' : 'email.kyc_rejected_subject',
                ['org' => $legalName],
            );
            $body = I18n::forLocale(
                $locale,
                $verified ? 'email.kyc_verified_body' : 'email.kyc_rejected_body',
                [
                    'name' => $contactName !== '' ? $contactName : $legalName,
                    'org' => $legalName,
                    'workspace' => (string) ($context['team_name'] ?? ''),
                    'note' => $note,
                    'url' => absolute_url('/onboarding'),
                ],
            );

            return Mailer::send($to, $subject, $body, $verified ? 'kyc_verified' : 'kyc_rejected');
        } catch (\Throwable $error) {
            // Mailer records its own failures in email_outbox; this catch is the
            // last line of defence so a decision is never lost to an email error.
            error_log('[pdd] KYC outcome email failed: ' . $error->getMessage());
            return null;
        }
    }
}
