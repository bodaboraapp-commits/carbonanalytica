<?php

declare(strict_types=1);

use App\Repositories\OrganizationRepository;
use App\Services\OnboardingService;
use App\Storage;

/**
 * Organization onboarding: step completion, validation, document handling and
 * the submit/lock transition.
 */

/** @return array<string, string> a complete step-1 payload */
function org_onboarding_details(array $overrides = []): array
{
    return array_merge([
        'legal_name' => 'Kisumu Carbon Ventures Ltd',
        'trading_name' => 'KCV',
        'registration_number' => 'PVT-2024-00981',
        'registration_country' => 'Kenya',
        'organization_type' => 'developer',
        'year_founded' => '2019',
        'website' => 'https://example.org',
        'registered_address' => '12 Oginga Odinga Street',
        'city' => 'Kisumu',
    ], $overrides);
}

/** @return array<string, string> a complete step-2 payload */
function org_onboarding_contact(array $overrides = []): array
{
    return array_merge([
        'contact_name' => 'Achieng Otieno',
        'contact_role' => 'Managing Director',
        'contact_email' => 'achieng@example.org',
        'contact_phone' => '+254 700 111 222',
    ], $overrides);
}

/** Create a real PDF file on disk and hand its path to the caller. */
function org_onboarding_temp_pdf(): string
{
    $path = tempnam(sys_get_temp_dir(), 'orgdoc_');
    if ($path === false) {
        throw new RuntimeException('Could not create a temporary file');
    }
    $pdfPath = $path . '.pdf';
    rename($path, $pdfPath);
    file_put_contents($pdfPath, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    return $pdfPath;
}

/**
 * Store a document for a team through the real Storage path, returning the
 * stored key so the caller can clean the file up.
 *
 * @return array{key: string, document: array<string, mixed>}
 */
function org_onboarding_store_document(string $teamId, string $userId, string $kind): array
{
    $stored = Storage::putLocalFile(
        org_onboarding_temp_pdf(),
        $kind . '.pdf',
        'organization',
        Storage::ORGANIZATION_DOCUMENT_EXTENSIONS,
        Storage::ORGANIZATION_DOCUMENT_MAX_BYTES,
    );

    $document = OrganizationRepository::saveDocument($teamId, [
        'kind' => $kind,
        'original_filename' => $stored['filename'],
        'storage_key' => $stored['key'],
        'mime_type' => $stored['mime_type'],
        'size_bytes' => $stored['size_bytes'],
        'checksum_sha256' => $stored['checksum_sha256'],
        'uploaded_by_user_id' => $userId,
    ]);

    return ['key' => $stored['key'], 'document' => $document];
}

return [
    'starts every workspace in draft with no steps complete' => static function (): void {
        $owner = make_user('owner@example.com');
        $state = OnboardingService::state((string) $owner['user']['id'], (string) $owner['team']['id']);

        assert_same('draft', $state['status']);
        assert_null($state['profile']);
        assert_same(false, $state['completion'][1]);
        assert_same(1, $state['first_incomplete']);
        assert_same(2, OnboardingService::furthestStep($state));
        assert_count(count(array_filter(OnboardingService::DOCUMENTS)), $state['missing_required']);
        assert_true($state['can_edit']);
        assert_false($state['is_locked']);
    },

    'saves organization details and moves to the contact step' => static function (): void {
        $owner = make_user('owner@example.com');
        $userId = (string) $owner['user']['id'];
        $teamId = (string) $owner['team']['id'];

        $profile = OnboardingService::saveOrganization($userId, $teamId, org_onboarding_details());

        assert_same('Kisumu Carbon Ventures Ltd', $profile['legal_name']);
        assert_same('developer', $profile['organization_type']);
        assert_same(2019, (int) $profile['year_founded']);
        assert_same(1, count_rows('audit_logs', "action = 'organization.profile.updated'"));

        $state = OnboardingService::state($userId, $teamId);
        assert_true($state['completion'][1]);
        assert_same(2, $state['first_incomplete']);
    },

    'rejects missing and malformed organization details' => static function (): void {
        $owner = make_user('owner@example.com');
        $userId = (string) $owner['user']['id'];
        $teamId = (string) $owner['team']['id'];

        assert_app_error('validation_error', static function () use ($userId, $teamId): void {
            OnboardingService::saveOrganization($userId, $teamId, org_onboarding_details(['legal_name' => '']));
        });

        assert_app_error('validation_error', static function () use ($userId, $teamId): void {
            OnboardingService::saveOrganization($userId, $teamId, org_onboarding_details(['year_founded' => '1820']));
        });

        assert_app_error('validation_error', static function () use ($userId, $teamId): void {
            OnboardingService::saveOrganization($userId, $teamId, org_onboarding_details(['website' => 'not a url']));
        });

        assert_same(0, count_rows('organization_profiles'));
    },

    'saves the primary contact and validates its email and phone' => static function (): void {
        $owner = make_user('owner@example.com');
        $userId = (string) $owner['user']['id'];
        $teamId = (string) $owner['team']['id'];

        OnboardingService::saveOrganization($userId, $teamId, org_onboarding_details());

        assert_app_error('validation_error', static function () use ($userId, $teamId): void {
            OnboardingService::saveContact($userId, $teamId, org_onboarding_contact(['contact_email' => 'nope']));
        });

        assert_app_error('validation_error', static function () use ($userId, $teamId): void {
            OnboardingService::saveContact($userId, $teamId, org_onboarding_contact(['contact_phone' => 'abc']));
        });

        $profile = OnboardingService::saveContact($userId, $teamId, org_onboarding_contact());
        assert_same('Achieng Otieno', $profile['contact_name']);
    },

    'needs every required document before it can be submitted' => static function (): void {
        $owner = make_user('owner@example.com');
        $userId = (string) $owner['user']['id'];
        $teamId = (string) $owner['team']['id'];

        OnboardingService::saveOrganization($userId, $teamId, org_onboarding_details());
        OnboardingService::saveContact($userId, $teamId, org_onboarding_contact());

        $state = OnboardingService::state($userId, $teamId);
        assert_same(['registration_certificate', 'ownership_structure', 'declaration'], $state['missing_required']);

        assert_app_error('organization_incomplete', static function () use ($userId, $teamId): void {
            OnboardingService::submit($userId, $teamId);
        });

        $stored = [];
        $stored[] = org_onboarding_store_document($teamId, $userId, 'registration_certificate');
        $stored[] = org_onboarding_store_document($teamId, $userId, 'ownership_structure');

        try {
            assert_app_error('organization_incomplete', static function () use ($userId, $teamId): void {
                OnboardingService::submit($userId, $teamId);
            });
        } finally {
            foreach ($stored as $item) {
                Storage::delete($item['key']);
            }
        }
    },

    'submits, locks the profile and audits the transition' => static function (): void {
        $owner = make_user('owner@example.com');
        $userId = (string) $owner['user']['id'];
        $teamId = (string) $owner['team']['id'];

        OnboardingService::saveOrganization($userId, $teamId, org_onboarding_details());
        OnboardingService::saveContact($userId, $teamId, org_onboarding_contact());

        $stored = [];
        foreach (['registration_certificate', 'ownership_structure', 'declaration'] as $kind) {
            $stored[] = org_onboarding_store_document($teamId, $userId, $kind);
        }

        try {
            $profile = OnboardingService::submit($userId, $teamId);
            assert_same('submitted', $profile['status']);
            assert_not_null($profile['submitted_at']);
            assert_same($userId, (string) $profile['attested_by_user_id']);
            assert_same(1, count_rows('audit_logs', "action = 'organization.submitted'"));

            $state = OnboardingService::state($userId, $teamId);
            assert_true($state['is_locked']);
            assert_false($state['can_edit']);
            assert_true($state['completion'][3]);
            assert_true($state['completion'][4]);

            // A submitted profile is immutable until review.
            assert_app_error('conflict', static function () use ($userId, $teamId): void {
                OnboardingService::saveOrganization($userId, $teamId, org_onboarding_details(['legal_name' => 'Renamed Ltd']));
            });
            assert_app_error('conflict', static function () use ($userId, $teamId): void {
                OnboardingService::submit($userId, $teamId);
            });
        } finally {
            foreach ($stored as $item) {
                Storage::delete($item['key']);
            }
        }
    },

    'only admins and owners can change onboarding data' => static function (): void {
        $owner = make_user('owner@example.com');
        $teamId = (string) $owner['team']['id'];

        $viewer = add_team_member($teamId, (string) $owner['user']['id'], 'viewer@example.com', 'viewer');

        // Viewers may read the summary …
        $state = OnboardingService::state((string) $viewer['user']['id'], $teamId);
        assert_false($state['can_edit']);

        // … but not change it.
        assert_app_error('forbidden', static function () use ($viewer, $teamId): void {
            OnboardingService::saveOrganization((string) $viewer['user']['id'], $teamId, org_onboarding_details());
        });
        assert_app_error('forbidden', static function () use ($viewer, $teamId): void {
            OnboardingService::submit((string) $viewer['user']['id'], $teamId);
        });
    },

    'keeps another workspace out of the profile and its documents' => static function (): void {
        $owner = make_user('owner@example.com');
        $intruder = make_user('intruder@example.com');

        $teamId = (string) $owner['team']['id'];
        OnboardingService::saveOrganization((string) $owner['user']['id'], $teamId, org_onboarding_details());
        $stored = org_onboarding_store_document($teamId, (string) $owner['user']['id'], 'declaration');

        try {
            assert_app_error('not_found', static function () use ($intruder, $teamId): void {
                OnboardingService::state((string) $intruder['user']['id'], $teamId);
            });

            assert_app_error('not_found', static function () use ($intruder, $teamId, $stored): void {
                OnboardingService::documentForTeam(
                    (string) $intruder['user']['id'],
                    (string) $intruder['team']['id'],
                    (string) $stored['document']['id'],
                );
            });

            // The document id cannot be read through the intruder's own workspace.
            assert_app_error('not_found', static function () use ($intruder, $stored): void {
                OnboardingService::documentForTeam(
                    (string) $intruder['user']['id'],
                    (string) $intruder['team']['id'],
                    (string) $stored['document']['id'],
                );
            });
        } finally {
            Storage::delete($stored['key']);
        }    },

    'removes a document and its stored file' => static function (): void {
        $owner = make_user('owner@example.com');
        $userId = (string) $owner['user']['id'];
        $teamId = (string) $owner['team']['id'];
        OnboardingService::saveOrganization($userId, $teamId, org_onboarding_details());

        $stored = org_onboarding_store_document($teamId, $userId, 'declaration');
        assert_true(Storage::exists($stored['key']));

        OnboardingService::deleteDocument($userId, $teamId, (string) $stored['document']['id']);

        assert_same(0, count_rows('organization_documents'));
        assert_false(Storage::exists($stored['key']));
        assert_same(1, count_rows('audit_logs', "action = 'organization.document.deleted'"));

        assert_app_error('not_found', static function () use ($userId, $teamId, $stored): void {
            OnboardingService::deleteDocument($userId, $teamId, (string) $stored['document']['id']);
        });
    },

    'rejects a document type that is not a PDF, DOC or DOCX' => static function (): void {
        $owner = make_user('owner@example.com');
        $userId = (string) $owner['user']['id'];
        $teamId = (string) $owner['team']['id'];
        OnboardingService::saveOrganization($userId, $teamId, org_onboarding_details());

        $path = tempnam(sys_get_temp_dir(), 'orgdoc_');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file');
        }
        file_put_contents($path, 'MZ fake executable');

        try {
            assert_app_error('validation_error', static function () use ($path): void {
                Storage::putLocalFile(
                    $path,
                    'installer.exe',
                    'organization',
                    Storage::ORGANIZATION_DOCUMENT_EXTENSIONS,
                    Storage::ORGANIZATION_DOCUMENT_MAX_BYTES,
                );
            });
        } finally {
            @unlink($path);
        }

        // Unknown document kinds are refused before any file is stored.
        assert_app_error('validation_error', static function () use ($userId, $teamId): void {
            OnboardingService::uploadDocument($userId, $teamId, 'passport', []);
        });
    },

    'replacing a document keeps one current file per requirement' => static function (): void {
        $owner = make_user('owner@example.com');
        $userId = (string) $owner['user']['id'];
        $teamId = (string) $owner['team']['id'];
        OnboardingService::saveOrganization($userId, $teamId, org_onboarding_details());

        $first = org_onboarding_store_document($teamId, $userId, 'declaration');
        $second = org_onboarding_store_document($teamId, $userId, 'declaration');

        try {
            assert_same(1, count_rows('organization_documents'));
            assert_same($second['key'], (string) $second['document']['storage_key']);
        } finally {
            Storage::delete($first['key']);
            Storage::delete($second['key']);
        }
    },
];
