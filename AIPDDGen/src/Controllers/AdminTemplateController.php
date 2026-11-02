<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AppError;
use App\Http;
use App\Services\TaskCenterService;
use App\Services\TemplateLibraryService;

/**
 * Staff-only template-library write actions: upload/replace a source document,
 * move a version through publish/retire, and delete a draft or retired version.
 * The read-only list stays on the platform console.
 */
final class AdminTemplateController extends Controller
{
    public function uploadSource(array $params): never
    {
        $user = $this->staff();

        try {
            $file = $_FILES['source'] ?? [];
            TemplateLibraryService::uploadSource(
                (string) $user['id'],
                (string) ($params['id'] ?? ''),
                is_array($file) ? $file : [],
            );
            $this->flash('success', t('flash.template_source_uploaded'));
        } catch (AppError $error) {
            $this->fail($error);
        }

        Http::redirect('/admin/templates');
    }

    public function updateStatus(array $params): never
    {
        $user = $this->staff();

        try {
            TemplateLibraryService::updateStatus(
                (string) $user['id'],
                (string) ($params['id'] ?? ''),
                (string) (Http::input('status') ?? ''),
            );
            $this->flash('success', t('flash.template_status_updated'));
        } catch (AppError $error) {
            $this->fail($error);
        }

        Http::redirect('/admin/templates');
    }

    public function deleteVersion(array $params): never
    {
        $user = $this->staff();

        if ((string) (Http::input('confirm') ?? '') !== '1') {
            $this->flash('error', t('template.error_confirm_required'));
            Http::redirect('/admin/templates');
        }

        try {
            TemplateLibraryService::deleteVersion((string) $user['id'], (string) ($params['id'] ?? ''));
            $this->flash('success', t('flash.template_deleted'));
        } catch (AppError $error) {
            $this->fail($error);
        }

        Http::redirect('/admin/templates');
    }

    /** @return array<string, mixed> */
    private function staff(): array
    {
        $user = $this->requireUser();
        TaskCenterService::assertStaff((string) $user['id']);

        return $user;
    }

    /** Flash a friendly message and go back to the template library. */
    private function fail(AppError $error): never
    {
        // Missing versions stay a real 404 (staff can still see the page).
        if ($error->errorCode === 'not_found') {
            throw $error;
        }

        $message = $error->errorCode === 'validation_error'
            ? t('template.error_upload')
            : t('error.' . $error->errorCode);
        $this->flash('error', $message);
        Http::redirect('/admin/templates');
    }
}
