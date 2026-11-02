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
        if (Auth::pending() !== null) {
            Http::redirect('/verify-otp');
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

            // Two-factor is opt-in per account and off by default (migration
            // 0006). With it off, the password establishes the session; with it
            // on, no session exists until the emailed code is verified.
            $user = AuthService::signIn($input['email'], $input['password']);

            if ((int) ($user['otp_enabled'] ?? 0) !== 1) {
                Auth::clearPending();
                $user = AuthService::completePasswordSignIn($user);
                Auth::login($user);
                Session::set('default_team_id', $user['default_team_id']);
                $this->flash('success', t('flash.signed_in'));
                Http::redirect($next);
            }

            $challenge = AuthService::startOtpChallenge($user, 'signin');

            Auth::clearPending();
            Auth::setPending([
                'user_id' => (string) $user['id'],
                'purpose' => 'signin',
                'email' => (string) $user['email'],
                'next' => $next,
                'expires_at' => time() + (int) Config::get('auth.otp_pending_ttl'),
                'delivered' => $challenge['delivered'],
                'delivery_error' => $challenge['delivery_error'],
            ]);

            $this->flash('success', t('flash.otp_sent'));
            Http::redirect('/verify-otp');
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
        if (Auth::pending() !== null) {
            Http::redirect('/verify-otp');
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
            $challenge = AuthService::startOtpChallenge($created['user'], 'signup');

            Auth::clearPending();
            Auth::setPending([
                'user_id' => (string) $created['user']['id'],
                'purpose' => 'signup',
                'email' => (string) $created['user']['email'],
                'next' => $next,
                'expires_at' => time() + (int) Config::get('auth.otp_pending_ttl'),
                'delivered' => $challenge['delivered'],
                'delivery_error' => $challenge['delivery_error'],
            ]);

            $this->flash('success', t('flash.otp_sent'));
            Http::redirect('/verify-otp');
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

    /** The one-time-code screen shared by sign-up verification and sign-in 2FA. */
    public function showVerifyOtp(array $params): never
    {
        if (Auth::check()) {
            Http::redirect('/dashboard');
        }

        $pending = Auth::pending();
        if ($pending === null) {
            $this->flash('error', t('error.otp_session_expired'));
            Http::redirect('/login');
        }

        \App\View::render('pages/verify-otp', [
            'pending' => $pending,
            'maskedEmail' => mask_email((string) ($pending['email'] ?? '')),
            'minutes' => max(1, (int) round(((int) Config::get('auth.otp_ttl')) / 60)),
            'errors' => Session::takeErrors(),
        ]);
    }

    public function verifyOtp(array $params): never
    {
        $pending = Auth::pending();
        if ($pending === null) {
            $this->flash('error', t('error.otp_session_expired'));
            Http::redirect('/login');
        }

        try {
            $code = self::otpCodeFromInput($_POST);
            if (!preg_match('/^\d{6}$/', $code)) {
                throw AppError::validation(['code' => 'invalid_code']);
            }

            $result = AuthService::verifyOtp((string) $pending['user_id'], (string) $pending['purpose'], $code);
            $user = $result['user'];

            Auth::login($user);
            Session::set('default_team_id', $user['default_team_id']);

            $this->flash('success', $result['newly_verified'] ? t('flash.email_verified') : t('flash.signed_in'));
            $next = $this->safeNext(is_string($pending['next'] ?? null) ? $pending['next'] : null);
            Http::redirect($next);
        } catch (AppError $error) {
            if ($error->fields !== []) {
                Session::errors($error->fields);
            }
            $this->flash('error', I18n::error($error->errorCode));
            Http::redirect('/verify-otp');
        }
    }

    public function resendOtp(array $params): never
    {
        $pending = Auth::pending();
        if ($pending === null) {
            $this->flash('error', t('error.otp_session_expired'));
            Http::redirect('/login');
        }

        try {
            $challenge = AuthService::resendOtp((string) $pending['user_id'], (string) $pending['purpose']);
            Auth::setPending(array_merge($pending, [
                'delivered' => $challenge['delivered'],
                'delivery_error' => $challenge['delivery_error'],
                'expires_at' => time() + (int) Config::get('auth.otp_pending_ttl'),
            ]));
            $this->flash('success', t('flash.otp_resent'));
        } catch (AppError $error) {
            $this->flash('error', I18n::error($error->errorCode));
        }

        Http::redirect('/verify-otp');
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

    /**
     * The code arrives as six single-digit `code[]` boxes; a single `code`
     * field is still accepted so older clients and scripts keep working.
     *
     * Slots are read by index rather than concatenated in arrival order: form
     * fields are not guaranteed to arrive in document order, and a scrambled
     * code would simply look wrong to the user.
     *
     * @param array<string, mixed> $input
     */
    private static function otpCodeFromInput(array $input): string
    {
        $raw = $input['code'] ?? '';

        if (is_array($raw)) {
            $code = '';
            for ($slot = 0; $slot < 6; $slot++) {
                $digit = $raw[$slot] ?? $raw[(string) $slot] ?? null;
                if (!is_string($digit) || preg_match('/^\d$/', trim($digit)) !== 1) {
                    return '';
                }
                $code .= trim($digit);
            }
            return $code;
        }

        return is_string($raw) ? trim($raw) : '';
    }
}
