<?php

declare(strict_types=1);

namespace App\Services;

use App\AppError;
use App\Audit;
use App\Config;
use App\Db;
use App\I18n;
use App\Mailer;
use App\OtpService;
use App\Password;
use App\RateLimit;
use App\Repositories\UserRepository;
use App\Tokens;

/**
 * Authentication services: sign-up, sign-in (password + emailed one-time code),
 * password reset and Google account linking.
 *
 * Rules:
 *  - passwords are hashed with PHP's password API and never logged;
 *  - sign-up and sign-in send a six-digit code; a password alone never
 *    establishes a session;
 *  - codes are stored hashed, are single use, expire, and die after a handful
 *    of wrong guesses (see OtpService);
 *  - reset and invitation tokens are random and stored hashed;
 *  - failed sign-ins are audited and rate limited, and an unknown account and
 *    a wrong password produce the same error (no enumeration);
 *  - a password reset bumps `session_version`, killing every existing session.
 */
final class AuthService
{
    public static function signUp(string $name, string $email, string $password, ?string $locale = null): array
    {
        $email = mb_strtolower(trim($email));
        $locale = in_array($locale, I18n::available(), true) ? $locale : 'en';

        if (mb_strlen($password) < Password::MIN_LENGTH || mb_strlen($password) > Password::MAX_LENGTH) {
            throw AppError::validation(['password' => 'too_short']);
        }

        if (UserRepository::findByEmail($email) !== null) {
            throw AppError::emailTaken();
        }

        $passwordHash = Password::hash($password);

        $created = Db::transaction(static function () use ($name, $email, $passwordHash, $locale): array {
            $user = UserRepository::create([
                'email' => $email,
                'password_hash' => $passwordHash,
                'name' => $name,
                'locale' => $locale,
            ]);

            $team = TeamService::createPersonalTeam((string) $user['id'], $name, $locale);
            UserRepository::setDefaultTeam((string) $user['id'], (string) $team['id']);

            Audit::record([
                'team_id' => (string) $team['id'],
                'actor_user_id' => (string) $user['id'],
                'action' => 'auth.signup',
                'target_type' => 'user',
                'target_id' => (string) $user['id'],
                'metadata' => ['locale' => $locale],
            ]);

            return ['user' => $user, 'team' => $team];
        });

        // Re-read so callers see the default workspace and verification state.
        $created['user'] = UserRepository::findById((string) $created['user']['id']) ?? $created['user'];

        return $created;
    }

    /**
     * Send the one-time code that completes a sign-up or a sign-in.
     *
     * @param array<string, mixed> $user
     * @return array{purpose: string, email: string, expires_at: string, delivered: bool, delivery_error: string|null}
     */
    public static function startOtpChallenge(array $user, string $purpose): array
    {
        $challenge = OtpService::issue($user, $purpose);

        Audit::record([
            'actor_user_id' => (string) $user['id'],
            'action' => 'auth.otp.sent',
            'target_type' => 'user',
            'target_id' => (string) $user['id'],
            'metadata' => ['purpose' => $purpose],
        ]);

        return $challenge;
    }

    /**
     * First factor: check the password. The caller must still complete the
     * one-time code before a session is created.
     *
     * @return array<string, mixed> the user row
     */
    public static function signIn(string $email, string $password): array
    {
        $email = mb_strtolower(trim($email));

        $ipLimit = RateLimit::consume(
            'auth:login:ip:' . \App\Http::ip(),
            (int) Config::get('auth.login_ip_limit.max'),
            (int) Config::get('auth.login_ip_limit.window'),
        );
        if (!$ipLimit['allowed']) {
            throw AppError::rateLimited($ipLimit['retry_after']);
        }

        $emailLimit = RateLimit::consume(
            'auth:login:email:' . $email,
            (int) Config::get('auth.login_email_limit.max'),
            (int) Config::get('auth.login_email_limit.window'),
        );
        if (!$emailLimit['allowed']) {
            throw AppError::rateLimited($emailLimit['retry_after']);
        }

        $user = UserRepository::findByEmail($email);

        if ($user === null) {
            Audit::record([
                'action' => 'auth.signin.failed',
                'metadata' => ['email' => $email, 'reason' => 'unknown_account'],
            ]);
            throw AppError::invalidCredentials();
        }

        if (!Password::verify($password, $user['password_hash'] !== null ? (string) $user['password_hash'] : null)) {
            Audit::record([
                'actor_user_id' => (string) $user['id'],
                'action' => 'auth.signin.failed',
                'target_type' => 'user',
                'target_id' => (string) $user['id'],
                'metadata' => ['reason' => 'bad_password'],
            ]);
            throw AppError::invalidCredentials();
        }

        RateLimit::reset('auth:login:email:' . $email);

        // Transparent hash upgrade when the algorithm settings change.
        if ($user['password_hash'] !== null && Password::needsRehash((string) $user['password_hash'])) {
            UserRepository::update((string) $user['id'], ['password_hash' => Password::hash($password)]);
        }

        Audit::record([
            'actor_user_id' => (string) $user['id'],
            'action' => 'auth.signin.password_verified',
            'target_type' => 'user',
            'target_id' => (string) $user['id'],
        ]);

        return $user;
    }

    /**
     * Complete a password-only sign-in when the account has the email second
     * factor switched off (the default; see migration 0006).
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed> the refreshed user row
     */
    public static function completePasswordSignIn(array $user): array
    {
        $userId = (string) $user['id'];

        Db::transaction(static function () use ($userId): void {
            UserRepository::touchLastSeen($userId);

            Audit::record([
                'actor_user_id' => $userId,
                'action' => 'auth.signin',
                'target_type' => 'user',
                'target_id' => $userId,
                'metadata' => ['method' => 'password'],
            ]);
        });

        return UserRepository::findById($userId) ?? $user;
    }

    /**
     * Enable or disable the email second factor for one account.
     *
     * Called by the staff console "Two-factor sign-in" button. The preference is
     * per account: turning it on for an admin does not affect tenant users.
     *
     * @return array<string, mixed> the refreshed user row
     */
    public static function setOtpEnabled(string $userId, bool $enabled): array
    {
        $user = UserRepository::findById($userId);
        if ($user === null) {
            throw AppError::notFound('That account');
        }

        Db::transaction(static function () use ($userId, $enabled): void {
            UserRepository::setOtpEnabled($userId, $enabled);

            Audit::record([
                'actor_user_id' => $userId,
                'action' => 'auth.otp.preference_changed',
                'target_type' => 'user',
                'target_id' => $userId,
                'metadata' => ['enabled' => $enabled],
            ]);
        });

        return UserRepository::findById($userId) ?? $user;
    }

    /**
     * Second factor: check the emailed code and establish the signed-in state.
     *
     * @return array{user: array<string, mixed>, newly_verified: bool}
     */
    public static function verifyOtp(string $userId, string $purpose, string $code): array
    {
        $limit = RateLimit::consume(
            'auth:otp:verify:' . $userId,
            (int) Config::get('auth.otp_verify_limit.max'),
            (int) Config::get('auth.otp_verify_limit.window'),
        );
        if (!$limit['allowed']) {
            throw AppError::rateLimited($limit['retry_after']);
        }

        $user = UserRepository::findById($userId);
        if ($user === null) {
            throw AppError::unauthorized();
        }

        OtpService::verify($userId, $purpose, $code);
        RateLimit::reset('auth:otp:verify:' . $userId);

        $newlyVerified = false;

        Db::transaction(static function () use ($userId, $purpose, $user, &$newlyVerified): void {
            // Proving control of the address with the code also verifies the email,
            // whatever the original purpose (sign-up or sign-in).
            if ($user['email_verified_at'] === null) {
                UserRepository::markEmailVerified($userId);
                $newlyVerified = true;
            }

            UserRepository::touchLastSeen($userId);

            Audit::record([
                'actor_user_id' => $userId,
                'action' => 'auth.otp.verified',
                'target_type' => 'user',
                'target_id' => $userId,
                'metadata' => ['purpose' => $purpose],
            ]);

            if ($newlyVerified) {
                Audit::record([
                    'actor_user_id' => $userId,
                    'action' => 'auth.email_verified',
                    'target_type' => 'user',
                    'target_id' => $userId,
                ]);
            }

            Audit::record([
                'actor_user_id' => $userId,
                'action' => 'auth.signin',
                'target_type' => 'user',
                'target_id' => $userId,
                'metadata' => ['method' => 'password+otp', 'purpose' => $purpose],
            ]);
        });

        $fresh = UserRepository::findById($userId) ?? $user;

        return ['user' => $fresh, 'newly_verified' => $newlyVerified];
    }

    /**
     * Re-send the pending code (rate limited per user and purpose).
     *
     * @return array{purpose: string, email: string, expires_at: string, delivered: bool, delivery_error: string|null}
     */
    public static function resendOtp(string $userId, string $purpose): array
    {
        $user = UserRepository::findById($userId);
        if ($user === null) {
            throw AppError::unauthorized();
        }

        $limit = RateLimit::consume(
            'auth:otp:resend:' . $userId . ':' . $purpose,
            (int) Config::get('auth.otp_resend_limit.max'),
            (int) Config::get('auth.otp_resend_limit.window'),
        );
        if (!$limit['allowed']) {
            throw AppError::rateLimited($limit['retry_after']);
        }

        $challenge = OtpService::issue($user, $purpose);

        Audit::record([
            'actor_user_id' => $userId,
            'action' => 'auth.otp.resent',
            'target_type' => 'user',
            'target_id' => $userId,
            'metadata' => ['purpose' => $purpose],
        ]);

        return $challenge;
    }

    public static function signOut(): void
    {
        $user = \App\Auth::user();
        if ($user !== null) {
            Audit::record([
                'actor_user_id' => (string) $user['id'],
                'action' => 'auth.signout',
                'target_type' => 'user',
                'target_id' => (string) $user['id'],
            ]);
        }
    }

    /**
     * Start a password reset. Always succeeds, whether or not the address exists,
     * so the endpoint cannot be used to discover accounts.
     */
    public static function requestPasswordReset(string $email): void
    {
        $email = mb_strtolower(trim($email));

        $ipLimit = RateLimit::consume(
            'auth:reset:ip:' . \App\Http::ip(),
            (int) Config::get('auth.reset_ip_limit.max'),
            (int) Config::get('auth.reset_ip_limit.window'),
        );
        if (!$ipLimit['allowed']) {
            throw AppError::rateLimited($ipLimit['retry_after']);
        }

        $emailLimit = RateLimit::consume(
            'auth:reset:email:' . $email,
            (int) Config::get('auth.reset_email_limit.max'),
            (int) Config::get('auth.reset_email_limit.window'),
        );
        if (!$emailLimit['allowed']) {
            throw AppError::rateLimited($emailLimit['retry_after']);
        }

        $user = UserRepository::findByEmail($email);

        if ($user === null) {
            Audit::record([
                'action' => 'auth.password_reset.requested_unknown_email',
                'metadata' => ['email' => $email],
            ]);
            return;
        }

        $token = Tokens::generate();
        $ttl = (int) Config::get('auth.password_reset_ttl');

        Db::transaction(static function () use ($user, $token, $ttl): void {
            Db::execute(
                "UPDATE verification_tokens SET used_at = NOW(3)
                 WHERE user_id = ? AND type = 'password_reset' AND used_at IS NULL",
                [(string) $user['id']],
            );

            Db::insert('verification_tokens', [
                'id' => \App\Uuid::v4(),
                'user_id' => (string) $user['id'],
                'type' => 'password_reset',
                'token_hash' => Tokens::hash($token),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttl),
                'requested_ip' => \App\Http::ip(),
            ]);

            $locale = (string) $user['locale'];
            Mailer::send(
                (string) $user['email'],
                I18n::forLocale($locale, 'email.reset_subject'),
                I18n::forLocale($locale, 'email.reset_body', [
                    'name' => (string) $user['name'],
                    'url' => absolute_url('/reset-password?token=' . rawurlencode($token)),
                ]),
                'password_reset',
            );

            Audit::record([
                'actor_user_id' => (string) $user['id'],
                'action' => 'auth.password_reset.requested',
                'target_type' => 'user',
                'target_id' => (string) $user['id'],
            ]);
        });
    }

    /** Complete a password reset: single use, revokes every existing session. */
    public static function resetPassword(string $token, string $password): array
    {
        if (mb_strlen($password) < Password::MIN_LENGTH || mb_strlen($password) > Password::MAX_LENGTH) {
            throw AppError::validation(['password' => 'too_short']);
        }

        $record = Db::selectOne(
            'SELECT * FROM verification_tokens WHERE token_hash = ? AND type = ? LIMIT 1',
            [Tokens::hash($token), 'password_reset'],
        );

        if ($record === null) {
            throw AppError::invalidToken();
        }
        if ($record['used_at'] !== null) {
            throw AppError::invalidToken();
        }
        if (strtotime((string) $record['expires_at']) <= time()) {
            throw AppError::tokenExpired();
        }

        $user = UserRepository::findById((string) $record['user_id']);
        if ($user === null) {
            throw AppError::invalidToken();
        }

        $passwordHash = Password::hash($password);

        Db::transaction(static function () use ($record, $user, $passwordHash): void {
            Db::execute('UPDATE verification_tokens SET used_at = NOW(3) WHERE id = ?', [(string) $record['id']]);

            UserRepository::update((string) $user['id'], [
                'password_hash' => $passwordHash,
                // Proving control of the address through the emailed link also verifies it.
                'email_verified_at' => $user['email_verified_at'] ?? gmdate('Y-m-d H:i:s'),
            ]);

            // Every existing session dies with the old password.
            UserRepository::bumpSessionVersion((string) $user['id']);

            Audit::record([
                'actor_user_id' => (string) $user['id'],
                'action' => 'auth.password_reset.completed',
                'target_type' => 'user',
                'target_id' => (string) $user['id'],
            ]);
        });

        return UserRepository::findById((string) $user['id']) ?? $user;
    }

    /**
     * Sign in with a verified Google profile: link to an existing account with
     * the same address, or create a password-less account and its workspace.
     *
     * @param array{provider_account_id: string, email: string, email_verified: bool, name: string, image_url: ?string} $profile
     */
    public static function signInWithGoogle(array $profile): array
    {
        if (empty($profile['email_verified'])) {
            // Never link an account to an unverified third-party address.
            throw new AppError('invalid_email', 422, 'Google did not verify that email address.');
        }

        $linked = Db::selectOne(
            'SELECT u.* FROM accounts a INNER JOIN users u ON u.id = a.user_id
             WHERE a.provider = ? AND a.provider_account_id = ? LIMIT 1',
            ['google', $profile['provider_account_id']],
        );

        if ($linked !== null) {
            if ($linked['deleted_at'] !== null) {
                throw AppError::unauthorized();
            }
            Audit::record([
                'actor_user_id' => (string) $linked['id'],
                'action' => 'auth.signin_google',
                'target_type' => 'user',
                'target_id' => (string) $linked['id'],
            ]);
            return $linked;
        }

        $byEmail = UserRepository::findByEmail($profile['email']);

        if ($byEmail !== null) {
            Db::transaction(static function () use ($byEmail, $profile): void {
                Db::insert('accounts', [
                    'id' => \App\Uuid::v4(),
                    'user_id' => (string) $byEmail['id'],
                    'provider' => 'google',
                    'provider_account_id' => $profile['provider_account_id'],
                ]);

                UserRepository::update((string) $byEmail['id'], [
                    'image_url' => $byEmail['image_url'] ?? $profile['image_url'],
                    'email_verified_at' => $byEmail['email_verified_at'] ?? gmdate('Y-m-d H:i:s'),
                ]);

                Audit::record([
                    'actor_user_id' => (string) $byEmail['id'],
                    'action' => 'auth.google_linked',
                    'target_type' => 'user',
                    'target_id' => (string) $byEmail['id'],
                ]);
            });

            return UserRepository::findById((string) $byEmail['id']) ?? $byEmail;
        }

        $created = Db::transaction(static function () use ($profile): array {
            $user = UserRepository::create([
                'email' => $profile['email'],
                'password_hash' => null,
                'name' => $profile['name'] !== '' ? $profile['name'] : 'Google user',
                'image_url' => $profile['image_url'],
                'locale' => 'en',
                'email_verified_at' => gmdate('Y-m-d H:i:s'),
            ]);

            $team = TeamService::createPersonalTeam((string) $user['id'], (string) $user['name'], 'en');
            UserRepository::setDefaultTeam((string) $user['id'], (string) $team['id']);

            Db::insert('accounts', [
                'id' => \App\Uuid::v4(),
                'user_id' => (string) $user['id'],
                'provider' => 'google',
                'provider_account_id' => $profile['provider_account_id'],
            ]);

            Audit::record([
                'team_id' => (string) $team['id'],
                'actor_user_id' => (string) $user['id'],
                'action' => 'auth.signup_google',
                'target_type' => 'user',
                'target_id' => (string) $user['id'],
            ]);

            return $user;
        });

        return $created;
    }
}
