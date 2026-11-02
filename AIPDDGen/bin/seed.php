<?php

declare(strict_types=1);

/**
 * Seed the application database.
 *
 * Usage: php bin/seed.php
 *
 * Creates (idempotently):
 *  - the provisional launch methodology shortlist for Gold Standard and Verra VCS;
 *  - the Gold Standard v1.4 template structure as a draft (published in step 2);
 *  - a demo account, workspace and two projects for exploring the product.
 *
 * The methodology list is provisional on purpose: which Africa-relevant
 * methodologies ship first is an open question (docs/open-questions.md).
 */
require __DIR__ . '/../src/bootstrap.php';

use App\Db;
use App\I18n;
use App\Repositories\TeamRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\ProjectService;
use App\Services\TaskCenterService;
use App\Uuid;

$demoEmail = 'demo@pddgen.local';
$demoPassword = 'DemoPassw0rd!123';

// ---------------------------------------------------------------------------
// Methodologies (provisional shortlist).
// ---------------------------------------------------------------------------
$methodologies = [
    ['gold_standard', 'AMS-I.C', 'Thermal energy for the user with or without electricity', 'v21.0'],
    ['gold_standard', 'AMS-II.G', 'Energy efficiency measures in thermal applications of non-renewable biomass', 'v13.0'],
    ['gold_standard', 'AR-AMS0007', 'Afforestation and reforestation on lands other than wetlands', 'v03.1'],
    ['verra_vcs', 'VM0007', 'REDD+ Methodology Framework (REDD-MF)', 'v1.6'],
    ['verra_vcs', 'VM0015', 'Methodology for Avoided Unplanned Deforestation', 'v1.1'],
    ['verra_vcs', 'VM0009', 'Methodology for Avoided Ecosystem Conversion', 'v3.0'],
];

$insertedMethodologies = 0;
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
    $insertedMethodologies++;
}

// ---------------------------------------------------------------------------
// Reference template: Gold Standard Template Guide v1.4 structure (draft).
// It stays in `draft` status until the admin console (step 2) publishes it.
// ---------------------------------------------------------------------------
$templateName = 'Gold Standard Template Guide v1.4';
$template = Db::selectOne('SELECT * FROM templates WHERE name = ? LIMIT 1', [$templateName]);

if ($template === null) {
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
    echo "template: seeded Gold Standard v1.4 structure (draft)\n";
} else {
    echo "template: already seeded\n";
}

// ---------------------------------------------------------------------------
// Demo account, workspace and projects.
// ---------------------------------------------------------------------------
if (getenv('SEED_DEMO') === 'false') {
    echo "demo data: skipped (SEED_DEMO=false)\n";
    exit(0);
}

$demoUser = UserRepository::findByEmail($demoEmail);

if ($demoUser !== null) {
    echo "demo data: user {$demoEmail} already exists\n";
    $team = TeamRepository::find((string) $demoUser['default_team_id']);
} else {
    $created = AuthService::signUp('Amina Demo', $demoEmail, $demoPassword, I18n::locale());
    $demoUser = $created['user'];
    $team = $created['team'];

    // Mark the demo address verified and sign-up email out of the way.
    UserRepository::markEmailVerified((string) $demoUser['id']);

    echo "demo data: created user {$demoEmail} / {$demoPassword}\n";
}

if ($team === null) {
    fwrite(STDERR, "demo data: workspace missing\n");
    exit(1);
}

$existingProjects = Db::select('SELECT id, name FROM projects WHERE team_id = ? AND deleted_at IS NULL', [(string) $team['id']]);

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
    echo "demo data: created 2 projects\n";
} else {
    echo 'demo data: ' . count($existingProjects) . " project(s) already present\n";
}

// ---------------------------------------------------------------------------
// Staff console: platform admin account and a realistic review queue.
// ---------------------------------------------------------------------------
$adminEmail = 'admin@pddgen.local';
$adminPassword = 'AdminPassw0rd!123';
$adminUser = UserRepository::findByEmail($adminEmail);

if ($adminUser === null) {
    $adminCreated = AuthService::signUp('Ian Admin', $adminEmail, $adminPassword, 'en');
    Db::execute(
        'UPDATE users SET is_platform_admin = 1, email_verified_at = NOW(3) WHERE id = ?',
        [(string) $adminCreated['user']['id']],
    );
    $adminUser = UserRepository::findById((string) $adminCreated['user']['id']);
    echo "staff: created {$adminEmail} / {$adminPassword} (platform admin)\n";
} elseif ((int) $adminUser['is_platform_admin'] !== 1) {
    Db::execute('UPDATE users SET is_platform_admin = 1 WHERE id = ?', [(string) $adminUser['id']]);
    $adminUser = UserRepository::findById((string) $adminUser['id']);
    echo "staff: promoted {$adminEmail} to platform admin\n";
} else {
    echo "staff: {$adminEmail} already a platform admin\n";
}

if ($adminUser !== null && $team !== null) {
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
                'due_at' => gmdate('Y-m-d H:i:s', $now - 2 * 24 * 60 * 60),
                'team_id' => $demoTeamId,
            ],
            [
                'work_type' => 'project_validation',
                'title' => 'Validate project boundary — Kisumu Clean Cookstoves',
                'description' => 'Boundary coordinates and included households need a second pair of eyes.',
                'priority' => 'urgent',
                'due_at' => gmdate('Y-m-d H:i:s', $now - 24 * 60 * 60),
                'assigned_user_id' => $adminId,
                'team_id' => $demoTeamId,
            ],
            [
                'work_type' => 'template_review',
                'title' => 'QA the Gold Standard v1.4 template structure',
                'description' => 'Headings, numbering and table columns must match the published guide.',
                'priority' => 'normal',
                'due_at' => gmdate('Y-m-d H:i:s', $now + 5 * 24 * 60 * 60),
                'assigned_user_id' => $adminId,
                'team_id' => $demoTeamId,
            ],
            [
                'work_type' => 'fact_sheet_review',
                'title' => 'Approve fact sheet — Rift Valley Reforestation',
                'description' => 'Confirm ex-ante estimates and the baseline quotations before drafting.',
                'priority' => 'normal',
                'due_at' => gmdate('Y-m-d H:i:s', $now + 3 * 24 * 60 * 60),
                'team_id' => $demoTeamId,
            ],
            [
                'work_type' => 'draft_review',
                'title' => 'Review draft sections — Kisumu Clean Cookstoves',
                'description' => 'Check citations and the two "needs input" markers in section B.',
                'priority' => 'low',
                'due_at' => gmdate('Y-m-d H:i:s', $now + 7 * 24 * 60 * 60),
                'assigned_user_id' => $adminId,
                'team_id' => $demoTeamId,
            ],
        ];

        foreach ($demoTasks as $task) {
            TaskCenterService::createTask($adminId, $task);
        }
        echo 'staff: seeded ' . count($demoTasks) . " review tasks\n";
    } else {
        echo "staff: {$taskCount} review task(s) already present\n";
    }

    // Make the pipeline cards show real activity in the demo workspace.
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
            echo "staff: seeded 1 draft in review\n";
        }
    }
}

echo "Seed complete.\n";
echo "  URL:      " . absolute_url('/login') . "\n";
echo "  Email:    {$demoEmail}\n";
echo "  Password: {$demoPassword}\n";
echo "  Staff:    {$adminEmail} / {$adminPassword}\n";
