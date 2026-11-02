<?php

declare(strict_types=1);

namespace App\Services;

use App\AppError;
use App\Audit;
use App\Db;
use App\Repositories\OrganizationRepository;
use App\Storage;
use App\Tenancy;
use App\Validator;

/**
 * Organization onboarding: the four-step verification wizard shown to a
 * workspace before it can be treated as a verified organization.
 *
 * Steps are derived from stored data rather than tracked in a wizard table, so
 * a refreshed page, a second admin or a re-opened tab always agrees on where
 * the organization stands. Writes require the admin role; the owner and admins
 * can submit; viewers can read the summary and the uploaded documents.
 */
final class OnboardingService
{
    /** Step number => machine name. */
    public const STEPS = [
        1 => 'organization',
        2 => 'contact',
        3 => 'documents',
        4 => 'review',
    ];

    /** Document kind => required for submission. */
    public const DOCUMENTS = [
        'registration_certificate' => true,
        'ownership_structure' => true,
        'declaration' => true,
        'tax_compliance' => false,
    ];

    /** @var list<string> */
    public const ORGANIZATION_TYPES = [
        'developer', 'consultant', 'ngo', 'government', 'academic', 'cooperative', 'other',
    ];

    private const LOCKED_STATUSES = ['submitted', 'verified'];

    /**
     * Everything the wizard needs for one workspace.
     *
     * @return array{
     *     team: array<string, mixed>,
     *     role: string,
     *     profile: array<string, mixed>|null,
     *     documents: list<array<string, mixed>>,
     *     documents_by_kind: array<string, array<string, mixed>>,
     *     status: string,
     *     completion: array<int, bool>,
     *     first_incomplete: int,
     *     can_edit: bool,
     *     is_locked: bool,
     *     missing_required: list<string>
     * }
     */
    public static function state(string $userId, string $teamId): array
    {
        $scope = Tenancy::teamForUser($userId, $teamId, 'viewer');
        $role = $scope['role'];

        $profile = OrganizationRepository::findProfile($teamId);
        $documents = OrganizationRepository::documentsForTeam($teamId);

        $documentsByKind = [];
        foreach ($documents as $document) {
            $documentsByKind[(string) $document['kind']] = $document;
        }

        $status = $profile !== null ? (string) $profile['status'] : 'draft';
        $completion = self::completion($profile, $documentsByKind);
        $firstIncomplete = 4;
        foreach ([1, 2, 3] as $step) {
            if (!$completion[$step]) {
                $firstIncomplete = $step;
                break;
            }
        }

        $locked = self::isLocked($status);

        return [
            'team' => $scope['team'],
            'role' => $role,
            'profile' => $profile,
            'documents' => $documents,
            'documents_by_kind' => $documentsByKind,
            'status' => $status,
            'completion' => $completion,
            'first_incomplete' => $firstIncomplete,
            'can_edit' => Tenancy::roleAtLeast($role, 'admin') && !$locked,
            'is_locked' => $locked,
            'missing_required' => self::missingRequiredDocuments($documentsByKind),
        ];
    }

    /**
     * Save step 1: the legal organization details.
     *
     * @param array<string, mixed> $input raw form input
     * @return array<string, mixed> the stored profile
     */
    public static function saveOrganization(string $userId, string $teamId, array $input): array
    {
        self::authorize($userId, $teamId, 'admin');

        $validated = Validator::validate($input, [
            'legal_name' => 'required|max:200',
            'trading_name' => 'max:200',
            'registration_number' => 'required|max:80',
            'registration_country' => 'required|max:80',
            'organization_type' => 'required|in:' . implode(',', self::ORGANIZATION_TYPES),
            'year_founded' => 'max:4',
            'website' => 'max:255',
            'registered_address' => 'required|max:400',
            'city' => 'required|max:120',
        ]);

        $yearFounded = null;
        if (isset($validated['year_founded']) && $validated['year_founded'] !== '') {
            $year = (string) $validated['year_founded'];
            $currentYear = (int) gmdate('Y');
            if (!preg_match('/^\d{4}$/', $year) || (int) $year < 1900 || (int) $year > $currentYear) {
                throw AppError::validation(['year_founded' => 'invalid_year']);
            }
            $yearFounded = (int) $year;
        }

        $website = isset($validated['website']) ? trim((string) $validated['website']) : '';
        if ($website !== '' && filter_var($website, FILTER_VALIDATE_URL) === false) {
            throw AppError::validation(['website' => 'invalid_url']);
        }

        $fields = [
            'legal_name' => $validated['legal_name'],
            'trading_name' => self::nullable($validated['trading_name'] ?? null),
            'registration_number' => $validated['registration_number'],
            'registration_country' => $validated['registration_country'],
            'organization_type' => $validated['organization_type'],
            'year_founded' => $yearFounded,
            'website' => $website !== '' ? $website : null,
            'registered_address' => $validated['registered_address'],
            'city' => $validated['city'],
        ];

        return Db::transaction(static function () use ($teamId, $userId, $fields): array {
            $before = OrganizationRepository::findProfile($teamId);
            if ($before !== null && self::isLocked((string) $before['status'])) {
                throw AppError::conflict('This organization has already been submitted for review.');
            }

            OrganizationRepository::ensureProfile($teamId);
            OrganizationRepository::updateProfile($teamId, $fields);

            Audit::record([
                'team_id' => $teamId,
                'actor_user_id' => $userId,
                'action' => 'organization.profile.updated',
                'target_type' => 'organization_profile',
                'target_id' => (string) $teamId,
                'metadata' => ['step' => 'organization', 'legal_name' => $fields['legal_name']],
            ]);

            $profile = OrganizationRepository::findProfile($teamId);
            if ($profile === null) {
                throw AppError::internal();
            }
            return $profile;
        });
    }

    /**
     * Save step 2: the primary contact.
     *
     * @param array<string, mixed> $input raw form input
     * @return array<string, mixed> the stored profile
     */
    public static function saveContact(string $userId, string $teamId, array $input): array
    {
        self::authorize($userId, $teamId, 'admin');

        $validated = Validator::validate($input, [
            'contact_name' => 'required|max:160',
            'contact_role' => 'required|max:120',
            'contact_email' => 'required|email|max:254',
            'contact_phone' => 'required|max:40',
        ]);

        if (!preg_match('/^\+?[0-9][0-9 ()\-]{5,}$/', (string) $validated['contact_phone'])) {
            throw AppError::validation(['contact_phone' => 'invalid_phone']);
        }

        $fields = [
            'contact_name' => $validated['contact_name'],
            'contact_role' => $validated['contact_role'],
            'contact_email' => $validated['contact_email'],
            'contact_phone' => $validated['contact_phone'],
        ];

        return Db::transaction(static function () use ($teamId, $userId, $fields): array {
            $before = OrganizationRepository::findProfile($teamId);
            if ($before !== null && self::isLocked((string) $before['status'])) {
                throw AppError::conflict('This organization has already been submitted for review.');
            }

            OrganizationRepository::ensureProfile($teamId);
            OrganizationRepository::updateProfile($teamId, $fields);

            Audit::record([
                'team_id' => $teamId,
                'actor_user_id' => $userId,
                'action' => 'organization.profile.updated',
                'target_type' => 'organization_profile',
                'target_id' => (string) $teamId,
                'metadata' => ['step' => 'contact'],
            ]);

            $profile = OrganizationRepository::findProfile($teamId);
            if ($profile === null) {
                throw AppError::internal();
            }
            return $profile;
        });
    }

    /**
     * Step 3: upload (or replace) one verification document.
     *
     * @param array<string, mixed> $file a $_FILES entry
     * @return array<string, mixed> the stored document row
     */
    public static function uploadDocument(string $userId, string $teamId, string $kind, array $file): array
    {
        self::authorize($userId, $teamId, 'admin');

        if (!array_key_exists($kind, self::DOCUMENTS)) {
            throw AppError::validation(['kind' => 'invalid_choice']);
        }

        $before = OrganizationRepository::findProfile($teamId);
        if ($before !== null && self::isLocked((string) $before['status'])) {
            throw AppError::conflict('This organization has already been submitted for review.');
        }

        $previous = OrganizationRepository::findDocumentByKind($teamId, $kind);

        $stored = Storage::putUploadedFile(
            $file,
            'organization',
            Storage::ORGANIZATION_DOCUMENT_EXTENSIONS,
            Storage::ORGANIZATION_DOCUMENT_MAX_BYTES,
        );

        $document = Db::transaction(static function () use ($teamId, $userId, $kind, $stored, $previous): array {
            $document = OrganizationRepository::saveDocument($teamId, [
                'kind' => $kind,
                'original_filename' => $stored['filename'],
                'storage_key' => $stored['key'],
                'mime_type' => $stored['mime_type'],
                'size_bytes' => $stored['size_bytes'],
                'checksum_sha256' => $stored['checksum_sha256'],
                'uploaded_by_user_id' => $userId,
            ]);

            Audit::record([
                'team_id' => $teamId,
                'actor_user_id' => $userId,
                'action' => 'organization.document.uploaded',
                'target_type' => 'organization_document',
                'target_id' => (string) $document['id'],
                'metadata' => [
                    'kind' => $kind,
                    'filename' => $stored['filename'],
                    'size_bytes' => $stored['size_bytes'],
                    'replaced' => $previous !== null,
                ],
            ]);

            return $document;
        });

        // The row now points at the new file; remove the replaced one only after
        // the transaction commits so a failed save never loses the old file.
        if ($previous !== null && (string) $previous['storage_key'] !== (string) $stored['key']) {
            Storage::delete((string) $previous['storage_key']);
        }

        return $document;
    }

    public static function deleteDocument(string $userId, string $teamId, string $documentId): void
    {
        self::authorize($userId, $teamId, 'admin');

        $before = OrganizationRepository::findProfile($teamId);
        if ($before !== null && self::isLocked((string) $before['status'])) {
            throw AppError::conflict('This organization has already been submitted for review.');
        }

        $document = OrganizationRepository::findDocument($teamId, $documentId);
        if ($document === null) {
            throw AppError::notFound('That document');
        }

        Db::transaction(static function () use ($teamId, $userId, $document): void {
            OrganizationRepository::deleteDocument($teamId, (string) $document['id']);

            Audit::record([
                'team_id' => $teamId,
                'actor_user_id' => $userId,
                'action' => 'organization.document.deleted',
                'target_type' => 'organization_document',
                'target_id' => (string) $document['id'],
                'metadata' => ['kind' => (string) $document['kind']],
            ]);
        });

        Storage::delete((string) $document['storage_key']);
    }

    /**
     * Step 4: submit the completed onboarding for verification.
     *
     * @return array<string, mixed> the stored profile
     */
    public static function submit(string $userId, string $teamId): array
    {
        self::authorize($userId, $teamId, 'admin');

        $profile = OrganizationRepository::findProfile($teamId);
        if ($profile === null) {
            throw new AppError('organization_incomplete', 422, 'Complete the organization details before submitting.');
        }
        if (self::isLocked((string) $profile['status'])) {
            throw AppError::conflict('This organization has already been submitted for review.');
        }

        $documents = OrganizationRepository::documentsForTeam($teamId);
        $documentsByKind = [];
        foreach ($documents as $document) {
            $documentsByKind[(string) $document['kind']] = $document;
        }

        $completion = self::completion($profile, $documentsByKind);
        $missing = self::missingRequiredDocuments($documentsByKind);
        if (!$completion[1] || !$completion[2] || $missing !== []) {
            throw new AppError(
                'organization_incomplete',
                422,
                'Complete the required steps and documents before submitting.',
            );
        }

        Db::transaction(static function () use ($teamId, $userId, $profile): void {
            OrganizationRepository::markSubmitted($teamId, $userId);

            Audit::record([
                'team_id' => $teamId,
                'actor_user_id' => $userId,
                'action' => 'organization.submitted',
                'target_type' => 'organization_profile',
                'target_id' => (string) $profile['id'],
                'metadata' => ['legal_name' => $profile['legal_name']],
            ]);
        });

        $updated = OrganizationRepository::findProfile($teamId);
        if ($updated === null) {
            throw AppError::internal();
        }
        return $updated;
    }

    /**
     * A document the caller is allowed to read, for the preview/download route.
     *
     * @return array<string, mixed>
     */
    public static function documentForTeam(string $userId, string $teamId, string $documentId): array
    {
        Tenancy::teamForUser($userId, $teamId, 'viewer');

        $document = OrganizationRepository::findDocument($teamId, $documentId);
        if ($document === null) {
            throw AppError::notFound('That document');
        }
        if (!Storage::exists((string) $document['storage_key'])) {
            throw AppError::notFound('That document');
        }

        return $document;
    }

    /**
     * Which steps are done. Step 4 is reached by submitting.
     *
     * @param array<string, mixed>|null $profile
     * @param array<string, array<string, mixed>> $documentsByKind
     * @return array<int, bool>
     */
    public static function completion(?array $profile, array $documentsByKind): array
    {
        $organizationDone = $profile !== null && self::filled($profile, [
            'legal_name', 'registration_number', 'registration_country',
            'organization_type', 'registered_address', 'city',
        ]);

        $contactDone = $profile !== null && self::filled($profile, [
            'contact_name', 'contact_role', 'contact_email', 'contact_phone',
        ]);

        $documentsDone = self::missingRequiredDocuments($documentsByKind) === [];
        $status = $profile !== null ? (string) $profile['status'] : 'draft';
        $submitted = in_array($status, ['submitted', 'verified'], true);

        return [
            1 => $organizationDone,
            2 => $contactDone,
            3 => $documentsDone,
            4 => $submitted,
        ];
    }

    /** @return list<string> required kinds that have no file yet */
    public static function missingRequiredDocuments(array $documentsByKind): array
    {
        $missing = [];
        foreach (self::DOCUMENTS as $kind => $required) {
            if ($required && !isset($documentsByKind[$kind])) {
                $missing[] = $kind;
            }
        }
        return $missing;
    }

    public static function isLocked(string $status): bool
    {
        return in_array($status, self::LOCKED_STATUSES, true);
    }

    /** The furthest step a visitor may open: the first incomplete step, plus one look-ahead. */
    public static function furthestStep(array $state): int
    {
        return (int) min(4, max(1, $state['first_incomplete'] + 1));
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $keys
     */
    private static function filled(array $row, array $keys): bool
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if ($value === null || trim((string) $value) === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array{team: array<string, mixed>, role: string, membership_id: string}
     */
    private static function authorize(string $userId, string $teamId, string $minimumRole): array
    {
        return Tenancy::teamForUser($userId, $teamId, $minimumRole);
    }

    private static function nullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
