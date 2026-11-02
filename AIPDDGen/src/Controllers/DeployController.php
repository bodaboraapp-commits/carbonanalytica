<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AppError;
use App\Config;
use App\Http;
use App\Services\DumpImportService;
use App\View;

/**
 * Token-gated deployment URL: GET /deploy/import?token=...
 *
 * This exists because a fresh deployment has no account to sign in with. The
 * token comes from DEPLOY_TOKEN in .env.local (not committed); when it is empty
 * the route answers 404 so the endpoint stays invisible by default.
 *
 * Safe use:
 *   /deploy/import?token=...&dry_run=1   validate only, no SQL executed
 *   /deploy/import?token=...             import when the DB is empty
 *   /deploy/import?token=...&replace=1   explicitly replace existing data
 */
final class DeployController extends Controller
{
    public function import(array $params): never
    {
        $configured = (string) Config::get('deploy.token');
        $provided = (string) (Http::queryString('token') ?? '');

        if ($configured === '' || !hash_equals($configured, $provided)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found';
            exit;
        }

        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');

        $replace = (string) (Http::queryString('replace') ?? '') === '1';
        $dryRun = (string) (Http::queryString('dry_run') ?? '') === '1';

        $state = DumpImportService::inspectData();
        $result = null;
        $error = null;

        $shouldRun = $dryRun || $replace || $state['safe_to_import'];

        if ($shouldRun) {
            try {
                $result = DumpImportService::importAsDeployToken($replace, $dryRun);
            } catch (AppError $exception) {
                $error = 'confirm';
            } catch (\Throwable $exception) {
                error_log('[pdd] token dump import failed: ' . $exception->getMessage());
                $error = 'failed';
            }
        } else {
            $error = 'confirm';
        }

        View::render('pages/deploy-import', [
            'token' => $configured,
            'state' => $state,
            'result' => $result,
            'dry_run' => $dryRun,
            'replace' => $replace,
            'error' => $error,
            'executed' => $result !== null,
        ], null);
    }
}
