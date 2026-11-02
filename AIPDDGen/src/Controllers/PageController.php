<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AppError;
use App\Auth;
use App\Http;
use App\I18n;
use App\Repositories\UserRepository;
use App\Services\OnboardingService;
use App\Services\ProjectService;
use App\Services\TeamService;
use App\Tenancy;
use App\View;

/**
 * Server-rendered pages (landing, dashboard, project, team, invite) and the
 * language switcher. Business rules live in the service classes.
 */
final class PageController extends Controller
{
    public function home(array $params): never
    {
        if (Auth::check()) {
            Http::redirect('/dashboard');
        }
        View::render('pages/home');
    }

    public function dashboard(array $params): never
    {
        $user = $this->requireUser();
        $team = $this->currentTeam($user);
        $projects = ProjectService::listForTeam((string) $user['id'], (string) $team['id']);

        View::render('pages/dashboard', [
            'user' => $user,
            'team' => $team,
            'teams' => Tenancy::teamsForUser((string) $user['id']),
            'projects' => $projects,
            'organization' => OnboardingService::state((string) $user['id'], (string) $team['id']),
            'canEdit' => $this->hasRole((string) $team['membership_role'], 'editor'),
            'errors' => \App\Session::takeErrors(),
        ]);
    }

    public function projectShow(array $params): never
    {
        $user = $this->requireUser();
        $scope = ProjectService::get((string) $user['id'], (string) $params['id']);

        View::render('pages/project', [
            'user' => $user,
            'project' => $scope['project'],
            'team' => $scope['team'],
            'role' => $scope['role'],
            'canEdit' => $this->hasRole($scope['role'], 'editor'),
            'teams' => Tenancy::teamsForUser((string) $user['id']),
        ]);
    }

    public function teamShow(array $params): never
    {
        $user = $this->requireUser();
        $team = $this->currentTeam($user);
        $scope = Tenancy::teamForUser((string) $user['id'], (string) $team['id'], 'viewer');

        $members = TeamService::members((string) $team['id']);
        $invitations = TeamService::pendingInvitations((string) $team['id']);

        // New invitation links are shown once, right after creation (email is
        // logged in this environment rather than delivered).
        $newInviteLink = \App\Session::get('_invite_link');
        \App\Session::forget('_invite_link');

        View::render('pages/team', [
            'user' => $user,
            'team' => $team,
            'role' => $scope['role'],
            'members' => $members,
            'invitations' => $invitations,
            'organization' => OnboardingService::state((string) $user['id'], (string) $team['id']),
            'canInvite' => $this->hasRole($scope['role'], 'admin'),
            'isOwner' => $scope['role'] === 'owner',
            'newInviteLink' => is_string($newInviteLink) ? $newInviteLink : null,
            'teams' => Tenancy::teamsForUser((string) $user['id']),
        ]);
    }

    public function inviteShow(array $params): never
    {
        $token = (string) ($params['token'] ?? '');
        $preview = TeamService::invitationPreview($token);

        if ($preview === null) {
            throw AppError::notFound('That invitation');
        }

        $user = Auth::user();
        $emailMatches = $user !== null
            && mb_strtolower((string) $user['email']) === mb_strtolower((string) $preview['invitation']['email']);

        View::render('pages/invite', [
            'user' => $user,
            'token' => $token,
            'preview' => $preview,
            'emailMatches' => $emailMatches,
        ]);
    }

    public function switchLocale(array $params): never
    {
        $locale = (string) ($params['locale'] ?? 'en');
        I18n::setLocale($locale);

        setcookie('pdd_locale', $locale, [
            'expires' => time() + 60 * 60 * 24 * 365,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => (bool) config('security.secure_cookies'),
        ]);

        $user = Auth::user();
        if ($user !== null) {
            UserRepository::update((string) $user['id'], ['locale' => $locale]);
        }

        $next = $this->safeNext(is_string($_GET['next'] ?? null) ? $_GET['next'] : null, '/dashboard');
        Http::redirect($next);
    }
}
