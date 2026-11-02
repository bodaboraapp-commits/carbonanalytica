<?php

declare(strict_types=1);

use App\Repositories\UserRepository;
use App\Services\AuthService;

/**
 * Sign-in second factor is OFF by default (migration 0006) and can be switched
 * on per account from the staff console. Sign-up email confirmation is separate
 * and unaffected.
 */
return [
    'new accounts have two-factor off by default' => static function (): void {
        $account = make_user('default-2fa@example.com');
        $user = UserRepository::findById((string) $account['user']['id']);

        assert_same(0, (int) $user['otp_enabled']);
    },

    'password-only sign-in completes without a one-time code' => static function (): void {
        $account = make_user('password-only@example.com');
        $userId = (string) $account['user']['id'];

        // This is what the controller does when otp_enabled is 0.
        $user = AuthService::signIn('password-only@example.com', $account['password']);
        assert_same(0, (int) $user['otp_enabled']);

        $fresh = AuthService::completePasswordSignIn($user);

        assert_same($userId, (string) $fresh['id']);
        assert_same(1, count_rows('audit_logs', "action = 'auth.signin'"));
        assert_same(0, count_rows('otp_codes'), 'no code should be issued for password-only sign-in');
        assert_not_null($fresh['last_seen_at']);
    },

    'an admin can switch the second factor on and off' => static function (): void {
        $account = make_user('staff-2fa@example.com');
        $userId = (string) $account['user']['id'];

        $enabled = AuthService::setOtpEnabled($userId, true);
        assert_same(1, (int) $enabled['otp_enabled']);
        assert_same(1, count_rows('audit_logs', "action = 'auth.otp.preference_changed'"));

        $disabled = AuthService::setOtpEnabled($userId, false);
        assert_same(0, (int) $disabled['otp_enabled']);
        assert_same(2, count_rows('audit_logs', "action = 'auth.otp.preference_changed'"));
    },

    'enabling two-factor changes what sign-in reports' => static function (): void {
        $account = make_user('require-2fa@example.com');
        $userId = (string) $account['user']['id'];

        AuthService::setOtpEnabled($userId, true);

        // The controller branches on this flag: 1 => start an OTP challenge.
        $user = AuthService::signIn('require-2fa@example.com', $account['password']);
        assert_same(1, (int) $user['otp_enabled']);
    },

    'a password reset does not silently change the preference' => static function (): void {
        $account = make_user('reset-2fa@example.com');
        $userId = (string) $account['user']['id'];
        AuthService::setOtpEnabled($userId, true);

        AuthService::requestPasswordReset('reset-2fa@example.com');
        $email = latest_email('reset-2fa@example.com', 'password_reset');
        $token = token_from_email((string) $email['body_text'], '/reset-password');
        assert_not_null($token);

        AuthService::resetPassword($token, 'another long passphrase');

        $fresh = UserRepository::findById($userId);
        assert_same(1, (int) $fresh['otp_enabled'], 'resetting a password must not disable two-factor');
    },

    'toggling a missing account is not found' => static function (): void {
        assert_app_error('not_found', static function (): void {
            AuthService::setOtpEnabled('00000000-0000-4000-8000-000000000000', true);
        });
    },
];
