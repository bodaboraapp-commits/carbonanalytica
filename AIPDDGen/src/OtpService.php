<?php

declare(strict_types=1);

namespace App;

/**
 * Email one-time codes used as the sign-up email proof and as the sign-in
 * second factor.
 *
 * Rules:
 *  - the plaintext code only ever exists in the email body; the database keeps
 *    a SHA-256 hash;
 *  - one active code per user and purpose — issuing a new one consumes the old;
 *  - a code is single use, expires after `auth.otp_ttl`, and dies after
 *    `auth.otp_max_attempts` wrong guesses;
 *  - delivery problems are recorded in `email_outbox` and reported to the
 *    caller, never thrown, so a mail outage cannot block sign-up.
 */
final class OtpService
{
    public const PURPOSES = ['signup', 'signin'];

    /**
     * Issue (and email) a fresh code, invalidating any earlier active one.
     *
     * @param array<string, mixed> $user
     * @return array{purpose: string, email: string, expires_at: string, delivered: bool, delivery_error: string|null}
     */
    public static function issue(array $user, string $purpose): array
    {
        self::assertPurpose($purpose);

        $userId = (string) $user['id'];
        $email = (string) $user['email'];
        $code = self::generateCode();
        $ttl = (int) Config::get('auth.otp_ttl');
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttl);

        Db::transaction(static function () use ($userId, $email, $purpose, $code, $expiresAt): void {
            Db::execute(
                'UPDATE otp_codes SET consumed_at = NOW(3)
                 WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL',
                [$userId, $purpose],
            );

            Db::insert('otp_codes', [
                'id' => Uuid::v4(),
                'user_id' => $userId,
                'email' => $email,
                'purpose' => $purpose,
                'code_hash' => Tokens::hash($code),
                'attempts' => 0,
                'expires_at' => $expiresAt,
                'requested_ip' => Http::ip(),
            ]);
        });

        $locale = (string) ($user['locale'] ?? 'en');
        $delivery = Mailer::send(
            $email,
            I18n::forLocale($locale, 'email.otp_subject'),
            I18n::forLocale($locale, 'email.otp_body', [
                'name' => (string) ($user['name'] ?? ''),
                'code' => $code,
                'minutes' => max(1, (int) round($ttl / 60)),
            ]),
            'otp',
        );

        return [
            'purpose' => $purpose,
            'email' => $email,
            'expires_at' => $expiresAt,
            'delivered' => (bool) $delivery['ok'],
            'delivery_error' => $delivery['error'],
        ];
    }

    /**
     * Check a code and consume every active code for that purpose on success.
     *
     * @return array{purpose: string, user_id: string}
     */
    public static function verify(string $userId, string $purpose, string $code): array
    {
        self::assertPurpose($purpose);

        $row = Db::selectOne(
            'SELECT * FROM otp_codes
             WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL
             ORDER BY created_at DESC LIMIT 1',
            [$userId, $purpose],
        );

        if ($row === null) {
            throw AppError::invalidOtp();
        }

        if (strtotime((string) $row['expires_at']) <= time()) {
            self::consume((string) $row['id']);
            throw AppError::expiredOtp();
        }

        $maxAttempts = max(1, (int) Config::get('auth.otp_max_attempts'));
        $attempts = (int) $row['attempts'];

        if ($attempts >= $maxAttempts) {
            self::consume((string) $row['id']);
            throw AppError::otpAttemptsExceeded();
        }

        if (!hash_equals((string) $row['code_hash'], Tokens::hash($code))) {
            $attempts++;
            Db::execute('UPDATE otp_codes SET attempts = ? WHERE id = ?', [$attempts, (string) $row['id']]);

            if ($attempts >= $maxAttempts) {
                throw AppError::otpAttemptsExceeded();
            }
            throw AppError::invalidOtp();
        }

        Db::execute(
            'UPDATE otp_codes SET consumed_at = NOW(3)
             WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL',
            [$userId, $purpose],
        );

        return ['purpose' => $purpose, 'user_id' => $userId];
    }

    /** The current active code row, for diagnostics and tests. */
    public static function active(string $userId, string $purpose): ?array
    {
        return Db::selectOne(
            'SELECT * FROM otp_codes
             WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL
             ORDER BY created_at DESC LIMIT 1',
            [$userId, $purpose],
        );
    }

    public static function generateCode(): string
    {
        return str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
    }

    private static function consume(string $id): void
    {
        Db::execute('UPDATE otp_codes SET consumed_at = NOW(3) WHERE id = ?', [$id]);
    }

    private static function assertPurpose(string $purpose): void
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new \InvalidArgumentException('Unknown OTP purpose: ' . $purpose);
        }
    }
}
