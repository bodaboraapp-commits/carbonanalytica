<?php

declare(strict_types=1);

use App\AppError;

use App\Db;
use App\Repositories\UserRepository;
use App\Services\AuthService;

return [
    'checks the password and audits the first factor' => static function (): void {
        $account = make_user('signin@example.com');

        $user = AuthService::signIn('signin@example.com', $account['password']);

        assert_same((string) $account['user']['id'], (string) $user['id']);
        assert_same(1, count_rows('audit_logs', "action = 'auth.signin.password_verified'"));

        // A password alone never signs anyone in and never touches last_seen.
        $fresh = UserRepository::findById((string) $user['id']);
        assert_null($fresh['last_seen_at']);
        assert_same(0, count_rows('audit_logs', "action = 'auth.signin'"));
    },

    'accepts the email in any case at sign-in' => static function (): void {
        $account = make_user('case@example.com');
        $user = AuthService::signIn('CASE@Example.com', $account['password']);
        assert_same((string) $account['user']['id'], (string) $user['id']);
    },

    'rejects a wrong password and audits the failure' => static function (): void {
        make_user('wrongpass@example.com');

        assert_app_error('invalid_credentials', static function (): void {
            AuthService::signIn('wrongpass@example.com', 'this is not the password');
        });

        $audit = Db::selectOne("SELECT * FROM audit_logs WHERE action = 'auth.signin.failed' ORDER BY created_at DESC LIMIT 1");
        assert_not_null($audit);
        assert_contains('bad_password', (string) $audit['metadata']);
    },

    'returns the same error for an unknown account' => static function (): void {
        assert_app_error('invalid_credentials', static function (): void {
            AuthService::signIn('nobody@example.com', 'whatever password');
        });

        $audit = Db::selectOne("SELECT * FROM audit_logs WHERE action = 'auth.signin.failed' ORDER BY created_at DESC LIMIT 1");
        assert_not_null($audit);
        assert_null($audit['actor_user_id']);
        assert_contains('unknown_account', (string) $audit['metadata']);
    },

    'rate limits repeated failures for the same email' => static function (): void {
        make_user('ratelimit@example.com');

        // The per-email limit is 10 attempts per window: the 11th is refused.
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            try {
                AuthService::signIn('ratelimit@example.com', 'wrong password');
                throw new RuntimeException('Expected invalid credentials on attempt ' . $attempt);
            } catch (AppError $error) {
                assert_same('invalid_credentials', $error->errorCode);
            }
        }

        assert_app_error('rate_limited', static function (): void {
            AuthService::signIn('ratelimit@example.com', 'wrong password');
        });

        // Even the correct password is refused while the limit holds.
        assert_app_error('rate_limited', static function (): void {
            AuthService::signIn('ratelimit@example.com', 'correct horse battery staple');
        });
    },

    'does not rate limit a successful sign-in permanently' => static function (): void {
        $account = make_user('resetlimit@example.com');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                AuthService::signIn('resetlimit@example.com', 'wrong password');
            } catch (AppError) {
                // expected
            }
        }

        $user = AuthService::signIn('resetlimit@example.com', $account['password']);
        assert_same((string) $account['user']['id'], (string) $user['id']);
    },
];
