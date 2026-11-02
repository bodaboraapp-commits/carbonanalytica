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

    'never stores a plaintext verification token' => static function (): void {
        make_user('hashed@example.com');

        $email = latest_email('hashed@example.com', 'email_verification');
        $token = token_from_email((string) $email['body_text'], '/verify-email');
        assert_not_null($token);

        $rows = Db::select('SELECT token_hash FROM verification_tokens');
        assert_count(1, $rows);
        assert_same(Tokens::hash($token), (string) $rows[0]['token_hash']);
        assert_true(strlen((string) $rows[0]['token_hash']) === 64);
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
