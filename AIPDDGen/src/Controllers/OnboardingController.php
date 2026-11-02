<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AppError;
use App\Http;
use App\I18n;
use App\Services\OnboardingService;
use App\Session;
use App\Storage;
use App\View;

/**
 * Organization onboarding wizard (steps: organization, contact, documents,
 * review). The controller only moves data between the request and
 * OnboardingService; completion rules and validation live in the service.
 */
final class OnboardingController extends Controller
{
    public function show(array $params): never
    {
        $user = $this->requireUser();
        $team = $this->currentTeam($user);
        $state = OnboardingService::state((string) $user['id'], (string) $team['id']);

        $requested = Http::queryString('step');
        $requestedStep = is_string($requested) && ctype_digit($requested) ? (int) $requested : 0;

        $defaultStep = $state['is_locked'] ? 4 : $state['first_incomplete'];
        $maxStep = OnboardingService::furthestStep($state);
        $step = $requestedStep > 0 ? min($requestedStep, $maxStep) : min($defaultStep, $maxStep);
        $step = max(1, $step);

        View::render('pages/onboarding', [
            'user' => $user,
            'team' => $team,
            'state' => $state,
            'step' => $step,
            'maxStep' => $maxStep,
            'errors' => Session::takeErrors(),
            'uploadMaxBytes' => Storage::maxUploadBytes(Storage::ORGANIZATION_DOCUMENT_MAX_BYTES),
        ]);
    }

    public function saveOrganization(array $params): never
    {
        $user = $this->requireUser();
        $team = $this->currentTeam($user);

        try {
            OnboardingService::saveOrganization((string) $user['id'], (string) $team['id'], $_POST);
            $this->flash('success', t('flash.organization_saved'));
            Http::redirect('/onboarding?step=2');
        } catch (AppError $error) {
            $this->rememberInput(self::postInput());
            $this->backWithError($error, '/onboarding?step=1');
        }
    }

    public function saveContact(array $params): never
    {
        $user = $this->requireUser();
        $team = $this->currentTeam($user);

        try {
            OnboardingService::saveContact((string) $user['id'], (string) $team['id'], $_POST);
            $this->flash('success', t('flash.contact_saved'));
            Http::redirect('/onboarding?step=3');
        } catch (AppError $error) {
            $this->rememberInput(self::postInput());
            $this->backWithError($error, '/onboarding?step=2');
        }
    }

    public function uploadDocument(array $params): never
    {
        $user = $this->requireUser();
        $team = $this->currentTeam($user);
        $kind = (string) (Http::input('kind') ?? '');
        $file = $_FILES['document'] ?? [];

        try {
            OnboardingService::uploadDocument(
                (string) $user['id'],
                (string) $team['id'],
                $kind,
                is_array($file) ? $file : [],
            );
            $this->flash('success', t('flash.document_uploaded'));
        } catch (AppError $error) {
            $this->flash('error', $this->uploadErrorMessage($error));
        }

        Http::redirect('/onboarding?step=3');
    }

    public function deleteDocument(array $params): never
    {
        $user = $this->requireUser();
        $team = $this->currentTeam($user);

        try {
            OnboardingService::deleteDocument((string) $user['id'], (string) $team['id'], (string) $params['id']);
            $this->flash('success', t('flash.document_deleted'));
        } catch (AppError $error) {
            $this->flash('error', I18n::error($error->errorCode));
        }

        Http::redirect('/onboarding?step=3');
    }

    public function submit(array $params): never
    {
        $user = $this->requireUser();
        $team = $this->currentTeam($user);

        if (Http::input('attestation') !== '1') {
            $this->flash('error', t('error.attestation_required'));
            Http::redirect('/onboarding?step=4');
        }

        try {
            OnboardingService::submit((string) $user['id'], (string) $team['id']);
            $this->flash('success', t('flash.organization_submitted'));
        } catch (AppError $error) {
            $this->flash('error', I18n::error($error->errorCode));
        }

        Http::redirect('/onboarding?step=4');
    }

    /** Open a stored document in the browser (PDFs render inline). */
    public function viewDocument(array $params): never
    {
        $this->streamDocument((string) ($params['id'] ?? ''), false);
    }

    /** Download a stored document as an attachment. */
    public function downloadDocument(array $params): never
    {
        $this->streamDocument((string) ($params['id'] ?? ''), true);
    }

    private function streamDocument(string $documentId, bool $asAttachment): never
    {
        $user = $this->requireUser();
        $team = $this->currentTeam($user);
        $document = OnboardingService::documentForTeam((string) $user['id'], (string) $team['id'], $documentId);

        $path = Storage::path((string) $document['storage_key']);
        $filename = self::safeFilename((string) $document['original_filename']);
        $mimeType = self::safeMimeType((string) $document['mime_type']);

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . (string) filesize($path));
        header(
            'Content-Disposition: ' . ($asAttachment ? 'attachment' : 'inline')
            . '; filename="' . $filename . '"',
        );
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }

    /** @return array<string, mixed> */
    private static function postInput(): array
    {
        $input = $_POST;
        unset($input['_csrf']);
        return $input;
    }

    private function uploadErrorMessage(AppError $error): string
    {
        $fieldCode = (string) ($error->fields['file'] ?? $error->fields['kind'] ?? '');

        $key = match ($fieldCode) {
            'required' => 'error.upload_required',
            'too_large' => 'error.upload_too_large',
            'invalid_type' => 'error.upload_invalid_type',
            'empty' => 'error.upload_empty',
            'upload_failed' => 'error.upload_failed',
            default => null,
        };

        if ($key !== null) {
            return t($key);
        }

        if ($fieldCode === 'invalid_choice') {
            return t('error.invalid_document_kind');
        }

        return I18n::error($error->errorCode);
    }

    /** Only the stored basename survives into the Content-Disposition header. */
    private static function safeFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = preg_replace('/[^\w.\- ]+/u', '_', $filename) ?? 'document';
        return $filename === '' ? 'document' : mb_substr($filename, 0, 120);
    }

    /** Never echo a stored MIME type that is not on the accepted list. */
    private static function safeMimeType(string $mimeType): string
    {
        $allowed = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/x-ole-storage',
            'application/cdfv2',
        ];

        return in_array($mimeType, $allowed, true) ? $mimeType : 'application/octet-stream';
    }
}
