<?php

declare(strict_types=1);

use App\Auth;
use App\Db;
use App\OtpService;
use App\Repositories\UserRepository;
use App\Services\AuthService;

/**
 * Email one-time codes: sign-up verification and the sign-in second factor.
 */
return [
    'sign-up code verifies the email and signs the user in' => static function (): void {
        $created = AuthService::signUp('Ama Serwaa', 'ama@example.com', 'correct horse battery staple', 'en');
        $userId = (string) $created['user']['id'];

        $code = request_otp('ama@example.com', 'signup');
        $result = AuthService::verifyOtp($userId, 'signup', $code);

        assert_true($result['newly_verified']);
        assert_same($userId, (string) $result['user']['id']);
        assert_not_null($result['user']['email_verified_at']);

        assert_same(1, count_rows('audit_logs', "action = 'auth.otp.verified'"));
        assert_same(1, count_rows('audit_logs', "action = 'auth.email_verified'"));
        assert_same(1, count_rows('audit_logs', "action = 'auth.signin'"));
        assert_null(OtpService::active($userId, 'signup'), 'the code must be consumed');
    },

    'a sign-in code authenticates without re-verifying an already verified address' => static function (): void {
        $account = make_user('second@example.com');
        $userId = (string) $account['user']['id'];
        UserRepository::markEmailVerified($userId);

        AuthService::signIn('second@example.com', $account['password']);
        $code = request_otp('second@example.com', 'signin');

        $result = AuthService::verifyOtp($userId, 'signin', $code);

        assert_false($result['newly_verified']);
        assert_same(1, count_rows('audit_logs', "action = 'auth.signin'"));
        assert_not_null(UserRepository::findById($userId)['last_seen_at']);
    },

    'a sign-in code also proves the address when it was still unverified' => static function (): void {
        $account = make_user('proof@example.com');
        $userId = (string) $account['user']['id'];
        assert_null($account['user']['email_verified_at']);

        AuthService::signIn('proof@example.com', $account['password']);
        $code = request_otp('proof@example.com', 'signin');
        $result = AuthService::verifyOtp($userId, 'signin', $code);

        assert_true($result['newly_verified']);
        assert_not_null($result['user']['email_verified_at']);
    },

    'a code can be used only once' => static function (): void {
        $created = AuthService::signUp('Once Only', 'once@example.com', 'correct horse battery staple', 'en');
        $userId = (string) $created['user']['id'];
        $code = request_otp('once@example.com', 'signup');

        AuthService::verifyOtp($userId, 'signup', $code);

        assert_app_error('otp_invalid', static function () use ($userId, $code): void {
            AuthService::verifyOtp($userId, 'signup', $code);
        });
    },

    'a wrong code is refused and counts an attempt' => static function (): void {
        $created = AuthService::signUp('Wrong Code', 'wrongcode@example.com', 'correct horse battery staple', 'en');
        $userId = (string) $created['user']['id'];
        $code = request_otp('wrongcode@example.com', 'signup');
        $wrong = $code === '000000' ? '111111' : '000000';

        assert_app_error('otp_invalid', static function () use ($userId, $wrong): void {
            AuthService::verifyOtp($userId, 'signup', $wrong);
        });

        $row = OtpService::active($userId, 'signup');
        assert_not_null($row);
        assert_same(1, (int) $row['attempts']);
    },

    'five wrong guesses kill the code' => static function (): void {
        $created = AuthService::signUp('Brute Force', 'brute@example.com', 'correct horse battery staple', 'en');
        $userId = (string) $created['user']['id'];
        $code = request_otp('brute@example.com', 'signup');
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $expected = $attempt === 5 ? 'otp_too_many_attempts' : 'otp_invalid';
            assert_app_error($expected, static function () use ($userId, $wrong): void {
                AuthService::verifyOtp($userId, 'signup', $wrong);
            });
        }

        // Even the right code cannot rescue a burned code.
        assert_app_error('otp_too_many_attempts', static function () use ($userId, $code): void {
            AuthService::verifyOtp($userId, 'signup', $code);
        });
    },

    'an expired code is refused' => static function (): void {
        $created = AuthService::signUp('Expired Code', 'expiredcode@example.com', 'correct horse battery staple', 'en');
        $userId = (string) $created['user']['id'];
        $code = request_otp('expiredcode@example.com', 'signup');

        Db::execute(
            'UPDATE otp_codes SET expires_at = ? WHERE user_id = ? AND purpose = ?',
            [gmdate('Y-m-d H:i:s', time() - 60), $userId, 'signup'],
        );

        assert_app_error('otp_expired', static function () use ($userId, $code): void {
            AuthService::verifyOtp($userId, 'signup', $code);
        });
    },

    'requesting a new code invalidates the previous one' => static function (): void {
        $created = AuthService::signUp('Resend Case', 'resend@example.com', 'correct horse battery staple', 'en');
        $userId = (string) $created['user']['id'];

        $first = request_otp('resend@example.com', 'signup');
        $second = request_otp('resend@example.com', 'signup');

        if ($first !== $second) {
            assert_app_error('otp_invalid', static function () use ($userId, $first): void {
                AuthService::verifyOtp($userId, 'signup', $first);
            });
        }

        AuthService::verifyOtp($userId, 'signup', $second);
        assert_same(0, count_rows('otp_codes', 'user_id = ? AND consumed_at IS NULL', [$userId]));
    },

    'resending a code is rate limited' => static function (): void {
        $created = AuthService::signUp('Rate Limit OTP', 'otprate@example.com', 'correct horse battery staple', 'en');
        $userId = (string) $created['user']['id'];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            AuthService::resendOtp($userId, 'signup');
        }

        assert_app_error('rate_limited', static function () use ($userId): void {
            AuthService::resendOtp($userId, 'signup');
        });
    },

    'pending state is not a session and login clears it' => static function (): void {
        $account = make_user('pending@example.com');
        $userId = (string) $account['user']['id'];

        Auth::setPending([
            'user_id' => $userId,
            'purpose' => 'signin',
            'email' => 'pending@example.com',
            'expires_at' => time() + 900,
        ]);

        assert_null(Auth::user(), 'a pending challenge must not authenticate the request');
        assert_not_null(Auth::pending());

        $user = UserRepository::findById($userId);
        assert_not_null($user);
        Auth::login($user);

        assert_null(Auth::pending());
        assert_same($userId, (string) Auth::user()['id']);
    },

    'stale pending state expires' => static function (): void {
        $account = make_user('stale@example.com');

        Auth::setPending([
            'user_id' => (string) $account['user']['id'],
            'purpose' => 'signin',
            'email' => 'stale@example.com',
            'expires_at' => time() - 1,
        ]);

        assert_null(Auth::pending());
    },
];
