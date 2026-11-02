<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AppError;
use App\Http;
use App\I18n;
use App\Services\DraftService;
use App\View;

/**
 * Workspace drafting endpoints: the manual "Generate draft" action and the
 * read-only draft page.
 *
 * Guards live in DraftService (tenant + editor role, approved fact sheet,
 * published template, configured engine) so the HTTP layer stays thin and the
 * same rules apply to any future API caller.
 */
final class DraftController extends Controller
{
    /**
     * POST /projects/{id}/draft/generate — synchronous per-section generation.
     * On success redirect to the new draft; on a project-level refusal redirect
     * back to the project with a clear, translated message (never a 500).
     */
    public function generate(array $params): never
    {
        $user = $this->requireUser();
        $projectId = (string) ($params['id'] ?? '');

        try {
            $result = DraftService::generate((string) $user['id'], $projectId);
            $this->flash('success', t('flash.draft_generated', [
                'sections' => count($result['sections']),
                'needs_input' => $result['counts']['needs_input'],
            ]));
            Http::redirect('/drafts/' . (string) $result['draft']['id']);
        } catch (AppError $error) {
            // Access failures keep their normal page: another tenant's project is
            // a 404 and a viewer is a 403, exactly as everywhere else.
            if ($error->status === 404 || $error->status === 403) {
                throw $error;
            }

            $reasonKey = $error->fields['reason_key'] ?? null;
            $params = [];
            foreach ($error->fields as $key => $value) {
                if ($key !== 'reason_key' && $key !== 'detail') {
                    $params[(string) $key] = (string) $value;
                }
            }
            $message = is_string($reasonKey) && $reasonKey !== ''
                ? t($reasonKey, $params)
                : I18n::error($error->errorCode);

            // Provider rejections carry a sanitised detail (e.g. "model not
            // found"); show it so a failed generation is actionable.
            $detail = $error->fields['detail'] ?? null;
            if (is_string($detail) && $detail !== '') {
                $message .= ' ' . t('ai.provider_detail', ['detail' => $detail]);
            }
            $this->flash('error', $message);
            Http::redirect('/projects/' . rawurlencode($projectId));
        } catch (\Throwable $error) {
            error_log('[pdd] draft generation failed: ' . $error->getMessage());
            $this->flash('error', t('common.something_wrong'));
            Http::redirect('/projects/' . rawurlencode($projectId));
        }
    }

    /** GET /drafts/{id} — section list, citations and needs-input markers. */
    public function show(array $params): never
    {
        $user = $this->requireUser();
        $view = DraftService::view((string) $user['id'], (string) ($params['id'] ?? ''));

        View::render('pages/draft', [
            'user' => $user,
            'draft' => $view['draft'],
            'project' => $view['project'],
            'sections' => $view['sections'],
            'template' => $view['template'],
            'fact_sheet' => $view['fact_sheet'],
            'counts' => $view['counts'],
        ]);
    }
}
