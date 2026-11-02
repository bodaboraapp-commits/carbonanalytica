<?php

declare(strict_types=1);

namespace App;

/**
 * Email delivery.
 *
 * Transports:
 *  - `resend` sends through the Resend HTTP API (production and parity-testing);
 *  - `log` writes to `email_outbox` and storage/logs/mail.log (local development);
 *  - `silent` only stores the message in `email_outbox` (tests).
 *
 * Every message is recorded in `email_outbox` with its delivery error, so a
 * broken provider never rolls back a successful sign-up and support can see
 * exactly what was sent. In development the body is also copied to mail.log
 * even when a real provider is configured, so one-time codes stay reachable
 * from the machine that runs the app.
 */
final class Mailer
{
    /**
     * @return array{ok: bool, error: string|null}
     */
    public static function send(string $to, string $subject, string $body, string $kind): array
    {
        $transport = (string) Config::get('mail.transport');
        $sentAt = gmdate('Y-m-d H:i:s');
        $error = null;
        $providerId = null;
        $delivered = false;
        $note = null;

        if ($transport === 'log') {
            self::appendToMailLog($to, $subject, $body, $kind);
            $delivered = true;
        } elseif ($transport === 'resend') {
            if (self::isTestRecipient($to)) {
                // Demo and end-to-end fixtures use reserved domains. Never call
                // the provider for them; the body is in mail.log in development.
                $error = 'Not sent: test recipient domain.';
            } else {
                try {
                    $result = ResendTransport::send($to, $subject, $body);
                } catch (\Throwable $failure) {
                    $result = ['ok' => false, 'id' => null, 'error' => $failure->getMessage()];
                }

                if ($result['ok']) {
                    $delivered = true;
                    $providerId = $result['id'];
                } else {
                    $error = (string) ($result['error'] ?? 'Resend delivery failed.');
                }
            }
            $note = $error;
            self::mirrorDevelopmentCopy($to, $subject, $body, $kind, $note);
        } elseif ($transport !== 'silent') {
            // Unknown transports fail visibly in the outbox rather than throwing:
            // a broken mailer must not roll back a successful sign-up.
            $error = sprintf("EMAIL_TRANSPORT '%s' is not implemented; message kept in email_outbox.", $transport);
            self::mirrorDevelopmentCopy($to, $subject, $body, $kind, $error);
        }

        Db::insert('email_outbox', [
            'id' => Uuid::v4(),
            'to_email' => $to,
            'subject' => $subject,
            'body_text' => $body,
            'body_html' => null,
            'kind' => $kind,
            'sent_at' => $delivered ? $sentAt : null,
            'error' => $error,
            'provider_message_id' => $providerId,
        ]);

        return ['ok' => $delivered, 'error' => $error];
    }

    /** True when Resend is configured and the API key is present. */
    public static function usingResend(): bool
    {
        return (string) Config::get('mail.transport') === 'resend' && ResendTransport::configured();
    }

    private static function isTestRecipient(string $to): bool
    {
        $domain = mb_strtolower((string) substr((string) strrchr($to, '@'), 1));
        if ($domain === '') {
            return false;
        }

        /** @var list<string> $testDomains */
        $testDomains = Config::get('mail.test_recipient_domains', []);
        return in_array($domain, $testDomains, true);
    }

    /** Keep one-time codes reachable locally when a real provider is configured. */
    private static function mirrorDevelopmentCopy(string $to, string $subject, string $body, string $kind, ?string $note): void
    {
        if ((string) Config::get('app.env') === 'production') {
            return;
        }
        if (!(bool) Config::get('mail.development_mail_log', true)) {
            return;
        }
        self::appendToMailLog($to, $subject, $body, $kind, $note);
    }

    private static function appendToMailLog(string $to, string $subject, string $body, string $kind, ?string $note = null): void
    {
        $directory = APP_ROOT . '/' . Config::get('storage.logs');
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $entry = sprintf(
            "[%s] to=%s kind=%s subject=%s%s\n%s\n%s\n",
            gmdate('Y-m-d H:i:s'),
            $to,
            $kind,
            $subject,
            $note !== null ? ' note=' . $note : '',
            $body,
            str_repeat('-', 72),
        );

        @file_put_contents($directory . '/mail.log', $entry, FILE_APPEND | LOCK_EX);
    }
}
