<?php

declare(strict_types=1);

use App\Db;
use App\Repositories\UserRepository;
use App\Services\PlatformConsoleService;
use App\Services\ProjectService;
use App\Services\TemplateLibraryService;
use App\Storage;
use App\Uuid;
use App\View;

/**
 * Step-2 template library management: private source upload, publish/retire and
 * version deletion. Every write is staff-only and audited.
 */

function tl_make_staff(string $email = 'tl-staff@example.com'): array
{
    $account = make_user($email, 'Template Library Staff');
    Db::execute('UPDATE users SET is_platform_admin = 1 WHERE id = ?', [(string) $account['user']['id']]);
    $account['user'] = UserRepository::findById((string) $account['user']['id']);
    return $account;
}

/**
 * Create a template + version through real rows; optionally give it one section
 * and one field so the delete cascade can be asserted.
 *
 * @return array{template_id: string, version_id: string, section_id: string|null}
 */
function tl_make_version(
    string $versionLabel = 'v1.0',
    string $status = 'draft',
    bool $withSection = false,
    string $templateName = 'Template Library Test',
): array {
    $templateId = Uuid::v4();
    Db::insert('templates', [
        'id' => $templateId,
        'name' => $templateName,
        'kind' => 'pdd_template',
        'registry' => 'gold_standard',
        'language' => 'en',
    ]);

    $versionId = Uuid::v4();
    Db::insert('template_versions', [
        'id' => $versionId,
        'template_id' => $templateId,
        'version_label' => $versionLabel,
        'language' => 'en',
        'status' => $status,
    ]);

    $sectionId = null;
    if ($withSection) {
        $sectionId = Uuid::v4();
        Db::insert('template_sections', [
            'id' => $sectionId,
            'template_version_id' => $versionId,
            'ordinal' => 1,
            'stable_key' => 'A',
            'heading' => 'Description',
            'level' => 1,
        ]);
        Db::insert('template_fields', [
            'id' => Uuid::v4(),
            'template_section_id' => $sectionId,
            'ordinal' => 1,
            'field_key' => 'project_name',
            'label' => 'Project name',
            'field_type' => 'text',
        ]);
    }

    return ['template_id' => $templateId, 'version_id' => $versionId, 'section_id' => $sectionId];
}

/**
 * Store a real local file through the shared Storage validation, exactly like
 * the upload endpoint does after PHP accepts the multipart request.
 *
 * @return array{key: string, filename: string, mime_type: string, size_bytes: int, checksum_sha256: string, extension: string}
 */
function tl_store_file(string $extension = 'txt', string $content = "Template source document\n"): array
{
    $directory = APP_ROOT . '/storage/tests';
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $path = $directory . '/' . Uuid::v4() . '.' . $extension;
    file_put_contents($path, $content);

    return Storage::putLocalFile($path, 'template-source.' . $extension, 'templates', TemplateLibraryService::SOURCE_EXTENSIONS);
}

function tl_temp_path(string $extension, string $content): string
{
    $directory = APP_ROOT . '/storage/tests';
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $path = $directory . '/' . Uuid::v4() . '.' . $extension;
    file_put_contents($path, $content);

    return $path;
}

return [
    'uploads a private source document with real metadata, checksum and audit' => static function (): void {
        $staff = tl_make_staff();
        $staffId = (string) $staff['user']['id'];
        $version = tl_make_version('v1.0', 'draft');
        $stored = tl_store_file('txt', "Gold Standard template source\n");

        try {
            TemplateLibraryService::attachStoredSource($staffId, $version['version_id'], $stored);

            $row = Db::selectOne('SELECT * FROM template_versions WHERE id = ?', [$version['version_id']]);
            assert_not_null($row);
            assert_same($stored['key'], (string) $row['storage_key'], 'the private storage key is persisted');
            assert_same($stored['filename'], (string) $row['source_filename']);
            assert_same((string) $stored['mime_type'], (string) $row['source_mime_type']);
            assert_same((int) $stored['size_bytes'], (int) $row['source_size_bytes']);
            assert_same(hash('sha256', "Gold Standard template source\n"), (string) $row['checksum_sha256'], 'the checksum matches the real bytes');
            assert_true(Storage::exists((string) $row['storage_key']), 'the source file is stored privately');
            assert_same(1, count_rows('audit_logs', "action = 'template_version.source_uploaded'"), 'the upload is audited');
        } finally {
            Storage::delete((string) $stored['key']);
        }

        // Executables/HTML/SVG are rejected by the shared Storage validation.
        $htmlPath = tl_temp_path('html', '<html><body>not a template</body></html>');
        try {
            assert_app_error('validation_error', static function () use ($htmlPath): void {
                Storage::putLocalFile($htmlPath, 'evil.html', 'templates', TemplateLibraryService::SOURCE_EXTENSIONS);
            }, 'HTML must not be accepted as a template source');
        } finally {
            if (is_file($htmlPath)) {
                unlink($htmlPath);
            }
        }
    },

    'replaces the previous source file and keeps only the new one' => static function (): void {
        $staff = tl_make_staff('tl-replace@example.com');
        $staffId = (string) $staff['user']['id'];
        $version = tl_make_version('v1.0', 'draft');
        $first = tl_store_file('txt', "first source\n");
        $second = null;

        try {
            TemplateLibraryService::attachStoredSource($staffId, $version['version_id'], $first);
            $second = tl_store_file('md', "# second source\n");
            TemplateLibraryService::attachStoredSource($staffId, $version['version_id'], $second);

            $row = Db::selectOne('SELECT storage_key, source_filename FROM template_versions WHERE id = ?', [$version['version_id']]);
            assert_not_null($row);
            assert_same($second['key'], (string) $row['storage_key'], 'the new source replaces the old key');
            assert_same('template-source.md', (string) $row['source_filename']);
            assert_false(Storage::exists((string) $first['key']), 'the replaced source file is removed from storage');
            assert_same(2, count_rows('audit_logs', "action = 'template_version.source_uploaded'"), 'each replace is audited');
        } finally {
            Storage::delete((string) $first['key']);
            if ($second !== null) {
                Storage::delete((string) $second['key']);
            }
        }
    },

    'publishes only with a stored source, then retires with real timestamps' => static function (): void {
        $staff = tl_make_staff('tl-status@example.com');
        $staffId = (string) $staff['user']['id'];
        $version = tl_make_version('v1.0', 'draft');

        assert_app_error('template_source_required', static function () use ($staffId, $version): void {
            TemplateLibraryService::updateStatus($staffId, $version['version_id'], 'published');
        }, 'a draft without a source file cannot be published');

        $stored = tl_store_file('pdf', "%PDF-1.4 test template\n");
        try {
            TemplateLibraryService::attachStoredSource($staffId, $version['version_id'], $stored);

            $published = TemplateLibraryService::updateStatus($staffId, $version['version_id'], 'published');
            assert_same('published', (string) $published['status']);
            assert_not_null($published['published_at'], 'publishing records published_at');
            assert_same(1, count_rows('audit_logs', "action = 'template_version.published'"));

            assert_app_error('template_status_unchanged', static function () use ($staffId, $version): void {
                TemplateLibraryService::updateStatus($staffId, $version['version_id'], 'published');
            }, 'publishing an already published version is refused');
            assert_app_error('template_status_invalid', static function () use ($staffId, $version): void {
                TemplateLibraryService::updateStatus($staffId, $version['version_id'], 'draft');
            }, 'published → draft is not an allowed transition');

            $retired = TemplateLibraryService::updateStatus($staffId, $version['version_id'], 'retired');
            assert_same('retired', (string) $retired['status']);
            assert_not_null($retired['retired_at'], 'retiring records retired_at');
            assert_same(1, count_rows('audit_logs', "action = 'template_version.retired'"));

            assert_app_error('template_status_invalid', static function () use ($staffId, $version): void {
                TemplateLibraryService::updateStatus($staffId, $version['version_id'], 'published');
            }, 'retired → published is not an allowed transition');
        } finally {
            Storage::delete((string) $stored['key']);
        }
    },

    'deletes a draft version with its sections, fields and stored file' => static function (): void {
        $staff = tl_make_staff('tl-delete@example.com');
        $staffId = (string) $staff['user']['id'];
        $version = tl_make_version('v0.9', 'draft', true);
        $stored = tl_store_file();

        try {
            TemplateLibraryService::attachStoredSource($staffId, $version['version_id'], $stored);
            assert_same(1, count_rows('template_sections', 'template_version_id = ?', [$version['version_id']]));
            assert_same(1, count_rows('template_fields', 'template_section_id = ?', [$version['section_id']]));

            TemplateLibraryService::deleteVersion($staffId, $version['version_id']);

            assert_same(0, count_rows('template_versions', 'id = ?', [$version['version_id']]), 'the version row is gone');
            assert_same(0, count_rows('template_sections', 'template_version_id = ?', [$version['version_id']]), 'sections cascade');
            assert_same(0, count_rows('template_fields', 'template_section_id = ?', [$version['section_id']]), 'fields cascade');
            assert_false(Storage::exists((string) $stored['key']), 'the stored source file is removed');
            assert_same(1, count_rows('audit_logs', "action = 'template_version.deleted'"), 'the deletion is audited');
        } finally {
            Storage::delete((string) $stored['key']);
        }
    },

    'refuses to delete a published version' => static function (): void {
        $staff = tl_make_staff('tl-delete-published@example.com');
        $staffId = (string) $staff['user']['id'];
        $version = tl_make_version('v1.0', 'draft');
        $stored = tl_store_file();

        try {
            TemplateLibraryService::attachStoredSource($staffId, $version['version_id'], $stored);
            TemplateLibraryService::updateStatus($staffId, $version['version_id'], 'published');

            assert_app_error('template_delete_published', static function () use ($staffId, $version): void {
                TemplateLibraryService::deleteVersion($staffId, $version['version_id']);
            }, 'a published version cannot be deleted');
            assert_same(1, count_rows('template_versions', 'id = ?', [$version['version_id']]));
        } finally {
            Storage::delete((string) $stored['key']);
        }
    },

    'refuses to delete a version referenced by a project or draft' => static function (): void {
        $staff = tl_make_staff('tl-delete-in-use@example.com');
        $staffId = (string) $staff['user']['id'];
        $owner = make_user('tl-in-use-owner@example.com');
        $ownerId = (string) $owner['user']['id'];
        $teamId = (string) $owner['team']['id'];

        // Referenced by a project.
        $byProject = tl_make_version('v1.0', 'draft', false, 'In-use project template');
        $project = ProjectService::create($ownerId, $teamId, 'Template library referenced project');
        $projectId = (string) $project['id'];
        Db::execute('UPDATE projects SET template_version_id = ? WHERE id = ?', [$byProject['version_id'], $projectId]);

        assert_app_error('template_delete_in_use', static function () use ($staffId, $byProject): void {
            TemplateLibraryService::deleteVersion($staffId, $byProject['version_id']);
        }, 'a version referenced by a project cannot be deleted');
        assert_same(1, count_rows('template_versions', 'id = ?', [$byProject['version_id']]));

        // Referenced by a draft (real project + approved fact sheet + draft rows).
        $byDraft = tl_make_version('v1.1', 'draft', false, 'In-use draft template');
        $factSheetId = Uuid::v4();
        Db::insert('fact_sheet_versions', [
            'id' => $factSheetId,
            'project_id' => $projectId,
            'version' => 1,
            'status' => 'approved',
            'snapshot' => '{}',
            'approved_at' => gmdate('Y-m-d H:i:s'),
        ]);
        Db::insert('drafts', [
            'id' => Uuid::v4(),
            'project_id' => $projectId,
            'team_id' => $teamId,
            'template_version_id' => $byDraft['version_id'],
            'fact_sheet_version_id' => $factSheetId,
            'language' => 'en',
            'status' => 'draft',
            'is_labeled_draft' => 'draft',
        ]);

        assert_app_error('template_delete_in_use', static function () use ($staffId, $byDraft): void {
            TemplateLibraryService::deleteVersion($staffId, $byDraft['version_id']);
        }, 'a version referenced by a draft cannot be deleted');
        assert_same(1, count_rows('template_versions', 'id = ?', [$byDraft['version_id']]));
    },

    'template library actions are staff-only and tenant accounts get a 404' => static function (): void {
        $tenant = make_user('tl-tenant@example.com');
        $tenantId = (string) $tenant['user']['id'];
        $version = tl_make_version('v1.0', 'draft');
        $stored = tl_store_file();

        try {
            assert_app_error('not_found', static function () use ($tenantId, $version, $stored): void {
                TemplateLibraryService::attachStoredSource($tenantId, $version['version_id'], $stored);
            }, 'a tenant account cannot upload a template source');
            assert_app_error('not_found', static function () use ($tenantId, $version): void {
                TemplateLibraryService::updateStatus($tenantId, $version['version_id'], 'published');
            }, 'a tenant account cannot change template status');
            assert_app_error('not_found', static function () use ($tenantId, $version): void {
                TemplateLibraryService::deleteVersion($tenantId, $version['version_id']);
            }, 'a tenant account cannot delete a template version');
            assert_same('draft', (string) Db::selectOne('SELECT status FROM template_versions WHERE id = ?', [$version['version_id']])['status']);
        } finally {
            Storage::delete((string) $stored['key']);
        }
    },

    'templates page renders the Manage column, disclosure and dialog forms' => static function (): void {
        $staff = tl_make_staff('tl-render@example.com');
        $staffId = (string) $staff['user']['id'];

        $draft = tl_make_version('v0.9', 'draft', true, 'Render Draft Template');
        $published = tl_make_version('v1.0', 'published', false, 'Render Published Template');
        $retired = tl_make_version('v0.8', 'retired', false, 'Render Retired Template');
        Db::execute('UPDATE template_versions SET published_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $published['version_id']]);

        $markup = View::capture('pages/admin/templates', [
            'user' => $staff['user'],
            'templates' => PlatformConsoleService::templates($staffId),
            'taskBadge' => 0,
        ]);

        // The old "Published" table header is replaced by "Manage".
        assert_same(1, preg_match_all('/<th scope="col">' . preg_quote(t('console.table_manage'), '/') . '<\/th>/', $markup), 'the last table header must be Manage');
        assert_false(str_contains($markup, '<th scope="col">' . t('console.table_published') . '</th>'), 'the Published column header is gone');

        // Published date stays inside the Manage cell.
        assert_true(str_contains($markup, format_date((string) Db::selectOne('SELECT published_at FROM template_versions WHERE id = ?', [$published['version_id']])['published_at'])), 'the published date stays in the Manage cell');

        // One disclosure panel per row plus the shared dialog shell.
        assert_same(3, substr_count($markup, 'data-manage>'), 'one Manage disclosure per template version');
        assert_same(3, substr_count($markup, 'data-manage-panel'), 'one manage panel per template version');
        assert_contains('id="template-manage-dialog"', $markup, 'the dialog shell must render');
        assert_contains('data-manage-host', $markup, 'the dialog needs a host for the enhanced panel');
        assert_contains('data-dialog-close', $markup, 'the dialog needs a close control');
        assert_contains('enctype="multipart/form-data"', $markup, 'the upload form must be multipart');
        assert_contains('name="source"', $markup, 'the upload form needs a source file field');
        assert_contains('name="_csrf"', $markup, 'every management form is CSRF-protected');
        assert_contains('/admin/templates/' . $draft['version_id'] . '/source', $markup, 'the upload action points at the version');
        assert_contains('/admin/templates/' . $draft['version_id'] . '/status', $markup, 'a draft can be published');
        assert_contains('/admin/templates/' . $draft['version_id'] . '/delete', $markup, 'a draft can be deleted');
        assert_false(str_contains($markup, '/admin/templates/' . $published['version_id'] . '/delete'), 'a published version cannot be deleted');
        assert_contains('/admin/templates/' . $published['version_id'] . '/status', $markup, 'a published version can be retired');
        assert_false(str_contains($markup, '/admin/templates/' . $retired['version_id'] . '/status'), 'a retired version has no transition');
    },
];
