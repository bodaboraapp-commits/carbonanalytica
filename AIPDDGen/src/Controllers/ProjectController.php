<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AppError;
use App\Auth;
use App\Http;
use App\I18n;
use App\Services\ProjectService;
use App\Session;
use App\Validator;

/**
 * Project actions. Creation is intentionally minimal here: the full setup wizard
 * (registry, methodology, country, project type) is PRD F2 / step 3.
 */
final class ProjectController extends Controller
{
    public function create(array $params): never
    {
        $user = $this->requireUser();

        // The workspace comes from the form so the POST cannot be redirected to
        // another tenant by tampering with the URL.
        $teamId = (string) (Http::input('team_id') ?? '');
        $name = (string) (Http::input('name') ?? '');
        $description = (string) (Http::input('description') ?? '');

        try {
            $input = Validator::validate($_POST, [
                'name' => 'required|max:200',
                'description' => 'max:2000',
            ]);

            $project = ProjectService::create(
                (string) $user['id'],
                $teamId,
                $input['name'],
                $input['description'] !== '' ? $input['description'] : null,
            );

            $this->flash('success', t('flash.project_created'));
            Http::redirect('/projects/' . $project['id']);
        } catch (AppError $error) {
            $this->rememberInput(['name' => $name, 'description' => $description]);
            $this->backWithError($error, '/dashboard');
        }
    }
}
