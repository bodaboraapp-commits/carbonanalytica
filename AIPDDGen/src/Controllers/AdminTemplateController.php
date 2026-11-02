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
    /**
     * Create a new PDD template + its first draft version (staff only). The
     * version starts with a PENDING AI Engine SKILL.md requirement until an
     * admin uploads that skill document.
     */
    public function create(array $params): never
    {
        $user = $this->staff();

        try {
            $file = $_FILES['source'] ?? [];
            TemplateLibraryService::createTemplateWithVersion(
                (string) $user['id'],
                Http::all(),
                is_array($file) ? $file : [],
            );
            $this->flash('success', t('flash.template_created'));
        } catch (AppError $error) {
            $this->fail($error, 'template.error_create');
        }

        Http::redirect('/admin/templates');
    }

    /** Upload the SKILL.md for one template version; marks its skill ready. */
    public function uploadSkill(array $params): never
    {
        $user = $this->staff();

        try {
            $file = $_FILES['skill'] ?? [];
            TemplateLibraryService::uploadSkill(
                (string) $user['id'],
                (string) ($params['id'] ?? ''),
                is_array($file) ? $file : [],
            );
            $this->flash('success', t('flash.template_skill_ready'));
        } catch (AppError $error) {
            $this->fail($error, 'template.error_skill_upload');
        }

        Http::redirect('/admin/templates');
    }

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
    private function fail(AppError $error, string $validationKey = 'template.error_upload'): never
    {
        // Missing versions stay a real 404 (staff can still see the page).
        if ($error->errorCode === 'not_found') {
            throw $error;
        }

        $message = $error->errorCode === 'validation_error'
            ? t($validationKey)
            : t('error.' . $error->errorCode);
        $this->flash('error', $message);
        Http::redirect('/admin/templates');
    }
}
