<?php

declare(strict_types=1);

/**
 * Route table. Plain arrays, no annotations or config files: one glance shows
 * every URL the application answers.
 */

use App\Controllers\AdminConsoleController;
use App\Controllers\AdminDatabaseController;
use App\Controllers\AdminTaskController;
use App\Controllers\AdminTemplateController;
use App\Controllers\AiEngineController;
use App\Controllers\ApiController;
use App\Controllers\AuthController;
use App\Controllers\DeployController;
use App\Controllers\DraftController;
use App\Controllers\InviteController;
use App\Controllers\OnboardingController;
use App\Controllers\PageController;
use App\Controllers\ProjectAiController;
use App\Controllers\ProjectController;
use App\Controllers\TeamController;
use App\Controllers\WorkspaceTaskController;
use App\Router;

$router = new Router();

$pages = new PageController();
$auth = new AuthController();
$projects = new ProjectController();
$projectAi = new ProjectAiController();
$team = new TeamController();
$invite = new InviteController();
$onboarding = new OnboardingController();
$adminTasks = new AdminTaskController();
$adminConsole = new AdminConsoleController();
$adminTemplates = new AdminTemplateController();
$adminDatabase = new AdminDatabaseController();
$aiEngine = new AiEngineController();
$drafts = new DraftController();
$deploy = new DeployController();
$workspaceTasks = new WorkspaceTaskController();
$api = new ApiController();

// Public pages.
$router->get('/', [$pages, 'home']);
$router->get('/login', [$auth, 'showLogin']);
$router->post('/login', [$auth, 'login']);
$router->get('/signup', [$auth, 'showSignup']);
$router->post('/signup', [$auth, 'signup']);
$router->post('/logout', [$auth, 'logout']);
$router->get('/forgot-password', [$auth, 'showForgot']);
$router->post('/forgot-password', [$auth, 'forgot']);
$router->get('/reset-password', [$auth, 'showReset']);
$router->post('/reset-password', [$auth, 'reset']);
// Email one-time-code verification (sign-up verification and sign-in 2FA).
// /verify-email is kept as a legacy entry point for links sent before the switch.
$router->get('/verify-otp', [$auth, 'showVerifyOtp']);
$router->post('/verify-otp', [$auth, 'verifyOtp']);
$router->post('/verify-otp/resend', [$auth, 'resendOtp']);
$router->get('/verify-email', [$auth, 'showVerifyOtp']);
$router->get('/auth/google', [$auth, 'googleRedirect']);
$router->get('/auth/google/callback', [$auth, 'googleCallback']);
$router->get('/lang/{locale}', [$pages, 'switchLocale']);

// Signed-in pages.
$router->get('/dashboard', [$pages, 'dashboard']);
$router->get('/projects/{id}', [$pages, 'projectShow']);

// AI drafting: manual generation plus the read-only draft page.
$router->post('/projects/{id}/draft/generate', [$drafts, 'generate']);
// User-side input tunnel: workspace editors submit project evidence for drafting.
$router->post('/projects/{id}/inputs', [$projectAi, 'addInput']);
$router->get('/drafts/{id}', [$drafts, 'show']);

// Workspace admin job queue (owner/admin only; workspace-scoped tasks).
$router->get('/dashboard/tasks', [$workspaceTasks, 'tasks']);
$router->post('/dashboard/tasks/{id}/assign', [$workspaceTasks, 'assign']);
$router->post('/dashboard/tasks/{id}/unassign', [$workspaceTasks, 'unassign']);
$router->post('/dashboard/tasks/{id}/complete', [$workspaceTasks, 'complete']);
$router->post('/projects', [$projects, 'create']);
$router->get('/team', [$pages, 'teamShow']);
$router->get('/invite/{token}', [$pages, 'inviteShow']);
$router->post('/invite/{token}/accept', [$invite, 'accept']);

// Organization onboarding wizard.
$router->get('/onboarding', [$onboarding, 'show']);
$router->post('/onboarding/organization', [$onboarding, 'saveOrganization']);
$router->post('/onboarding/contact', [$onboarding, 'saveContact']);
$router->post('/onboarding/documents', [$onboarding, 'uploadDocument']);
$router->post('/onboarding/documents/{id}/delete', [$onboarding, 'deleteDocument']);
$router->get('/onboarding/documents/{id}', [$onboarding, 'viewDocument']);
$router->get('/onboarding/documents/{id}/download', [$onboarding, 'downloadDocument']);
$router->post('/onboarding/submit', [$onboarding, 'submit']);

// Platform console: overview, workspaces, KYC, users, templates, audit.
$router->get('/admin', [$adminConsole, 'overview']);
$router->get('/admin/workspaces', [$adminConsole, 'workspaces']);
$router->get('/admin/kyc', [$adminConsole, 'kyc']);
$router->post('/admin/kyc/{id}/verify', [$adminConsole, 'verifyKyc']);
$router->post('/admin/kyc/{id}/reject', [$adminConsole, 'rejectKyc']);
$router->get('/admin/users', [$adminConsole, 'users']);
$router->post('/admin/users/{id}/platform-admin', [$adminConsole, 'togglePlatformAdmin']);
$router->post('/admin/settings/{key}', [$adminConsole, 'toggleSetting']);
$router->get('/admin/templates', [$adminConsole, 'templates']);
// Template library management (staff only): create template, source upload,
// SKILL.md upload, publish/retire, delete.
$router->post('/admin/templates', [$adminTemplates, 'create']);
$router->post('/admin/templates/{id}/skill', [$adminTemplates, 'uploadSkill']);
$router->post('/admin/templates/{id}/source', [$adminTemplates, 'uploadSource']);
$router->post('/admin/templates/{id}/status', [$adminTemplates, 'updateStatus']);
$router->post('/admin/templates/{id}/delete', [$adminTemplates, 'deleteVersion']);
$router->get('/admin/audit', [$adminConsole, 'audit']);
$router->get('/admin/system', [$adminConsole, 'system']);

// AI Engine settings (SYSTEM group): staff-only provider config + drafting skill.
$router->get('/admin/ai', [$aiEngine, 'show']);
$router->post('/admin/ai', [$aiEngine, 'save']);
$router->post('/admin/ai/test', [$aiEngine, 'testConnection']);

// AI Engine test bench + few-shot skill examples (staff only, ephemeral runs).
$router->post('/admin/ai/test-input', [$aiEngine, 'runTest']);
$router->post('/admin/ai/examples', [$aiEngine, 'saveExample']);
$router->post('/admin/ai/examples/{id}/toggle', [$aiEngine, 'toggleExample']);
$router->post('/admin/ai/examples/{id}/delete', [$aiEngine, 'deleteExample']);

// Deployment: migration ledger, apply migrations, run seed (staff only).
$router->get('/admin/database', [$adminDatabase, 'show']);
$router->post('/admin/database/migrate', [$adminDatabase, 'migrate']);
$router->post('/admin/database/seed', [$adminDatabase, 'seed']);
$router->get('/admin/database/import', [$adminDatabase, 'showImport']);
$router->post('/admin/database/import', [$adminDatabase, 'importDump']);

// One-click dump import, enabled only when DEPLOY_TOKEN is configured.
$router->get('/deploy/import', [$deploy, 'import']);

// Staff review queue.
$router->get('/admin/tasks', [$adminTasks, 'tasks']);
$router->post('/admin/tasks/{id}/assign', [$adminTasks, 'assign']);
$router->post('/admin/tasks/{id}/unassign', [$adminTasks, 'unassign']);
$router->post('/admin/tasks/{id}/complete', [$adminTasks, 'complete']);
$router->post('/admin/security/otp', [$adminTasks, 'toggleOtp']);

// Team management actions.
$router->post('/team/invite', [$team, 'invite']);
$router->post('/team/invitations/{id}/revoke', [$team, 'revokeInvitation']);
$router->post('/team/members/{id}/role', [$team, 'changeRole']);
$router->post('/team/members/{id}/remove', [$team, 'removeMember']);

// JSON API.
$router->get('/api/health', [$api, 'health']);
$router->get('/api/session', [$api, 'session']);
$router->get('/api/projects', [$api, 'projects']);

return $router;
