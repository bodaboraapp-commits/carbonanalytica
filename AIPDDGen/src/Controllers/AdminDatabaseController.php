<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AppError;
use App\Http;
use App\I18n;
use App\Repositories\ReviewTaskRepository;
use App\Services\DatabaseMaintenanceService;
use App\Services\TaskCenterService;
use App\View;

/**
 * Staff-only database maintenance: view the migration ledger, apply pending
 * migrations and run the idempotent seed after a deployment.
 */
final class AdminDatabaseController extends Controller
{
    public function show(array $params): never
    {
        $user = $this->staff();

        View::render('pages/admin/database', [
            'user' => $user,
            'status' => DatabaseMaintenanceService::status((string) $user['id']),
            'taskBadge' => ReviewTaskRepository::summary((string) $user['id'])['breached'],
        ], 'layout_admin');
    }

    public function migrate(array $params): never
    {
        $user = $this->staff();

        try {
            $applied = DatabaseMaintenanceService::migrate((string) $user['id']);
            $this->flash('success', $applied === []
                ? t('flash.db_uptodate')
                : t('flash.db_migrated', ['count' => count($applied)]));
        } catch (AppError $error) {
            $this->flash('error', I18n::error($error->errorCode));
        } catch (\Throwable $error) {
            error_log('[pdd] migration failed: ' . $error->getMessage());
            $this->flash('error', t('flash.db_failed'));
        }

        Http::redirect('/admin/database');
    }

    public function seed(array $params): never
    {
        $user = $this->staff();

        try {
            DatabaseMaintenanceService::seed((string) $user['id']);
            $this->flash('success', t('flash.db_seeded'));
        } catch (AppError $error) {
            $this->flash('error', I18n::error($error->errorCode));
        } catch (\Throwable $error) {
            error_log('[pdd] seed failed: ' . $error->getMessage());
            $this->flash('error', t('flash.db_failed'));
        }

        Http::redirect('/admin/database');
    }

    /** @return array<string, mixed> */
    private function staff(): array
    {
        $user = $this->requireUser();
        TaskCenterService::assertStaff((string) $user['id']);
        return $user;
    }
}
