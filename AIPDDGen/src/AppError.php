<?php

declare(strict_types=1);

namespace App;

/**
 * Expected application errors. Controllers turn these into friendly messages
 * (HTML pages or JSON), so services can fail loudly without knowing about HTTP.
 */
final class AppError extends \RuntimeException
{
    /** @param array<string, string> $fields */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string, string> $fields */
    public static function validation(array $fields, string $message = 'Some fields need attention.'): self
    {
        return new self('validation_error', 422, $message, $fields);
    }

    public static function invalidCredentials(): self
    {
        return new self('invalid_credentials', 401, 'Email or password is incorrect.');
    }

    public static function emailTaken(): self
    {
        return new self('email_taken', 409, 'An account already exists for this email address.');
    }

    public static function unauthorized(): self
    {
        return new self('unauthorized', 401, 'Sign in to continue.');
    }

    public static function forbidden(): self
    {
        return new self('forbidden', 403, 'You do not have permission to do that.');
    }

    /** Also used for cross-tenant access so IDs cannot be enumerated. */
    public static function notFound(string $what = 'That item'): self
    {
        return new self('not_found', 404, $what . ' could not be found.');
    }

    public static function conflict(string $message = 'That change conflicts with existing data.'): self
    {
        return new self('conflict', 409, $message);
    }

    public static function rateLimited(int $retryAfterSeconds): self
    {
        return new self('rate_limited', 429, 'Too many attempts. Try again shortly.');
    }

    public static function invalidToken(): self
    {
        return new self('invalid_token', 400, 'This link is invalid or has already been used.');
    }

    public static function tokenExpired(): self
    {
        return new self('token_expired', 400, 'This link has expired. Request a new one.');
    }

    public static function invalidRole(): self
    {
        return new self('invalid_role', 422, 'That role cannot be assigned.');
    }

    public static function lastOwner(): self
    {
        return new self('last_owner', 409, 'A team needs at least one owner.');
    }

    public static function inviteEmailMismatch(): self
    {
        return new self('invite_email_mismatch', 403, 'This invitation belongs to a different email address.');
    }

    public static function notConfigured(string $provider): self
    {
        return new self('not_configured', 503, $provider . ' is not configured on this deployment.');
    }

    public static function internal(): self
    {
        return new self('internal_error', 500, 'Something went wrong.');
    }
}
