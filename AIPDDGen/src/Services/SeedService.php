<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\I18n;
use App\Repositories\TeamRepository;
use App\Repositories\UserRepository;
use App\Uuid;

/**
 * Idempotent seed data, shared by the CLI (`bin/seed.php`) and the staff console
 * "Update database" page (`/admin/database`).
 *
 * Safe to run repeatedly: every step checks for existing rows first.
 */
final class SeedService
{
    public const DEMO_EMAIL = 'demo@pddgen.local';
    public const DEMO_PASSWORD = 'DemoPassw0rd!123';
    public const ADMIN_EMAIL = 'admin@pddgen.local';
    public const ADMIN_PASSWORD = 'AdminPassw0rd!123';

    /**
     * @return array{lines: list<string>, demo: array{email: string, password: string}, admin: array{email: string, password: string}}
     */
    public static function run(bool $withDemo = true): array
    {
        $lines = [];

        $lines[] = self::seedMethodologies();
        $lines[] = self::seedGoldStandardTemplate();

        if (!$withDemo) {
            $lines[] = 'demo data: skipped';
            return [
                'lines' => $lines,
                'demo' => ['email' => self::DEMO_EMAIL, 'password' => self::DEMO_PASSWORD],
                'admin' => ['email' => self::ADMIN_EMAIL, 'password' => self::ADMIN_PASSWORD],
            ];
        }

        $demo = self::seedDemoWorkspace($lines);
        self::seedStaffConsole($lines, $demo['user'], $demo['team']);

        $lines[] = 'Seed complete.';

        return [
            'lines' => $lines,
            'demo' => ['email' => self::DEMO_EMAIL, 'password' => self::DEMO_PASSWORD],
            'admin' => ['email' => self::ADMIN_EMAIL, 'password' => self::ADMIN_PASSWORD],
        ];
    }

    private static function seedMethodologies(): string
    {
        $methodologies = [
            ['gold_standard', 'AMS-I.C', 'Thermal energy for the user with or without electricity', 'v21.0'],
            ['gold_standard', 'AMS-II.G', 'Energy efficiency measures in thermal applications of non-renewable biomass', 'v13.0'],
            ['gold_standard', 'AR-AMS0007', 'Afforestation and reforestation on lands other than wetlands', 'v03.1'],
            ['verra_vcs', 'VM0007', 'REDD+ Methodology Framework (REDD-MF)', 'v1.6'],
            ['verra_vcs', 'VM0015', 'Methodology for Avoided Unplanned Deforestation', 'v1.1'],
            ['verra_vcs', 'VM0009', 'Methodology for Avoided Ecosystem Conversion', 'v3.0'],
        ];

        $inserted = 0;
        foreach ($methodologies as [$registry, $code, $name, $version]) {
            $existing = Db::selectOne(
                'SELECT id FROM methodologies WHERE registry = ? AND code = ? AND version = ? AND language = ?',
                [$registry, $code, $version, 'en'],
            );
            if ($existing !== null) {
                continue;
            }
            Db::insert('methodologies', [
                'id' => Uuid::v4(),
                'registry' => $registry,
                'code' => $code,
                'name' => $name,
                'version' => $version,
                'language' => 'en',
                'is_active' => true,
                'is_provisional' => true,
                'notes' => 'Launch shortlist pending product-owner confirmation (docs/open-questions.md).',
            ]);
            $inserted++;
        }

        return "methodologies: {$inserted} added, " . count($methodologies) . ' in catalogue';
    }

    private static function seedGoldStandardTemplate(): string
    {
        $templateName = 'Gold Standard Template Guide v1.4';
        $template = Db::selectOne('SELECT * FROM templates WHERE name = ? LIMIT 1', [$templateName]);

        if ($template !== null) {
            return 'template: already seeded';
        }

        $templateId = Uuid::v4();
        Db::insert('templates', [
            'id' => $templateId,
            'name' => $templateName,
            'kind' => 'pdd_template',
            'registry' => 'gold_standard',
            'methodology_code' => null,
            'language' => 'en',
            'description' => 'Key Project Information and Project Design Document structure.',
        ]);

        $versionId = Uuid::v4();
        Db::insert('template_versions', [
            'id' => $versionId,
            'template_id' => $templateId,
            'version_label' => 'v1.4',
            'language' => 'en',
            'effective_date' => '2023-06-21',
            'status' => 'draft',
            'source_url' => 'https://globalgoals.goldstandard.org/standards/TGuide-PreReview_V1.4-Project-Design-Document.pdf',
            'license_note' => 'Source link recorded. Permission to store and use must be confirmed by the owner before publishing (open question).',
        ]);

        $sections = [
            ['KPI', 'Key Project Information', 1, true],
            ['A', 'Description of project', 1, true],
            ['A.1', 'Purpose and general description', 2, true],
            ['A.2', 'Eligibility', 2, true],
            ['A.3', 'Project ownership', 2, true],
            ['A.4', 'Location', 2, true],
            ['A.5', 'Technologies and/or practices', 2, true],
            ['A.6', 'Scale and finance', 2, true],
            ['B', 'Methodology and SDG contributions', 1, true],
            ['B.1', 'Baseline', 2, true],
            ['B.2', 'Boundary', 2, true],
            ['B.3', 'Additionality', 2, true],
            ['B.4', 'Ex ante estimates', 2, true],
            ['B.5', 'Monitoring plan', 2, true],
            ['C', 'Duration and crediting period', 1, true],
            ['D', 'Safeguarding principles and gender summary', 1, true],
            ['E', 'Local stakeholder consultation', 1, true],
            ['APP1', 'Safeguarding assessment', 1, false],
            ['APP2', 'Contacts', 1, false],
            ['APP3', 'Land-use and forests information', 1, false],
            ['APP4', 'Design changes', 1, false],
        ];

        $ordinal = 0;
        foreach ($sections as [$key, $heading, $level, $required]) {
            Db::insert('template_sections', [
                'id' => Uuid::v4(),
                'template_version_id' => $versionId,
                'parent_section_id' => null,
                'ordinal' => ++$ordinal,
                'stable_key' => $key,
                'heading' => $heading,
                'level' => $level,
                'is_required' => $required,
                'allow_intentionally_blank' => true,
                'guidance' => null,
                'format_rules' => json_encode([
                    'date_format' => 'DD/MM/YYYY',
                    'number_format' => 'thousands_comma_decimal_point',
                    'unique_ids_for_figures_and_tables' => true,
                ]),
            ]);
        }

        return 'template: seeded Gold Standard v1.4 structure (draft)';
    }

    /**
     * @param list<string> $lines
     * @return array{user: array<string, mixed>, team: array<string, mixed>}
     */
    private static function seedDemoWorkspace(array &$lines): array
    {
        $demoUser = UserRepository::findByEmail(self::DEMO_EMAIL);

        if ($demoUser !== null) {
            $lines[] = 'demo data: user ' . self::DEMO_EMAIL . ' already exists';
            $team = TeamRepository::find((string) $demoUser['default_team_id']);
        } else {
            $created = AuthService::signUp('Amina Demo', self::DEMO_EMAIL, self::DEMO_PASSWORD, I18n::locale());
            $demoUser = $created['user'];
            $team = $created['team'];
            UserRepository::markEmailVerified((string) $demoUser['id']);
            $lines[] = 'demo data: created ' . self::DEMO_EMAIL;
        }

        if ($team === null) {
            throw new \RuntimeException('Demo workspace is missing for ' . self::DEMO_EMAIL);
        }

        $existingProjects = Db::select(
            'SELECT id, name FROM projects WHERE team_id = ? AND deleted_at IS NULL',
            [(string) $team['id']],
        );

        if ($existingProjects === []) {
            ProjectService::create(
                (string) $demoUser['id'],
                (string) $team['id'],
                'Kisumu Clean Cookstoves',
                'Improved cookstoves for households around Kisumu, Kenya.',
            );
            ProjectService::create(
                (string) $demoUser['id'],
                (string) $team['id'],
                'Rift Valley Reforestation',
                'Afforestation and reforestation on degraded land in the Rift Valley.',
            );
            $lines[] = 'demo data: created 2 projects';
        } else {
            $lines[] = 'demo data: ' . count($existingProjects) . ' project(s) already present';
        }

        return ['user' => $demoUser, 'team' => $team];
    }

    /**
     * @param list<string> $lines
     * @param array<string, mixed> $demoUser
     * @param array<string, mixed> $team
     */
    private static function seedStaffConsole(array &$lines, array $demoUser, array $team): void
    {
        $adminUser = UserRepository::findByEmail(self::ADMIN_EMAIL);

        if ($adminUser === null) {
            $created = AuthService::signUp('Ian Admin', self::ADMIN_EMAIL, self::ADMIN_PASSWORD, 'en');
            Db::execute(
                'UPDATE users SET is_platform_admin = 1, email_verified_at = NOW(3) WHERE id = ?',
                [(string) $created['user']['id']],
            );
            $adminUser = UserRepository::findById((string) $created['user']['id']);
            $lines[] = 'staff: created ' . self::ADMIN_EMAIL . ' (platform admin)';
        } elseif ((int) $adminUser['is_platform_admin'] !== 1) {
            Db::execute('UPDATE users SET is_platform_admin = 1 WHERE id = ?', [(string) $adminUser['id']]);
            $adminUser = UserRepository::findById((string) $adminUser['id']);
            $lines[] = 'staff: promoted ' . self::ADMIN_EMAIL . ' to platform admin';
        } else {
            $lines[] = 'staff: ' . self::ADMIN_EMAIL . ' already a platform admin';
        }

        if ($adminUser === null) {
            return;
        }

        $demoTeamId = (string) $team['id'];
        $demoTeamName = (string) $team['name'];
        $adminId = (string) $adminUser['id'];
        $now = time();

        $taskCount = (int) (Db::selectOne('SELECT COUNT(*) AS total FROM review_tasks')['total'] ?? 0);

        if ($taskCount === 0) {
            $demoTasks = [
                [
                    'work_type' => 'organisation_kyb',
                    'title' => 'Review organisation documents — ' . $demoTeamName,
                    'description' => 'Check registration certificate and ownership structure before verifying the workspace.',
                    'priority' => 'high',
                    'due_at' => gmdate('Y-m-d H:i:s', $now - 2 * 86400),
                    'team_id' => $demoTeamId,
                ],
                [
                    'work_type' => 'project_validation',
                    'title' => 'Validate project boundary — Kisumu Clean Cookstoves',
                    'description' => 'Boundary coordinates and included households need a second pair of eyes.',
                    'priority' => 'urgent',
                    'due_at' => gmdate('Y-m-d H:i:s', $now - 86400),
                    'assigned_user_id' => $adminId,
                    'team_id' => $demoTeamId,
                ],
                [
                    'work_type' => 'template_review',
                    'title' => 'QA the Gold Standard v1.4 template structure',
                    'description' => 'Headings, numbering and table columns must match the published guide.',
                    'priority' => 'normal',
                    'due_at' => gmdate('Y-m-d H:i:s', $now + 5 * 86400),
                    'assigned_user_id' => $adminId,
                    'team_id' => $demoTeamId,
                ],
                [
                    'work_type' => 'fact_sheet_review',
                    'title' => 'Approve fact sheet — Rift Valley Reforestation',
                    'description' => 'Confirm ex-ante estimates and the baseline quotations before drafting.',
                    'priority' => 'normal',
                    'due_at' => gmdate('Y-m-d H:i:s', $now + 3 * 86400),
                    'team_id' => $demoTeamId,
                ],
                [
                    'work_type' => 'draft_review',
                    'title' => 'Review draft sections — Kisumu Clean Cookstoves',
                    'description' => 'Check citations and the two "needs input" markers in section B.',
                    'priority' => 'low',
                    'due_at' => gmdate('Y-m-d H:i:s', $now + 7 * 86400),
                    'assigned_user_id' => $adminId,
                    'team_id' => $demoTeamId,
                ],
            ];

            foreach ($demoTasks as $task) {
                TaskCenterService::createTask($adminId, $task);
            }
            $lines[] = 'staff: seeded ' . count($demoTasks) . ' review tasks';
        } else {
            $lines[] = "staff: {$taskCount} review task(s) already present";
        }

        Db::execute(
            "UPDATE projects SET status = 'facts_pending_approval'
             WHERE team_id = ? AND name = 'Rift Valley Reforestation' AND status = 'setup'",
            [$demoTeamId],
        );
        Db::execute(
            "UPDATE projects SET status = 'review'
             WHERE team_id = ? AND name = 'Kisumu Clean Cookstoves' AND status = 'setup'",
            [$demoTeamId],
        );

        $kisumu = Db::selectOne(
            "SELECT id FROM projects WHERE team_id = ? AND name = 'Kisumu Clean Cookstoves' AND deleted_at IS NULL LIMIT 1",
            [$demoTeamId],
        );
        $templateVersion = Db::selectOne(
            "SELECT tv.id FROM template_versions tv
             INNER JOIN templates t ON t.id = tv.template_id
             WHERE t.name = 'Gold Standard Template Guide v1.4' LIMIT 1",
        );

        if ($kisumu !== null && $templateVersion !== null) {
            $existingDraft = Db::selectOne('SELECT id FROM drafts WHERE project_id = ? LIMIT 1', [(string) $kisumu['id']]);
            if ($existingDraft === null) {
                $factSheetVersionId = Uuid::v4();
                Db::insert('fact_sheet_versions', [
                    'id' => $factSheetVersionId,
                    'project_id' => (string) $kisumu['id'],
                    'version' => 1,
                    'status' => 'approved',
                    'snapshot' => ['facts' => [], 'approved_for' => 'demo seed'],
                    'created_by_user_id' => (string) $demoUser['id'],
                    'approved_by_user_id' => (string) $demoUser['id'],
                    'approved_at' => gmdate('Y-m-d H:i:s'),
                ]);
                Db::insert('drafts', [
                    'id' => Uuid::v4(),
                    'project_id' => (string) $kisumu['id'],
                    'team_id' => $demoTeamId,
                    'template_version_id' => (string) $templateVersion['id'],
                    'fact_sheet_version_id' => $factSheetVersionId,
                    'language' => 'en',
                    'status' => 'in_review',
                    'version' => 1,
                    'is_labeled_draft' => 'draft',
                    'created_by_user_id' => (string) $demoUser['id'],
                ]);
                $lines[] = 'staff: seeded 1 draft in review';
            }
        }
    }
}
