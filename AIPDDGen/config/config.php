<?php

declare(strict_types=1);

/**
 * Application configuration.
 *
 * Every value can be overridden with an environment variable or a .env.local
 * file, so the same code runs on a developer laptop and in production without
 * editing files.
 */
return [
    'app' => [
        'name' => 'AI PDD Generator',
        // Public base URL, used in emails and absolute redirects.
        'url' => rtrim((string) (getenv('APP_URL') ?: 'http://localhost:8000'), '/'),
        'env' => getenv('APP_ENV') ?: 'development',
        'default_locale' => 'en',
        'locales' => ['en', 'fr'],
        'debug' => (getenv('APP_DEBUG') ?: '1') === '1',
    ],

    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        // XAMPP's MariaDB is configured on 3307 in this installation.
        'port' => (int) (getenv('DB_PORT') ?: 3307),
        'name' => getenv('DB_NAME') ?: 'pdd_generator',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],

    'auth' => [
        // Session lifetime in seconds (sliding, refreshed on activity).
        'session_ttl' => 60 * 60 * 24 * 30,
        'email_verification_ttl' => 60 * 60 * 48,
        'password_reset_ttl' => 60 * 60,
        'invitation_ttl' => 60 * 60 * 24 * 7,
        'login_ip_limit' => ['max' => 30, 'window' => 900],
        'login_email_limit' => ['max' => 10, 'window' => 900],
        'reset_ip_limit' => ['max' => 20, 'window' => 3600],
        'reset_email_limit' => ['max' => 5, 'window' => 3600],
        'resend_verification_limit' => ['max' => 5, 'window' => 3600],
        // Email one-time codes: sign-up verification and the sign-in second factor.
        'otp_ttl' => 10 * 60,
        'otp_pending_ttl' => 15 * 60,
        'otp_max_attempts' => 5,
        'otp_verify_limit' => ['max' => 15, 'window' => 600],
        'otp_resend_limit' => ['max' => 5, 'window' => 600],
    ],

    'mail' => [
        // `log` writes to the email_outbox table and storage/logs/mail.log.
        'transport' => getenv('EMAIL_TRANSPORT') ?: 'log',
        'from' => getenv('EMAIL_FROM') ?: 'AI PDD Generator <no-reply@pddgen.local>',
        // Fixture domains that must never reach a real provider (demo account,
        // end-to-end tests). Their bodies still land in mail.log in development.
        'test_recipient_domains' => ['pddgen.local', 'example.com', 'example.org'],
        // With a real provider configured, mirror message bodies to mail.log
        // outside production so one-time codes stay reachable on the dev machine.
        'development_mail_log' => true,
    ],

    'resend' => [
        'api_key' => getenv('RESEND_API_KEY') ?: '',
        'from' => getenv('RESEND_FROM') ?: (getenv('EMAIL_FROM') ?: 'AI PDD Generator <noreply@carbonanalytica.com>'),
        'endpoint' => getenv('RESEND_ENDPOINT') ?: 'https://api.resend.com/emails',
        'timeout' => (int) (getenv('RESEND_TIMEOUT') ?: 10),
    ],

    'google' => [
        'client_id' => getenv('GOOGLE_CLIENT_ID') ?: '',
        'client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: '',
    ],

    'security' => [
        // Session cookies are HTTPS-only when the app URL is https.
        'secure_cookies' => str_starts_with((string) (getenv('APP_URL') ?: 'http://localhost:8000'), 'https://'),
        'session_cookie' => 'pdd_session',
        'locale_cookie' => 'pdd_locale',
    ],

    'storage' => [
        'sessions' => 'storage/sessions',
        'logs' => 'storage/logs',
        'uploads' => 'storage/uploads',
    ],
];
