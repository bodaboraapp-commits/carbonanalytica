<?php

declare(strict_types=1);

use App\Db;
use App\Repositories\TeamRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Tokens;

return [
    'creates the account, workspace, owner membership and audit trail' => static function (): void {
        $created = AuthService::signUp('Ama Serwaa', 'AMA@Example.com', 'correct horse battery staple', 'en');

        assert_same('ama@example.com', $created['user']['email']);
        assert_same('Ama Serwaa', $created['user']['name']);
        assert_same('en', $created['user']['locale']);
        assert_same('free', $created['team']['plan']);

        $membership = TeamRepository::findMembership((string) $created['team']['id'], (string) $created['user']['id']);
        assert_not_null($membership);
        assert_same('owner', $membership['role']);

        assert_same((string) $created['team']['id'], (string) $created['user']['default_team_id']);

        assert_same(1, count_rows('team_members', 'user_id = ?', [(string) $created['user']['id']]));
        assert_same(1, count_rows('audit_logs', "action = 'auth.signup'"));
    },

    'queues a verification email with a hashed token' => static function (): void {
        $created = AuthService::signUp('Kofi Mensah', 'kofi@example.com', 'correct horse battery staple', 'fr');

        assert_same('fr', $created['user']['locale']);

        $email = latest_email('kofi@example.com', 'email_verification');
        assert_not_null($email);
        assert_contains('Confirmez', (string) $email['subject']);

        $token = token_from_email((string) $email['body_text'], '/verify-email');
        assert_not_null($token);

        $stored = Db::selectOne(
            "SELECT * FROM verification_tokens WHERE user_id = ? AND type = 'email_verification' LIMIT 1",
            [(string) $created['user']['id']],
        );
        assert_not_null($stored);
        assert_true($stored['token_hash'] !== $token, 'the plaintext token must not be stored');
        assert_same(Tokens::hash($token), (string) $stored['token_hash']);
    },

    'rejects a duplicate email regardless of case' => static function (): void {
        make_user('duplicate@example.com');

        assert_app_error('email_taken', static function (): void {
            AuthService::signUp('Someone Else', 'DUPLICATE@Example.com', 'correct horse battery staple', 'en');
        });
    },

    'rejects a password shorter than twelve characters' => static function (): void {
        assert_app_error('validation_error', static function (): void {
            AuthService::signUp('Short Password', 'short@example.com', 'short', 'en');
        });
        assert_same(0, count_rows('users'));
    },

    'normalises and trims the email address' => static function (): void {
        $created = AuthService::signUp('Trim Test', '  Trim@Example.COM  ', 'correct horse battery staple', 'en');
        assert_same('trim@example.com', $created['user']['email']);
        assert_not_null(UserRepository::findByEmail('tRiM@example.com'));
    },

    'allows signing in immediately after signing up' => static function (): void {
        $created = AuthService::signUp('Immediate', 'immediate@example.com', 'correct horse battery staple', 'en');
        $signedIn = AuthService::signIn('immediate@example.com', 'correct horse battery staple');
        assert_same((string) $created['user']['id'], (string) $signedIn['id']);
    },
];
