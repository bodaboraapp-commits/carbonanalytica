<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AppError;
use App\Http;
use App\I18n;
use App\Services\AiEngineService;
use App\Services\AiEngineTestbenchService;
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
 *
 * The page also hosts the ephemeral test bench and the few-shot example library
 * (in-context skill tuning, not model fine-tuning). Test-bench output lives only
 * in the session for the next render; nothing is written to drafts or projects.
 */
final class AiEngineController extends Controller
{
    /**
     * Server-side tabs: plain query strings so every tab is deep-linkable and
     * works without JavaScript. Anything unknown falls back to overview.
     */
    public const TABS = ['overview', 'settings', 'skill', 'bench'];

    /** PRG action => the tab the user returns to. */
    private const ACTION_TABS = [
        'test_connection' => 'overview',
        'bench_run' => 'bench',
        'example_save' => 'bench',
        'example_toggle' => 'bench',
        'example_delete' => 'bench',
    ];

    /** Validate a `?tab=` value (invalid/missing → overview). */
    public static function normaliseTab(mixed $raw): string
    {
        return is_string($raw) && in_array($raw, self::TABS, true) ? $raw : 'overview';
    }

    /**
     * Which tab a settings/skills save came from. The frontend can send a hidden
     * `_tab=settings|skill`; otherwise the presence of skill inputs (or an
     * uploaded skill document) identifies the skills form.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public static function saveOrigin(array $post, array $files): string
    {
        $tab = (string) ($post['_tab'] ?? '');
        if ($tab === 'settings' || $tab === 'skill') {
            return $tab;
        }

        foreach (array_keys(AiEngineService::SKILL_UPLOAD_FIELDS) as $skillField) {
            if (array_key_exists($skillField, $post)) {
                return 'skill';
            }
        }
        foreach (AiEngineService::SKILL_UPLOAD_FIELDS as $uploadField) {
            if (isset($files[$uploadField]) && is_array($files[$uploadField])) {
                return 'skill';
            }
        }

        return 'settings';
    }

    /**
     * PRG redirect target for an action: always `/admin/ai?tab=…`, preserving the
     * existing bench anchors. Query-only tabs; no new routes.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public static function redirectPath(string $action, array $post = [], array $files = []): string
    {
        $tab = $action === 'save'
            ? self::saveOrigin($post, $files)
            : (self::ACTION_TABS[$action] ?? 'overview');

        $anchor = match ($action) {
            'bench_run' => '#test-bench',
            'example_save', 'example_toggle', 'example_delete' => '#examples',
            default => '',
        };

        return '/admin/ai?tab=' . $tab . $anchor;
    }

    /** Settings page: status header + the active server-side tab. */
    public function show(array $params): never
    {
        $user = $this->staff();
        $userId = (string) $user['id'];
        $activeTab = self::normaliseTab(Http::queryString('tab'));

        // Ephemeral bench result / repopulated form: read once, then forget.
        $benchResult = Session::get('_ai_bench_result');
        $benchForm = Session::get('_ai_bench_form');
        Session::forget('_ai_bench_result');
        Session::forget('_ai_bench_form');

        View::render('pages/admin/ai', [
            'user' => $user,
            'activeTab' => $activeTab,
            'settings' => AiEngineService::publicSettings(),
            // Dynamic Skill tab data: per-template/version SKILL.md rows grouped
            // by registry, registry fallbacks and global instructions.
            'skills' => AiEngineService::skillTabPayload(),
            'errors' => Session::takeErrors(),
            'bench' => [
                'result' => is_array($benchResult) ? $benchResult : null,
                'form' => is_array($benchForm) ? $benchForm : [],
                'examples' => AiEngineTestbenchService::examples($userId),
                'registries' => AiEngineTestbenchService::REGISTRIES,
                'max_input_chars' => AiEngineTestbenchService::MAX_INPUT_CHARS,
                'max_upload_kb' => (int) (AiEngineTestbenchService::MAX_UPLOAD_BYTES / 1024),
                'budget_chars' => AiEngineTestbenchService::MAX_EXAMPLE_PROMPT_CHARS,
                'max_examples' => AiEngineTestbenchService::MAX_EXAMPLES_IN_PROMPT,
            ],
            'taskBadge' => PlatformConsoleService::taskBadge($userId),
        ], 'layout_admin');
    }

    /** Save settings (CSRF and the method are enforced by the front controller). */
    public function save(array $params): never
    {
        $user = $this->staff();
        $input = Http::all();

        try {
            // Uploaded .md skill documents replace the matching textarea on save.
            $input = AiEngineService::applySkillUploads($input, $_FILES);
            AiEngineService::save((string) $user['id'], $input);
            $this->flash('success', t('flash.ai_settings_saved'));
        } catch (AppError $error) {
            if ($error->fields !== []) {
                // Never remember the API key: the masked field is write-only, so
                // the plaintext key must not be parked in the session either.
                $safeOld = $input;
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

        Http::redirect(self::redirectPath('save', Http::all(), $_FILES));
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

        Http::redirect(self::redirectPath('test_connection'));
    }

    /**
     * Run one staff sample through the engine. Ephemeral: the result is kept in
     * the session for the next render only (never a draft, never a project).
     */
    public function runTest(array $params): never
    {
        $user = $this->staff();
        $registry = (string) (Http::input('registry') ?? '');

        try {
            $inputText = AiEngineTestbenchService::inputFromRequest($_POST, $_FILES);
            $expected = trim((string) (Http::input('expected_output') ?? ''));
            $result = AiEngineTestbenchService::run((string) $user['id'], $registry, $inputText, $expected);

            // Session-only: lets the page offer "save as example" for this pair.
            Session::set('_ai_bench_result', [
                'output' => $result['output'],
                'model' => $result['model'],
                'latency_ms' => $result['latency_ms'],
                'registry' => $result['registry'],
                'input_text' => $inputText,
                'expected_output' => $expected,
            ]);
            $this->flash('success', t('flash.ai_test_run_ok', [
                'model' => $result['model'],
                'ms' => $result['latency_ms'],
            ]));
        } catch (AppError $error) {
            if ($error->fields !== []) {
                Session::errors($error->fields);
                Session::set('_ai_bench_form', [
                    'registry' => $registry,
                    'input_text' => (string) (Http::input('input_text') ?? ''),
                    'expected_output' => (string) (Http::input('expected_output') ?? ''),
                ]);
                $this->flash('error', I18n::error('validation_error'));
            } else {
                $this->flash('error', $this->errorMessage($error));
            }
        } catch (\Throwable $error) {
            error_log('[pdd] ai engine bench run failed: ' . $error->getMessage());
            $this->flash('error', t('common.something_wrong'));
        }

        Http::redirect(self::redirectPath('bench_run'));
    }

    /** Save a reviewed input/output pair as a few-shot example for a registry. */
    public function saveExample(array $params): never
    {
        $user = $this->staff();

        try {
            AiEngineTestbenchService::saveExample((string) $user['id'], Http::all());
            $this->flash('success', t('flash.ai_example_saved'));
        } catch (AppError $error) {
            if ($error->fields !== []) {
                $safeOld = Http::all();
                unset($safeOld['_csrf']);
                $this->rememberInput($safeOld);
                Session::errors($error->fields);
                $this->flash('error', I18n::error('validation_error'));
            } else {
                $this->flash('error', $this->errorMessage($error));
            }
        } catch (\Throwable $error) {
            error_log('[pdd] ai engine example save failed: ' . $error->getMessage());
            $this->flash('error', t('common.something_wrong'));
        }

        Http::redirect(self::redirectPath('example_save'));
    }

    /** Enable or disable one example. */
    public function toggleExample(array $params): never
    {
        $user = $this->staff();
        $enabled = (string) (Http::input('enabled') ?? '') === '1';

        try {
            AiEngineTestbenchService::toggleExample((string) $user['id'], (string) ($params['id'] ?? ''), $enabled);
            $this->flash('success', $enabled ? t('flash.ai_example_enabled') : t('flash.ai_example_disabled'));
        } catch (AppError $error) {
            $this->flash('error', $this->errorMessage($error));
        } catch (\Throwable $error) {
            error_log('[pdd] ai engine example toggle failed: ' . $error->getMessage());
            $this->flash('error', t('common.something_wrong'));
        }

        Http::redirect(self::redirectPath('example_toggle'));
    }

    /** Delete one example. */
    public function deleteExample(array $params): never
    {
        $user = $this->staff();

        try {
            AiEngineTestbenchService::deleteExample((string) $user['id'], (string) ($params['id'] ?? ''));
            $this->flash('success', t('flash.ai_example_deleted'));
        } catch (AppError $error) {
            $this->flash('error', $this->errorMessage($error));
        } catch (\Throwable $error) {
            error_log('[pdd] ai engine example delete failed: ' . $error->getMessage());
            $this->flash('error', t('common.something_wrong'));
        }

        Http::redirect(self::redirectPath('example_delete'));
    }

    /** @return array<string, mixed> */
    private function staff(): array
    {
        $user = $this->requireUser();
        TaskCenterService::assertStaff((string) $user['id']);

        return $user;
    }

    /**
     * Translate an AppError and append the client's sanitised provider detail
     * (never the key, headers or raw body) so the admin can act on it.
     */
    private function errorMessage(AppError $error): string
    {
        $reasonKey = $error->fields['reason_key'] ?? null;
        $message = is_string($reasonKey) && $reasonKey !== '' ? t($reasonKey) : I18n::error($error->errorCode);

        $detail = $error->fields['detail'] ?? null;
        if (is_string($detail) && $detail !== '') {
            $message .= ' ' . t('ai.provider_detail', ['detail' => $detail]);
        }

        return $message;
    }
}
