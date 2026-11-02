<?php

declare(strict_types=1);

namespace App;

/**
 * Email delivery.
 *
 * The MVP ships a `log` transport: every message is stored in `email_outbox`
 * and appended to storage/logs/mail.log, which is enough to open verification,
 * reset and invitation links locally. A production deployment swaps the
 * transport here (SMTP/SES/Postmark); nothing else changes.
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $body, string $kind): void
    {
        $transport = (string) Config::get('mail.transport');
        $sentAt = gmdate('Y-m-d H:i:s');
        $error = null;

        if ($transport === 'log') {
            $logPath = APP_ROOT . '/' . Config::get('storage.logs') . '/mail.log';
            $entry = sprintf(
                "[%s] to=%s kind=%s subject=%s\n%s\n%s\n",
                $sentAt,
                $to,
                $kind,
                $subject,
                $body,
                str_repeat('-', 72),
            );
            file_put_contents($logPath, $entry, FILE_APPEND | LOCK_EX);
        } elseif ($transport !== 'silent') {
            // Unknown transports fail visibly in the outbox rather than throwing:
            // a broken mailer must not roll back a successful sign-up.
            $error = sprintf("EMAIL_TRANSPORT '%s' is not implemented; message kept in email_outbox.", $transport);
        }

        Db::insert('email_outbox', [
            'id' => Uuid::v4(),
            'to_email' => $to,
            'subject' => $subject,
            'body_text' => $body,
            'body_html' => null,
            'kind' => $kind,
            'sent_at' => $error === null ? $sentAt : null,
            'error' => $error,
        ]);
    }
}
