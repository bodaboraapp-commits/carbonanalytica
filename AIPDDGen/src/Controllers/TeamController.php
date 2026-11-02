<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AppError;
use App\Http;
use App\I18n;
use App\Services\TeamService;
use App\Session;
use App\Tenancy;
use App\Validator;

/**
 * Team workspace actions: invitations, role changes and member removal (PRD F10).
 * Role rules themselves live in TeamService.
 */
final class TeamController extends Controller
{
    public function invite(array $params): never
    {
        $user = $this->requireUser();
        $team = $this->currentTeam($user);
        $email = (string) (Http::input('email') ?? '');

        try {
            $input = Validator::validate($_POST, [
                'email' => 'required|email|max:254',
                'role' => 'required|in:admin,editor,viewer',
            ]);

            $result = TeamService::invite(
                (string) $user['id'],
                (string) $team['id'],
                $input['email'],
                $input['role'],
            );

            // Email delivery is logged locally, so surface the link once.
            Session::set('_invite_link', absolute_url('/invite/' . $result['token']));
            $this->flash('success', t('flash.invite_created'));
            Http::redirect('/team');
        } catch (AppError $error) {
            $this->rememberInput(['email' => $email]);
            $this->backWithError($error, '/team');
        }
    }

    public function revokeInvitation(array $params): never
    {
        $user = $this->requireUser();
        $team = $this->currentTeam($user);

        try {
            TeamService::revokeInvitation((string) $user['id'], (string) $team['id'], (string) $params['id']);
            $this->flash('success', t('flash.invite_revoked'));
        } catch (AppError $error) {
            $this->flash('error', I18n::error($error->errorCode));
        }

        Http::redirect('/team');
    }

    public function changeRole(array $params): never
    {
        $user = $this->requireUser();
        $team = $this->currentTeam($user);

        try {
            $input = Validator::validate($_POST, ['role' => 'required|in:admin,editor,viewer']);
            TeamService::changeMemberRole((string) $user['id'], (string) $team['id'], (string) $params['id'], $input['role']);
            $this->flash('success', t('flash.role_changed'));
        } catch (AppError $error) {
            $this->flash('error', I18n::error($error->errorCode));
        }

        Http::redirect('/team');
    }

    public function removeMember(array $params): never
    {
        $user = $this->requireUser();
        $team = $this->currentTeam($user);

        try {
            TeamService::removeMember((string) $user['id'], (string) $team['id'], (string) $params['id']);
            $this->flash('success', t('flash.member_removed'));
        } catch (AppError $error) {
            $this->flash('error', I18n::error($error->errorCode));
        }

        Http::redirect('/team');
    }
}
