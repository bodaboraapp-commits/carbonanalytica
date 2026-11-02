<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AppError;
use App\Http;
use App\I18n;
use App\Services\TeamService;

/**
 * Invitation acceptance. Signing in first is mandatory and the signed-in email
 * must match the invited address (TeamService enforces the match).
 */
final class InviteController extends Controller
{
    public function accept(array $params): never
    {
        $token = (string) ($params['token'] ?? '');
        $invitePath = '/invite/' . rawurlencode($token);

        $user = $this->requireUser($invitePath);

        try {
            $result = TeamService::acceptInvitation(
                (string) $user['id'],
                (string) $user['email'],
                $token,
            );

            \App\Session::set('current_team_id', $result['team_id']);
            \App\Session::set('default_team_id', $result['team_id']);
            $this->flash('success', t('flash.invite_accepted'));
            Http::redirect('/dashboard');
        } catch (AppError $error) {
            $this->flash('error', I18n::error($error->errorCode));
            Http::redirect($invitePath);
        }
    }
}
