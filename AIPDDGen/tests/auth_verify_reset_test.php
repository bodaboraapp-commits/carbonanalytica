<?php

declare(strict_types=1);

use App\Db;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Tokens;
use App\Uuid;

return [
    'verifies an email address with the emailed token' => static function (): void {
        $account = make_user('verify@example.com');
        $email = latest_email('verify@example.com', 'email_verification');
        $token = token_from_email((string) $email['body_text'], '/verify-email');
        assert_not_null($token);

        $result = AuthService::verifyEmail($token);
        assert_same((string) $account['user']['id'], (string) $result['user']['id']);
        assert_false($result['already_verified']);
        assert_not_null($result['user']['email_verified_at']);

        assert_same(1, count_rows('audit_logs', "action = 'auth.email_verified'"));
    },

    'refuses to reuse a verification token' => static function (): void {
        make_user('reuse@example.com');
        $email = latest_email('reuse@example.com', 'email_verification');
        $token = token_from_email((string) $email['body_text'], '/verify-email');
        assert_not_null($token);

        AuthService::verifyEmail($token);

        assert_app_error('invalid_token', static function () use ($token): void {
            AuthService::verifyEmail($token);
        });
    },

    'refuses an expired verification token' => static function (): void {
        $account = make_user('expired@example.com');
        $token = 'expired-' . Tokens::generate();

        Db::insert('verification_tokens', [
            'id' => Uuid::v4(),
            'user_id' => (string) $account['user']['id'],
            'type' => 'email_verification',
            'token_hash' => Tokens::hash($token),
            'expires_at' => gmdate('Y-m-d H:i:s', time() - 60),
        ]);

        assert_app_error('token_expired', static function () use ($token): void {
            AuthService::verifyEmail($token);
        });
    },

    'queues a password reset email without leaking account existence' => static function (): void {
        make_user('reset@example.com');

        AuthService::requestPasswordReset('reset@example.com');
        $email = latest_email('reset@example.com', 'password_reset');
        assert_not_null($email);
        assert_contains('/reset-password?token=', (string) $email['body_text']);

        // Unknown address: no exception, no email.
        AuthService::requestPasswordReset('unknown@example.com');
        assert_null(latest_email('unknown@example.com'));
    },

    'resets the password, invalidates old sessions and burns the token' => static function (): void {
        $account = make_user('newpassword@example.com');
        $userId = (string) $account['user']['id'];
        $versionBefore = (int) $account['user']['session_version'];

        AuthService::requestPasswordReset('newpassword@example.com');
        $email = latest_email('newpassword@example.com', 'password_reset');
        $token = token_from_email((string) $email['body_text'], '/reset-password');
        assert_not_null($token);

        $newPassword = 'an entirely different passphrase';
        AuthService::resetPassword($token, $newPassword);

        $updated = UserRepository::findById($userId);
        assert_same($versionBefore + 1, (int) $updated['session_version'], 'password reset must revoke sessions');
        assert_not_null($updated['email_verified_at'], 'email ownership is proven by the reset link');

        $signedIn = AuthService::signIn('newpassword@example.com', $newPassword);
        assert_same($userId, (string) $signedIn['id']);

        assert_app_error('invalid_credentials', static function (): void {
            AuthService::signIn('newpassword@example.com', 'correct horse battery staple');
        });

        assert_app_error('invalid_token', static function () use ($token, $newPassword): void {
            AuthService::resetPassword($token, $newPassword);
        });
    },

    'rejects a malformed reset token' => static function (): void {
        make_user('badtoken@example.com');
        assert_app_error('invalid_token', static function (): void {
            AuthService::resetPassword('not-a-real-token-but-long-enough', 'another long password');
        });
    },

    'rejects a short replacement password' => static function (): void {
        make_user('shortreset@example.com');
        AuthService::requestPasswordReset('shortreset@example.com');
        $email = latest_email('shortreset@example.com', 'password_reset');
        $token = token_from_email((string) $email['body_text'], '/reset-password');
        assert_not_null($token);

        assert_app_error('validation_error', static function () use ($token): void {
            AuthService::resetPassword($token, 'short');
        });
    },
];
