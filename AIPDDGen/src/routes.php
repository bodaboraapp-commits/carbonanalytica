<?php

declare(strict_types=1);

/**
 * Route table. Plain arrays, no annotations or config files: one glance shows
 * every URL the application answers.
 */

use App\Controllers\AdminTaskController;
use App\Controllers\ApiController;
use App\Controllers\AuthController;
use App\Controllers\InviteController;
use App\Controllers\OnboardingController;
use App\Controllers\PageController;
use App\Controllers\ProjectController;
use App\Controllers\TeamController;
use App\Router;

$router = new Router();

$pages = new PageController();
$auth = new AuthController();
$projects = new ProjectController();
$team = new TeamController();
$invite = new InviteController();
$onboarding = new OnboardingController();
$adminTasks = new AdminTaskController();
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
$router->get('/verify-email', [$auth, 'verifyEmail']);
$router->post('/verify-email/resend', [$auth, 'resendVerification']);
$router->get('/auth/google', [$auth, 'googleRedirect']);
$router->get('/auth/google/callback', [$auth, 'googleCallback']);
$router->get('/lang/{locale}', [$pages, 'switchLocale']);

// Signed-in pages.
$router->get('/dashboard', [$pages, 'dashboard']);
$router->get('/projects/{id}', [$pages, 'projectShow']);
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

// Staff console: Action Center dashboard and review job queue.
$router->get('/admin', [$adminTasks, 'dashboard']);
$router->get('/admin/tasks', [$adminTasks, 'tasks']);
$router->post('/admin/tasks/{id}/assign', [$adminTasks, 'assign']);
$router->post('/admin/tasks/{id}/unassign', [$adminTasks, 'unassign']);
$router->post('/admin/tasks/{id}/complete', [$adminTasks, 'complete']);

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
