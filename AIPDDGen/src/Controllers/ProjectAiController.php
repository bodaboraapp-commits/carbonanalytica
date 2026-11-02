<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AppError;
use App\Http;
use App\I18n;
use App\Services\ProjectAiService;

/**
 * Workspace-side AI input tunnel: editors submit project input evidence, which the
 * admin-configured engine later turns into a PDD draft via the existing
 * POST /projects/{id}/draft/generate endpoint.
 */
final class ProjectAiController extends Controller
{
    /** POST /projects/{id}/inputs — add text and/or a .txt/.md attachment. */
    public function addInput(array $params): never
    {
        $user = $this->requireUser();
        $projectId = (string) ($params['id'] ?? '');
        $backPath = '/projects/' . rawurlencode($projectId);

        try {
            ProjectAiService::addInput(
                (string) $user['id'],
                $projectId,
                (string) (Http::input('text') ?? ''),
                $_FILES['attachment'] ?? [],
            );
            $this->flash('success', t('project.ai_input_added'));
        } catch (AppError $error) {
            // Cross-tenant projects stay a 404 and viewers keep their normal 403.
            if ($error->status === 404 || $error->status === 403) {
                throw $error;
            }

            $message = $error->errorCode === 'validation_error'
                ? t('project.ai_input_invalid')
                : I18n::error($error->errorCode);
            $this->flash('error', $message);
            Http::redirect($backPath);
        } catch (\Throwable $error) {
            error_log('[pdd] project input failed: ' . $error->getMessage());
            $this->flash('error', t('common.something_wrong'));
            Http::redirect($backPath);
        }

        Http::redirect($backPath);
    }
}
