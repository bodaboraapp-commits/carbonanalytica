<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Db;
use App\Http;
use App\Services\ProjectService;
use App\Tenancy;

/**
 * Small JSON API (REST) for the same operations the pages use.
 *
 * The server-rendered pages are the primary interface; these endpoints exist so
 * later steps (upload progress, editor autosave, mobile) reuse identical rules.
 */
final class ApiController
{
    public function health(array $params): never
    {
        $database = 'ok';
        try {
            Db::selectOne('SELECT 1 AS ok');
        } catch (\Throwable) {
            $database = 'unavailable';
        }

        Http::json([
            'status' => $database === 'ok' ? 'ok' : 'degraded',
            'database' => $database,
            'time' => gmdate('c'),
        ], $database === 'ok' ? 200 : 503);
    }

    public function session(array $params): never
    {
        $user = Auth::user();
        if ($user === null) {
            Http::json(['data' => null]);
        }

        Http::json([
            'data' => [
                'user' => [
                    'id' => (string) $user['id'],
                    'name' => (string) $user['name'],
                    'email' => (string) $user['email'],
                    'locale' => (string) $user['locale'],
                    'email_verified' => $user['email_verified_at'] !== null,
                ],
                'teams' => Tenancy::teamsForUser((string) $user['id']),
            ],
        ]);
    }

    public function projects(array $params): never
    {
        $user = Auth::user();
        if ($user === null) {
            throw \App\AppError::unauthorized();
        }

        $team = Tenancy::currentTeam((string) $user['id'], Http::queryString('team'));
        $projects = ProjectService::listForTeam((string) $user['id'], (string) $team['id']);

        Http::json([
            'data' => $projects,
            'meta' => ['team_id' => (string) $team['id']],
        ]);
    }
}
