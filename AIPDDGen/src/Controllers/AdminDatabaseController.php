<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AppError;
use App\Http;
use App\I18n;
use App\Repositories\ReviewTaskRepository;
use App\Services\DatabaseMaintenanceService;
use App\Services\DumpImportService;
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

    /** Confirmation page for importing database/pdd_generator_deploy.sql. */
    public function showImport(array $params): never
    {
        $user = $this->staff();

        View::render('pages/admin/import', [
            'user' => $user,
            'state' => DumpImportService::inspect((string) $user['id']),
            'taskBadge' => ReviewTaskRepository::summary((string) $user['id'])['breached'],
        ], 'layout_admin');
    }

    /**
     * Import the deployment dump. The page URL is the entry point; the POST
     * button performs the import (or a dry-run validation).
     */
    public function importDump(array $params): never
    {
        $user = $this->staff();

        $action = (string) (Http::input('action') ?? 'import');
        $dryRun = $action === 'dry_run';
        $replace = (string) (Http::input('replace') ?? '') === '1';

        try {
            $result = DumpImportService::import((string) $user['id'], $replace, $dryRun);

            if ($dryRun) {
                $this->flash('success', t('flash.dump_dry', [
                    'count' => $result['statements'],
                    'tables' => $result['tables'],
                ]));
            } else {
                $this->flash('success', t('flash.dump_imported', [
                    'count' => $result['statements'],
                    'tables' => $result['tables'],
                ]));

                // The import replaces the users table, so the session account no
                // longer exists; send the visitor to the sign-in screen.
                Http::redirect('/login');
            }
        } catch (AppError $error) {
            $this->flash(
                'error',
                $error->errorCode === 'conflict' ? t('flash.dump_blocked') : I18n::error($error->errorCode),
            );
        } catch (\Throwable $error) {
            error_log('[pdd] dump import failed: ' . $error->getMessage());
            $this->flash('error', t('flash.dump_failed'));
        }

        Http::redirect('/admin/database/import');
    }

    /** @return array<string, mixed> */
    private function staff(): array
    {
        $user = $this->requireUser();
        TaskCenterService::assertStaff((string) $user['id']);
        return $user;
    }
}
