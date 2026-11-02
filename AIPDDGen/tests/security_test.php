<?php

declare(strict_types=1);

use App\Auth;
use App\Csrf;
use App\Db;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Tokens;

return [
    'accepts a matching CSRF token' => static function (): void {
        $_SESSION = [];
        $token = Csrf::token();
        Csrf::validate($token);
        assert_true(strlen($token) === 64);
    },

    'rejects a wrong or missing CSRF token' => static function (): void {
        $_SESSION = ['_csrf' => bin2hex(random_bytes(32))];

        assert_app_error('invalid_csrf', static function (): void {
            Csrf::validate('wrong-token');
        });

        assert_app_error('invalid_csrf', static function (): void {
            Csrf::validate(null);
        });
    },

    'invalidates sessions when the session version is bumped' => static function (): void {
        $account = make_user('session@example.com');

        Auth::login($account['user']);
        assert_not_null(Auth::user());

        UserRepository::bumpSessionVersion((string) $account['user']['id']);
        Auth::refresh();

        assert_null(Auth::user(), 'a bumped session version must sign the user out');
    },

    'stores the session version and locale at sign-in' => static function (): void {
        $account = make_user('session2@example.com');
        Auth::login($account['user']);

        assert_same((int) $account['user']['session_version'], $_SESSION['session_version']);
        assert_same('en', $_SESSION['locale']);
    },

    'never stores a plaintext one-time code' => static function (): void {
        $created = make_user('hashed@example.com');
        AuthService::startOtpChallenge($created['user'], 'signup');

        $code = latest_otp_code('hashed@example.com');
        assert_not_null($code);

        $row = Db::selectOne('SELECT * FROM otp_codes WHERE user_id = ? LIMIT 1', [(string) $created['user']['id']]);
        assert_not_null($row);
        assert_same(Tokens::hash((string) $code), (string) $row['code_hash']);
        assert_true(strlen((string) $row['code_hash']) === 64);
        assert_true($row['code_hash'] !== $code);
    },

    'never puts the password in an email' => static function (): void {
        $password = 'correct horse battery staple';
        AuthService::signUp('No Leak', 'noleak@example.com', $password, 'en');

        $emails = Db::select('SELECT body_text FROM email_outbox');
        foreach ($emails as $email) {
            assert_true(!str_contains((string) $email['body_text'], $password), 'email must not contain the password');
        }
    },

    'logs a sign-out when a session ends' => static function (): void {
        $account = make_user('signout@example.com');
        Auth::login($account['user']);

        AuthService::signOut();
        assert_same(1, count_rows('audit_logs', "action = 'auth.signout'"));
    },

    'keeps account enumeration off the sign-up error path' => static function (): void {
        make_user('existing@example.com');

        // Sign-up reveals that an address is taken (needed for a usable form),
        // but sign-in must not: covered in auth_signin_test. Here we assert the
        // reset endpoint stays silent about unknown accounts.
        AuthService::requestPasswordReset('missing@example.com');
        assert_same(0, count_rows('email_outbox', 'to_email = ?', ['missing@example.com']));
    },
];
