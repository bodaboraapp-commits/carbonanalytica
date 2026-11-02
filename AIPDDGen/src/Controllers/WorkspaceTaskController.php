<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AppError;
use App\Http;
use App\I18n;
use App\Services\WorkspaceTaskService;
use App\View;

/**
 * Workspace admin job queue: workspace-scoped review tasks.
 *
 * Owner/admin roles only; viewers and editors get 404. The tenant gate lives in
 * WorkspaceTaskService (Tenancy), so cross-workspace tasks are never visible.
 */
final class WorkspaceTaskController extends Controller
{
    public function tasks(array $params): never
    {
        [$user, $team] = $this->workspaceAdmin();

        $queue = WorkspaceTaskService::queue((string) $user['id'], (string) $team['id'], [
            'scope' => (string) (Http::queryString('scope') ?? 'open'),
            'work_type' => (string) (Http::queryString('type') ?? ''),
            'q' => (string) (Http::queryString('q') ?? ''),
            'page' => (int) (Http::queryString('page') ?? 1),
        ]);

        View::render('pages/workspace/tasks', [
            'user' => $user,
            'team' => $team,
            'queue' => $queue,
            'taskBadge' => $queue['summary']['breached'],
        ]);
    }

    public function assign(array $params): never
    {
        [$user, $team] = $this->workspaceAdmin();

        try {
            WorkspaceTaskService::assignToSelf((string) $params['id'], (string) $user['id'], (string) $team['id']);
            $this->flash('success', t('flash.task_assigned'));
        } catch (AppError $error) {
            $this->flash('error', I18n::error($error->errorCode));
        }

        Http::redirect($this->returnPath());
    }

    public function unassign(array $params): never
    {
        [$user, $team] = $this->workspaceAdmin();

        try {
            WorkspaceTaskService::unassign((string) $params['id'], (string) $user['id'], (string) $team['id']);
            $this->flash('success', t('flash.task_unassigned'));
        } catch (AppError $error) {
            $this->flash('error', I18n::error($error->errorCode));
        }

        Http::redirect($this->returnPath());
    }

    public function complete(array $params): never
    {
        [$user, $team] = $this->workspaceAdmin();

        try {
            WorkspaceTaskService::complete((string) $params['id'], (string) $user['id'], (string) $team['id']);
            $this->flash('success', t('flash.task_completed'));
        } catch (AppError $error) {
            $this->flash('error', I18n::error($error->errorCode));
        }

        Http::redirect($this->returnPath());
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function workspaceAdmin(): array
    {
        $user = $this->requireUser();
        $team = $this->currentTeam($user);

        if (!$this->hasRole((string) $team['membership_role'], 'admin')) {
            throw AppError::notFound('That page');
        }

        return [$user, $team];
    }

    /** Only dashboard queue paths are honoured (no open redirects). */
    private function returnPath(): string
    {
        $path = $this->safeNext(is_string($_POST['return'] ?? null) ? $_POST['return'] : null, '/dashboard/tasks');

        return str_starts_with($path, '/dashboard/tasks') ? $path : '/dashboard/tasks';
    }
}
