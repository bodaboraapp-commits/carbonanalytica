<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AppError;
use App\Http;
use App\I18n;
use App\Services\PlatformConsoleService;
use App\Services\PlatformSettingsService;
use App\Services\TaskCenterService;
use App\View;

/**
 * Fresh platform console: overview, workspaces, KYC queue, users, templates and
 * the audit trail. Every page is staff-only; tenant dashboards live elsewhere.
 */
final class AdminConsoleController extends Controller
{
    public function overview(array $params): never
    {
        $user = $this->staff();

        View::render('pages/admin/overview', [
            'user' => $user,
            'overview' => PlatformConsoleService::overview(
                (string) $user['id'],
                (string) (Http::queryString('range') ?? '7'),
            ),
            'taskBadge' => PlatformConsoleService::taskBadge((string) $user['id']),
        ], 'layout_admin');
    }

    /** Dedicated System Health page (SYSTEM group). */
    public function system(array $params): never
    {
        $user = $this->staff();

        View::render('pages/admin/system', [
            'user' => $user,
            'system' => PlatformConsoleService::systemHealth((string) $user['id']),
            'totals' => PlatformConsoleService::platformTotals((string) $user['id']),
            'taskBadge' => PlatformConsoleService::taskBadge((string) $user['id']),
        ], 'layout_admin');
    }

    /** AI Engine page (SYSTEM group): honest not-configured placeholder. */
    public function ai(array $params): never
    {
        $user = $this->staff();

        View::render('pages/admin/ai', [
            'user' => $user,
            'taskBadge' => PlatformConsoleService::taskBadge((string) $user['id']),
        ], 'layout_admin');
    }

    public function workspaces(array $params): never
    {
        $user = $this->staff();

        View::render('pages/admin/workspaces', [
            'user' => $user,
            'workspaces' => PlatformConsoleService::workspaces((string) $user['id'], [
                'q' => (string) (Http::queryString('q') ?? ''),
                'kyc' => (string) (Http::queryString('kyc') ?? ''),
                'page' => (int) (Http::queryString('page') ?? 1),
            ]),
            'analytics' => PlatformConsoleService::workspaceAnalytics((string) $user['id']),
            'taskBadge' => PlatformConsoleService::taskBadge((string) $user['id']),
        ], 'layout_admin');
    }

    public function kyc(array $params): never
    {
        $user = $this->staff();

        View::render('pages/admin/kyc', [
            'user' => $user,
            'kyc' => PlatformConsoleService::kycQueue((string) $user['id']),
            'taskBadge' => PlatformConsoleService::taskBadge((string) $user['id']),
        ], 'layout_admin');
    }

    public function users(array $params): never
    {
        $user = $this->staff();

        View::render('pages/admin/users', [
            'user' => $user,
            'users' => PlatformConsoleService::users((string) $user['id'], [
                'q' => (string) (Http::queryString('q') ?? ''),
                'admin' => (string) (Http::queryString('admin') ?? ''),
                'new' => (string) (Http::queryString('new') ?? ''),
                'page' => (int) (Http::queryString('page') ?? 1),
            ]),
            'analytics' => PlatformConsoleService::userAnalytics((string) $user['id']),
            'taskBadge' => PlatformConsoleService::taskBadge((string) $user['id']),
        ], 'layout_admin');
    }

    public function templates(array $params): never
    {
        $user = $this->staff();

        View::render('pages/admin/templates', [
            'user' => $user,
            'templates' => PlatformConsoleService::templates((string) $user['id']),
            'taskBadge' => PlatformConsoleService::taskBadge((string) $user['id']),
        ], 'layout_admin');
    }

    public function audit(array $params): never
    {
        $user = $this->staff();

        // Leading "Recent activity" panel: the latest unfiltered entries, five
        // per page, independent of the audit table's action filter and paging.
        $recentPage = max(1, (int) (Http::queryString('recent_page') ?? 1));
        $recent = PlatformConsoleService::audit((string) $user['id'], [
            'page' => $recentPage,
            'per_page' => 5,
        ]);

        View::render('pages/admin/audit', [
            'user' => $user,
            'audit' => PlatformConsoleService::audit((string) $user['id'], [
                'action' => (string) (Http::queryString('action') ?? ''),
                'page' => (int) (Http::queryString('page') ?? 1),
                // The audit table pages five rows at a time (matching Recent activity).
                'per_page' => 5,
            ]),
            'recent_audit' => $recent['rows'],
            'recent_page' => $recent['page'],
            'recent_pages' => $recent['pages'],
            'recent_total' => $recent['total'],
            'taskBadge' => PlatformConsoleService::taskBadge((string) $user['id']),
        ], 'layout_admin');
    }

    /** Grant or revoke platform-admin access for an account. */
    public function togglePlatformAdmin(array $params): never
    {
        $user = $this->staff();

        $enabled = (string) (Http::input('enabled') ?? '') === '1';

        try {
            PlatformConsoleService::setPlatformAdmin((string) $user['id'], (string) $params['id'], $enabled);
            $this->flash('success', $enabled ? t('flash.platform_admin_granted') : t('flash.platform_admin_revoked'));
        } catch (AppError $error) {
            $this->flash('error', I18n::error($error->errorCode));
        }

        Http::redirect('/admin/users');
    }

    /** Turn a platform-wide control (maintenance mode, sign-ups) on or off. */
    public function toggleSetting(array $params): never
    {
        $user = $this->staff();

        $key = (string) ($params['key'] ?? '');
        if (!in_array($key, PlatformSettingsService::editableKeys(), true)) {
            Http::redirect('/admin');
        }

        $enabled = (string) (Http::input('value') ?? '') === '1';

        try {
            PlatformSettingsService::setEnabled((string) $user['id'], $key, $enabled);
            $this->flash('success', t($enabled ? 'flash.setting_enabled' : 'flash.setting_disabled', [
                'setting' => t('super.' . $key),
            ]));
        } catch (AppError $error) {
            $this->flash('error', I18n::error($error->errorCode));
        } catch (\Throwable $error) {
            error_log('[pdd] toggle setting failed: ' . $error->getMessage());
            $this->flash('error', t('flash.setting_missing'));
        }

        Http::redirect('/admin');
    }

    /** @return array<string, mixed> */
    private function staff(): array
    {
        $user = $this->requireUser();
        TaskCenterService::assertStaff((string) $user['id']);
        return $user;
    }
}
