<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AppError;
use App\Auth;
use App\Config;
use App\GoogleOAuth;
use App\Http;
use App\I18n;
use App\Session;
use App\Services\AuthService;
use App\Tenancy;
use App\Validator;

/**
 * Authentication endpoints (PRD F1): sign-up, sign-in, sign-out, email
 * verification, password reset and Google sign-in.
 *
 * Forms use the POST → redirect → GET pattern, so refresh never re-submits and
 * the pages work without JavaScript.
 */
final class AuthController extends Controller
{
    public function showLogin(array $params): never
    {
        if (Auth::check()) {
            Http::redirect('/dashboard');
        }

        \App\View::render('pages/login', [
            'email' => (string) (Http::queryString('email') ?? ''),
            'next' => $this->safeNext(Http::queryString('next')),
            'errors' => Session::takeErrors(),
            'showGoogle' => GoogleOAuth::configured(),
        ]);
    }

    public function login(array $params): never
    {
        $next = $this->safeNext(is_string($_POST['next'] ?? null) ? $_POST['next'] : null);
        $email = (string) (Http::input('email') ?? '');

        try {
            $input = Validator::validate($_POST, [
                'email' => 'required|email|max:254',
                'password' => 'required|max:200',
            ]);

            $user = AuthService::signIn($input['email'], $input['password']);
            Auth::login($user);
            Session::set('default_team_id', $user['default_team_id']);
            $this->flash('success', t('flash.signed_in'));
            Http::redirect($next);
        } catch (AppError $error) {
            $this->rememberInput(['email' => $email]);
            $this->backWithError($error, '/login?next=' . rawurlencode($next));
        }
    }

    public function showSignup(array $params): never
    {
        if (Auth::check()) {
            Http::redirect('/dashboard');
        }

        \App\View::render('pages/signup', [
            'name' => '',
            'email' => (string) (Http::queryString('email') ?? ''),
            'next' => $this->safeNext(Http::queryString('next')),
            'errors' => Session::takeErrors(),
            'showGoogle' => GoogleOAuth::configured(),
        ]);
    }

    public function signup(array $params): never
    {
        $next = $this->safeNext(is_string($_POST['next'] ?? null) ? $_POST['next'] : null);
        $name = (string) (Http::input('name') ?? '');
        $email = (string) (Http::input('email') ?? '');

        try {
            $input = Validator::validate($_POST, [
                'name' => 'required|max:120',
                'email' => 'required|email|max:254',
                'password' => 'required|min:12|max:200',
            ]);

            $created = AuthService::signUp($input['name'], $input['email'], $input['password'], I18n::locale());
            Auth::login($created['user']);
            Session::set('default_team_id', $created['team']['id']);
            $this->flash('success', t('flash.account_created'));
            Http::redirect($next);
        } catch (AppError $error) {
            $this->rememberInput(['name' => $name, 'email' => $email]);
            $this->backWithError($error, '/signup');
        }
    }

    public function logout(array $params): never
    {
        AuthService::signOut();
        Auth::logout();
        $this->flash('success', t('flash.signed_out'));
        Http::redirect('/login');
    }

    public function showForgot(array $params): never
    {
        \App\View::render('pages/forgot-password', [
            'errors' => Session::takeErrors(),
        ]);
    }

    public function forgot(array $params): never
    {
        try {
            $input = Validator::validate($_POST, ['email' => 'required|email|max:254']);
            AuthService::requestPasswordReset($input['email']);
            $this->flash('success', t('flash.password_reset_sent'));
            Http::redirect('/login');
        } catch (AppError $error) {
            $this->backWithError($error, '/forgot-password');
        }
    }

    public function showReset(array $params): never
    {
        $token = (string) (Http::queryString('token') ?? '');
        if ($token === '') {
            $this->flash('error', t('error.invalid_token'));
            Http::redirect('/forgot-password');
        }

        \App\View::render('pages/reset-password', [
            'token' => $token,
            'errors' => Session::takeErrors(),
        ]);
    }

    public function reset(array $params): never
    {
        $token = (string) (Http::input('token') ?? '');

        try {
            $input = Validator::validate($_POST, [
                'token' => 'required|max:200',
                'password' => 'required|min:12|max:200',
            ]);
            AuthService::resetPassword($input['token'], $input['password']);
            $this->flash('success', t('flash.password_changed'));
            Http::redirect('/login');
        } catch (AppError $error) {
            $this->backWithError($error, '/reset-password?token=' . rawurlencode($token));
        }
    }

    public function verifyEmail(array $params): never
    {
        $token = (string) (Http::queryString('token') ?? '');

        if ($token !== '') {
            try {
                $result = AuthService::verifyEmail($token);
                if (Auth::check()) {
                    Auth::refresh();
                }
                $this->flash('success', t('flash.email_verified'));
                Http::redirect(Auth::check() ? '/dashboard' : '/login');
            } catch (AppError $error) {
                $this->flash('error', I18n::error($error->errorCode));
                Http::redirect(Auth::check() ? '/dashboard' : '/login');
            }
        }

        // No token: show the "check your inbox" page.
        $user = Auth::user();
        \App\View::render('pages/verify-email', [
            'user' => $user,
        ]);
    }

    public function resendVerification(array $params): never
    {
        $user = $this->requireUser();

        try {
            $sent = AuthService::resendVerification((string) $user['id']);
            $this->flash('success', $sent ? t('flash.verification_resent') : t('flash.already_verified'));
        } catch (AppError $error) {
            $this->backWithError($error, '/dashboard');
        }

        Http::redirect('/dashboard');
    }

    public function googleRedirect(array $params): never
    {
        if (!GoogleOAuth::configured()) {
            $this->flash('error', t('flash.google_unavailable'));
            Http::redirect('/login');
        }

        $pkce = GoogleOAuth::pkce();
        $state = \App\Tokens::generate(24);

        Session::set('oauth_state', $state);
        Session::set('oauth_verifier', $pkce['verifier']);

        Http::redirect(GoogleOAuth::authorizeUrl($state, $pkce['challenge']));
    }

    public function googleCallback(array $params): never
    {
        if (!GoogleOAuth::configured()) {
            $this->flash('error', t('flash.google_unavailable'));
            Http::redirect('/login');
        }

        $state = (string) (Http::queryString('state') ?? '');
        $code = (string) (Http::queryString('code') ?? '');
        $expectedState = (string) (Session::get('oauth_state') ?? '');
        $verifier = (string) (Session::get('oauth_verifier') ?? '');

        Session::forget('oauth_state');
        Session::forget('oauth_verifier');

        if ($code === '' || $expectedState === '' || !\App\Tokens::equals($expectedState, $state)) {
            $this->flash('error', t('error.invalid_token'));
            Http::redirect('/login');
        }

        try {
            $accessToken = GoogleOAuth::exchangeCode($code, $verifier);
            $profile = GoogleOAuth::profile($accessToken);
            $user = AuthService::signInWithGoogle($profile);
            Auth::login($user);
            Session::set('default_team_id', $user['default_team_id']);
            $this->flash('success', t('flash.signed_in'));
            Http::redirect('/dashboard');
        } catch (AppError $error) {
            $this->flash('error', I18n::error($error->errorCode));
            Http::redirect('/login');
        }
    }
}
