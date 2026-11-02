<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AppError;
use App\Auth;
use App\Http;
use App\I18n;
use App\Services\TaskCenterService;
use App\View;

/**
 * Staff Action Center and job queue.
 *
 * Every action is gated on `users.is_platform_admin`; a tenant account receives
 * 404 so the console cannot be discovered from the customer app.
 */
final class AdminTaskController extends Controller
{
    public function dashboard(array $params): never
    {
        $user = $this->requireUser();
        TaskCenterService::assertStaff((string) $user['id']);

        $data = TaskCenterService::dashboard((string) $user['id']);

        View::render('pages/admin/dashboard', [
            'user' => $user,
            'dashboard' => $data,
            'taskBadge' => $data['summary']['breached'],
        ], 'layout_admin');
    }

    public function tasks(array $params): never
    {
        $user = $this->requireUser();
        TaskCenterService::assertStaff((string) $user['id']);

        $queue = TaskCenterService::queue((string) $user['id'], [
            'scope' => (string) (Http::queryString('scope') ?? 'open'),
            'work_type' => (string) (Http::queryString('type') ?? ''),
            'q' => (string) (Http::queryString('q') ?? ''),
            'page' => (int) (Http::queryString('page') ?? 1),
        ]);

        View::render('pages/admin/tasks', [
            'user' => $user,
            'queue' => $queue,
            'taskBadge' => $queue['summary']['breached'],
        ], 'layout_admin');
    }

    public function assign(array $params): never
    {
        $user = $this->requireUser();

        try {
            TaskCenterService::assignToSelf((string) $params['id'], (string) $user['id']);
            $this->flash('success', t('flash.task_assigned'));
        } catch (AppError $error) {
            $this->flash('error', I18n::error($error->errorCode));
        }

        Http::redirect($this->returnPath());
    }

    public function unassign(array $params): never
    {
        $user = $this->requireUser();

        try {
            TaskCenterService::unassign((string) $params['id'], (string) $user['id']);
            $this->flash('success', t('flash.task_unassigned'));
        } catch (AppError $error) {
            $this->flash('error', I18n::error($error->errorCode));
        }

        Http::redirect($this->returnPath());
    }

    public function complete(array $params): never
    {
        $user = $this->requireUser();

        try {
            TaskCenterService::complete((string) $params['id'], (string) $user['id']);
            $this->flash('success', t('flash.task_completed'));
        } catch (AppError $error) {
            $this->flash('error', I18n::error($error->errorCode));
        }

        Http::redirect($this->returnPath());
    }

    /** Only same-console return paths are honoured (no open redirects). */
    private function returnPath(): string
    {
        $path = $this->safeNext(is_string($_POST['return'] ?? null) ? $_POST['return'] : null, '/admin/tasks');
        return str_starts_with($path, '/admin') ? $path : '/admin/tasks';
    }
}
