<?php

declare(strict_types=1);

use App\Db;
use App\Repositories\OrganizationRepository;
use App\Repositories\ReviewTaskRepository;
use App\Services\OnboardingService;
use App\Services\OrganizationReviewService;
use App\Services\PlatformConsoleService;
use App\Services\TaskCenterService;
use App\View;

/**
 * KYC review workflow: tenant submit → staff verify/reject on /admin/kyc →
 * outcome recorded, review task closed, contact emailed, tenant sees the result
 * and can resubmit after a rejection.
 */

/** @return array<string, string> complete step-1 payload */
function kyc_org_details(array $overrides = []): array
{
    return array_merge([
        'legal_name' => 'Lake Victoria Carbon Ltd',
        'trading_name' => 'LVC',
        'registration_number' => 'PVT-2025-00123',
        'registration_country' => 'Kenya',
        'organization_type' => 'developer',
        'year_founded' => '2021',
        'website' => 'https://example.org',
        'registered_address' => '4 Oginga Odinga Street',
        'city' => 'Kisumu',
    ], $overrides);
}

/** @return array<string, string> complete step-2 payload */
function kyc_org_contact(string $email): array
{
    return [
        'contact_name' => 'Achieng Otieno',
        'contact_role' => 'Managing Director',
        'contact_email' => $email,
        'contact_phone' => '+254 700 111 222',
    ];
}

/** @return array<string, mixed> a submitted organisation (owner + profile) */
function kyc_submitted_org(string $ownerEmail, string $contactEmail): array
{
    $owner = make_user($ownerEmail);
    $userId = (string) $owner['user']['id'];
    $teamId = (string) $owner['team']['id'];

    OnboardingService::saveOrganization($userId, $teamId, kyc_org_details());
    OnboardingService::saveContact($userId, $teamId, kyc_org_contact($contactEmail));

    // The workflow tests do not exercise upload storage: seed the required rows
    // directly through the repository (submit only checks that the kinds exist).
    foreach (['registration_certificate', 'ownership_structure', 'declaration'] as $kind) {
        OrganizationRepository::saveDocument($teamId, [
            'kind' => $kind,
            'original_filename' => $kind . '.pdf',
            'storage_key' => 'tests/kyc/' . $teamId . '/' . $kind . '.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'checksum_sha256' => hash('sha256', $kind . $teamId),
            'uploaded_by_user_id' => $userId,
        ]);
    }

    $profile = OnboardingService::submit($userId, $teamId);

    return ['owner' => $owner, 'user_id' => $userId, 'team_id' => $teamId, 'profile' => $profile];
}

/** @return array{user: array<string, mixed>, team: array<string, mixed>} a platform admin */
function kyc_staff(string $email): array
{
    $staff = make_user($email);
    Db::execute('UPDATE users SET is_platform_admin = 1 WHERE id = ?', [(string) $staff['user']['id']]);
    return $staff;
}

/** @return list<array<string, mixed>> open review tasks for a profile */
function kyc_open_tasks(string $profileId): array
{
    return Db::select(
        "SELECT * FROM review_tasks
         WHERE target_type = 'organization_profile' AND target_id = ?
           AND status IN ('unassigned','in_progress','blocked')",
        [$profileId],
    );
}

/** @return list<array<string, mixed>> every review task for a profile */
function kyc_all_tasks(string $profileId): array
{
    return Db::select(
        "SELECT * FROM review_tasks WHERE target_type = 'organization_profile' AND target_id = ? ORDER BY created_at ASC",
        [$profileId],
    );
}

/** Render the tenant onboarding page for the given state (template-level assertion). */
function kyc_onboarding_markup(array $org): string
{
    $state = OnboardingService::state((string) $org['user_id'], (string) $org['team_id']);

    return View::capture('pages/onboarding', [
        'user' => $org['owner']['user'],
        'team' => $state['team'],
        'state' => $state,
        'step' => 4,
        'maxStep' => 4,
        'errors' => [],
    ]);
}

/** Render the staff KYC screen for the given queue options. */
function kyc_admin_markup(array $staff, string $status = 'submitted', array $errors = [], string $focusId = ''): string
{
    return View::capture('pages/admin/kyc', [
        'user' => $staff['user'],
        'kyc' => PlatformConsoleService::kycQueue((string) $staff['user']['id'], ['status' => $status]),
        'errors' => $errors,
        'focus_id' => $focusId,
        'taskBadge' => 0,
    ]);
}

return [
    'verifying closes the review task, audits the decision and emails the contact' => static function (): void {
        $contactEmail = 'kyc-verify-contact@example.com';
        $org = kyc_submitted_org('kyc-verify-owner@example.com', $contactEmail);
        $staff = kyc_staff('kyc-verify-staff@example.com');
        $profileId = (string) $org['profile']['id'];

        $created = ReviewTaskRepository::syncSubmittedOrganisations(null);
        assert_true($created >= 1, 'submitting must create a KBY review task');
        $openBefore = kyc_open_tasks($profileId);
        assert_count(1, $openBefore, 'exactly one open KBY task for the new profile');
        $taskId = (string) $openBefore[0]['id'];

        $result = OrganizationReviewService::verify((string) $staff['user']['id'], $profileId, 'Documents look good.');

        assert_same('verified', $result['profile']['status']);
        assert_same('Documents look good.', $result['profile']['review_note']);
        assert_not_null($result['profile']['reviewed_at']);

        $state = OnboardingService::state((string) $org['user_id'], (string) $org['team_id']);
        assert_true($state['is_locked'], 'a verified profile stays locked for the tenant');
        assert_false($state['can_edit']);

        assert_count(0, kyc_open_tasks($profileId), 'the decision must close the open review task');
        $task = Db::selectOne('SELECT * FROM review_tasks WHERE id = ?', [$taskId]);
        assert_same('completed', (string) $task['status']);
        assert_same((string) $staff['user']['id'], (string) $task['completed_by_user_id']);

        assert_same(1, count_rows('audit_logs', "action = 'organization.verified'"));
        assert_same(1, count_rows('audit_logs', "action = 'review_task.completed' AND target_id = ?", [$taskId]));

        $email = latest_email($contactEmail, 'kyc_verified');
        assert_not_null($email, 'a verified decision must email the primary contact');
        assert_contains('Lake Victoria Carbon Ltd', (string) $email['body_text']);
        assert_contains('Documents look good.', (string) $email['body_text']);
        assert_contains('/onboarding', (string) $email['body_text']);

        $queueSubmitted = PlatformConsoleService::kycQueue((string) $staff['user']['id'], ['status' => 'submitted']);
        $queueVerified = PlatformConsoleService::kycQueue((string) $staff['user']['id'], ['status' => 'verified']);
        $submittedIds = array_map(static fn (array $row): string => (string) $row['id'], $queueSubmitted['rows']);
        $verifiedIds = array_map(static fn (array $row): string => (string) $row['id'], $queueVerified['rows']);
        assert_false(in_array($profileId, $submittedIds, true), 'a verified profile leaves the submitted filter');
        assert_true(in_array($profileId, $verifiedIds, true), 'a verified profile appears in the verified filter');
    },

    'rejecting requires a reason and makes the profile editable again' => static function (): void {
        $contactEmail = 'kyc-reject-contact@example.com';
        $org = kyc_submitted_org('kyc-reject-owner@example.com', $contactEmail);
        $staff = kyc_staff('kyc-reject-staff@example.com');
        $profileId = (string) $org['profile']['id'];
        ReviewTaskRepository::syncSubmittedOrganisations(null);

        assert_app_error('validation_error', static function () use ($staff, $profileId): void {
            OrganizationReviewService::reject((string) $staff['user']['id'], $profileId, '   ');
        });
        $unchanged = OrganizationRepository::findProfileById($profileId);
        assert_same('submitted', (string) $unchanged['status'], 'an empty reason must not change the profile');

        $reason = 'The registration certificate is unreadable; upload a scan.';
        $result = OrganizationReviewService::reject((string) $staff['user']['id'], $profileId, $reason);

        assert_same('rejected', $result['profile']['status']);
        assert_same($reason, $result['profile']['review_note']);
        assert_not_null($result['profile']['reviewed_at']);

        $state = OnboardingService::state((string) $org['user_id'], (string) $org['team_id']);
        assert_false($state['is_locked'], 'a rejected profile becomes editable again');
        assert_true($state['can_edit']);
        assert_count(0, kyc_open_tasks($profileId));

        assert_same(1, count_rows('audit_logs', "action = 'organization.rejected'"));

        $email = latest_email($contactEmail, 'kyc_rejected');
        assert_not_null($email, 'a rejection must email the primary contact');
        assert_contains($reason, (string) $email['body_text']);

        // The tenant sees the reason and the resubmit CTA on the onboarding page.
        $markup = kyc_onboarding_markup($org);
        assert_contains($reason, $markup);
        assert_contains(e(t('org.review_update_cta')), $markup);
        assert_contains('alert--error', $markup);
    },

    'resubmitting exposes the old note and opens a fresh review task' => static function (): void {
        $org = kyc_submitted_org('kyc-resubmit-owner@example.com', 'kyc-resubmit-contact@example.com');
        $staff = kyc_staff('kyc-resubmit-staff@example.com');
        $profileId = (string) $org['profile']['id'];
        ReviewTaskRepository::syncSubmittedOrganisations(null);

        $reason = 'Please add the ownership structure document.';
        OrganizationReviewService::reject((string) $staff['user']['id'], $profileId, $reason);
        assert_count(1, kyc_all_tasks($profileId), 'rejection closes the first task');

        // Decision D: a rejected profile can be submitted again, and the previous
        // note stays visible until the new decision replaces it.
        $resubmitted = OnboardingService::submit((string) $org['user_id'], (string) $org['team_id']);
        assert_same('submitted', (string) $resubmitted['status']);
        assert_same($reason, (string) $resubmitted['review_note']);

        $markup = kyc_onboarding_markup($org);
        assert_contains(t('org.previous_review_note_label'), $markup);
        assert_contains($reason, $markup);

        $created = ReviewTaskRepository::syncSubmittedOrganisations(null);
        assert_true($created >= 1, 'resubmission must create a new open review task');
        assert_count(1, kyc_open_tasks($profileId), 'exactly one open task after resubmission');
        assert_count(2, kyc_all_tasks($profileId), 'the completed task is kept as history');

        $final = OrganizationReviewService::verify((string) $staff['user']['id'], $profileId, 'All documents received.');
        assert_same('verified', $final['profile']['status']);
        assert_same('All documents received.', $final['profile']['review_note'], 'the new decision replaces the old note');
    },

    'the generic job-queue complete is refused for KBY tasks' => static function (): void {
        $org = kyc_submitted_org('kyc-guard-owner@example.com', 'kyc-guard-contact@example.com');
        $staff = kyc_staff('kyc-guard-staff@example.com');
        $profileId = (string) $org['profile']['id'];
        ReviewTaskRepository::syncSubmittedOrganisations(null);
        $open = kyc_open_tasks($profileId);
        assert_count(1, $open);

        assert_app_error('conflict', static function () use ($staff, $open): void {
            TaskCenterService::complete((string) $open[0]['id'], (string) $staff['user']['id']);
        });

        $task = Db::selectOne('SELECT * FROM review_tasks WHERE id = ?', [(string) $open[0]['id']]);
        assert_same('unassigned', (string) $task['status'], 'the task must stay open without a decision');
        $profile = OrganizationRepository::findProfileById($profileId);
        assert_same('submitted', (string) $profile['status']);
    },

    'KYC decisions are staff-only and single-shot' => static function (): void {
        $org = kyc_submitted_org('kyc-guard-owner-2@example.com', 'kyc-guard-contact-2@example.com');
        $staff = kyc_staff('kyc-guard-staff-2@example.com');
        $profileId = (string) $org['profile']['id'];

        // Tenant accounts (even the workspace owner) never reach staff services.
        assert_app_error('not_found', static function () use ($org, $profileId): void {
            OrganizationReviewService::verify((string) $org['user_id'], $profileId, 'Tenant decision');
        });
        assert_app_error('not_found', static function () use ($staff): void {
            OrganizationReviewService::verify((string) $staff['user']['id'], '00000000-0000-4000-8000-000000000000', 'Missing');
        });

        OrganizationReviewService::verify((string) $staff['user']['id'], $profileId, '');
        assert_app_error('conflict', static function () use ($staff, $profileId): void {
            OrganizationReviewService::reject((string) $staff['user']['id'], $profileId, 'Too late');
        });
    },

    'the KYC queue filters by decision and falls back to submitted' => static function (): void {
        $first = kyc_submitted_org('kyc-queue-owner-1@example.com', 'kyc-queue-contact-1@example.com');
        $second = kyc_submitted_org('kyc-queue-owner-2@example.com', 'kyc-queue-contact-2@example.com');
        $staff = kyc_staff('kyc-queue-staff@example.com');
        $staffId = (string) $staff['user']['id'];
        ReviewTaskRepository::syncSubmittedOrganisations(null);
        OrganizationReviewService::verify($staffId, (string) $second['profile']['id'], 'Verified.');

        $submittedQueue = PlatformConsoleService::kycQueue($staffId, ['status' => 'submitted']);
        assert_same('submitted', $submittedQueue['status']);
        $submittedIds = array_map(static fn (array $row): string => (string) $row['id'], $submittedQueue['rows']);
        assert_true(in_array((string) $first['profile']['id'], $submittedIds, true));
        assert_false(in_array((string) $second['profile']['id'], $submittedIds, true));

        $verified = PlatformConsoleService::kycQueue($staffId, ['status' => 'verified']);
        $verifiedIds = array_map(static fn (array $row): string => (string) $row['id'], $verified['rows']);
        assert_true(in_array((string) $second['profile']['id'], $verifiedIds, true));

        $all = PlatformConsoleService::kycQueue($staffId, ['status' => 'all']);
        assert_same('all', $all['status']);
        assert_true(count($all['rows']) >= 2, 'all filter shows submitted and decided profiles');

        $fallback = PlatformConsoleService::kycQueue($staffId, ['status' => 'bogus']);
        assert_same('submitted', $fallback['status'], 'an invalid status falls back to submitted');

        // Counts only exist for statuses actually recorded in this test database.
        assert_true(array_key_exists('submitted', $submittedQueue['counts']));
        assert_true(array_key_exists('verified', $submittedQueue['counts']));
        foreach ($submittedQueue['counts'] as $statusKey => $count) {
            assert_true(is_int($count), 'KYC count must be an int for ' . $statusKey);
        }
    },

    'the admin KYC screen renders filters, decision forms and inline errors' => static function (): void {
        $org = kyc_submitted_org('kyc-screen-owner@example.com', 'kyc-screen-contact@example.com');
        $staff = kyc_staff('kyc-screen-staff@example.com');
        $profileId = (string) $org['profile']['id'];
        ReviewTaskRepository::syncSubmittedOrganisations(null);

        // Escaping: a hostile legal name must never render as raw markup.
        Db::execute("UPDATE organization_profiles SET legal_name = ? WHERE id = ?", ['<script>alert(1)</script>', $profileId]);
        $markup = kyc_admin_markup($staff, 'submitted', ['review_note' => 'required'], $profileId);

        assert_contains('kyc-filters', $markup);
        assert_contains('aria-current="page"', $markup);
        assert_contains('name="review_note"', $markup);
        assert_contains('/verify', $markup);
        assert_contains('/reject', $markup);
        assert_contains('name="_csrf"', $markup);
        assert_contains(t('console.kyc_verify_button'), $markup);
        assert_contains(t('console.kyc_reject_button'), $markup);
        assert_contains(t('console.kyc_reject_required'), $markup, 'the focused row shows the inline reason error');
        assert_false(str_contains($markup, '<script>alert(1)</script>'), 'profile values must be escaped');
        assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $markup);

        // A decided profile shows no decision forms, only the recorded note.
        OrganizationReviewService::verify((string) $staff['user']['id'], $profileId, 'Looks complete.');
        $verifiedMarkup = kyc_admin_markup($staff, 'verified');
        assert_contains(t('console.kyc_already_reviewed'), $verifiedMarkup);
        assert_contains('Looks complete.', $verifiedMarkup);
    },
];
