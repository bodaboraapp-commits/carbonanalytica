<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AppError;
use App\Http;
use App\I18n;
use App\Services\AiEngineService;
use App\Services\PlatformConsoleService;
use App\Services\TaskCenterService;
use App\Session;
use App\View;

/**
 * AI Engine settings (platform console, SYSTEM group).
 *
 * Staff only: a tenant account gets a 404 through the shared staff guard, the
 * same way every other console page behaves. The API key is write-only: the page
 * renders a masked field and only the last four characters, and no action ever
 * returns or logs the key.
 */
final class AiEngineController extends Controller
{
    /** Settings page: configuration form, status and the Test connection action. */
    public function show(array $params): never
    {
        $user = $this->staff();

        View::render('pages/admin/ai', [
            'user' => $user,
            'settings' => AiEngineService::publicSettings(),
            'errors' => Session::takeErrors(),
            'taskBadge' => PlatformConsoleService::taskBadge((string) $user['id']),
        ], 'layout_admin');
    }

    /** Save settings (CSRF and the method are enforced by the front controller). */
    public function save(array $params): never
    {
        $user = $this->staff();

        try {
            AiEngineService::save((string) $user['id'], Http::all());
            $this->flash('success', t('flash.ai_settings_saved'));
        } catch (AppError $error) {
            if ($error->fields !== []) {
                // Never remember the API key: the masked field is write-only, so
                // the plaintext key must not be parked in the session either.
                $safeOld = Http::all();
                unset($safeOld['api_key'], $safeOld['_csrf']);
                $this->rememberInput($safeOld);
                Session::errors($error->fields);
                $this->flash('error', I18n::error('validation_error'));
            } else {
                $this->flash('error', $this->errorMessage($error));
            }
        } catch (\Throwable $error) {
            error_log('[pdd] ai engine settings save failed: ' . $error->getMessage());
            $this->flash('error', t('common.something_wrong'));
        }

        Http::redirect('/admin/ai');
    }

    /** The only staff action that makes a real request to the provider. */
    public function testConnection(array $params): never
    {
        $user = $this->staff();

        try {
            $result = AiEngineService::testConnection((string) $user['id']);
            $this->flash('success', t('flash.ai_connection_ok', ['model' => $result['model']]));
        } catch (AppError $error) {
            $this->flash('error', $this->errorMessage($error));
        } catch (\Throwable $error) {
            error_log('[pdd] ai engine connection test failed: ' . $error->getMessage());
            $this->flash('error', t('common.something_wrong'));
        }

        Http::redirect('/admin/ai');
    }

    /** @return array<string, mixed> */
    private function staff(): array
    {
        $user = $this->requireUser();
        TaskCenterService::assertStaff((string) $user['id']);

        return $user;
    }

    /** Translate an AppError without ever rendering provider detail or secrets. */
    private function errorMessage(AppError $error): string
    {
        $reasonKey = $error->fields['reason_key'] ?? null;

        return is_string($reasonKey) && $reasonKey !== '' ? t($reasonKey) : I18n::error($error->errorCode);
    }
}
