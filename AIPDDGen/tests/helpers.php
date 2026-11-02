<?php

declare(strict_types=1);

/**
 * Lightweight test helpers: assertions, database reset and factories.
 *
 * Deliberately dependency-free (no PHPUnit) so the open-source edition runs its
 * tests with nothing but PHP and MySQL.
 */

use App\AppError;
use App\Auth;
use App\Db;
use App\Repositories\TeamRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\TeamService;

/** Empty every table except the migration ledger, then reset in-memory auth. */
function reset_database(): void
{
    $_SESSION = [];
    Auth::refresh();

    $pdo = Db::pdo();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll();
    foreach ($tables as $table) {
        $name = (string) $table['table_name'];
        if ($name === 'schema_migrations') {
            continue;
        }
        $pdo->exec('TRUNCATE TABLE `' . $name . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function assert_true(bool $condition, string $message = 'Expected true'): void
{
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function assert_false(bool $condition, string $message = 'Expected false'): void
{
    assert_true(!$condition, $message);
}

function assert_equals(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected != $actual) {
        throw new RuntimeException(sprintf(
            "Assertion failed: %s\n  expected: %s\n  actual:   %s",
            $message !== '' ? $message : 'values differ',
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "Assertion failed: %s\n  expected: %s\n  actual:   %s",
            $message !== '' ? $message : 'values differ',
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

function assert_not_null(mixed $value, string $message = 'Expected a value'): void
{
    assert_true($value !== null, $message);
}

function assert_null(mixed $value, string $message = 'Expected null'): void
{
    assert_true($value === null, $message);
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException(sprintf(
            'Assertion failed: %s does not contain %s',
            $message !== '' ? $message : 'string',
            var_export($needle, true),
        ));
    }
}

function assert_count(int $expected, array $rows, string $message = ''): void
{
    assert_same($expected, count($rows), $message !== '' ? $message : 'row count differs');
}

/**
 * Assert that a callable throws an AppError with the given error code.
 */
function assert_app_error(string $expectedCode, callable $callback, string $message = ''): void
{
    try {
        $callback();
    } catch (AppError $error) {
        assert_same($expectedCode, $error->errorCode, $message !== '' ? $message : 'unexpected error code');
        return;
    }

    throw new RuntimeException(sprintf(
        'Assertion failed: expected AppError %s but nothing was thrown%s',
        $expectedCode,
        $message !== '' ? ' (' . $message . ')' : '',
    ));
}

function count_rows(string $table, string $where = '1 = 1', array $params = []): int
{
    $row = Db::selectOne("SELECT COUNT(*) AS total FROM `{$table}` WHERE {$where}", $params);
    return (int) ($row['total'] ?? 0);
}

/** @return array<string, mixed>|null */
function latest_email(string $toEmail, ?string $kind = null): ?array
{
    if ($kind !== null) {
        return Db::selectOne(
            'SELECT * FROM email_outbox WHERE to_email = ? AND kind = ? ORDER BY created_at DESC, id DESC LIMIT 1',
            [$toEmail, $kind],
        );
    }
    return Db::selectOne(
        'SELECT * FROM email_outbox WHERE to_email = ? ORDER BY created_at DESC, id DESC LIMIT 1',
        [$toEmail],
    );
}

/** Extract a token from an emailed link such as /reset-password?token=abc. */
function token_from_email(string $body, string $path): ?string
{
    if (preg_match('#' . preg_quote($path, '#') . '\?token=([A-Za-z0-9_\-]+)#', $body, $matches)) {
        return $matches[1];
    }
    return null;
}

/** The newest six-digit sign-in/sign-up code emailed to an address. */
function latest_otp_code(string $email): ?string
{
    $emailRow = latest_email($email, 'otp');
    if ($emailRow === null) {
        return null;
    }
    if (preg_match('/\b(\d{6})\b/', (string) $emailRow['body_text'], $matches)) {
        return $matches[1];
    }
    return null;
}

/** Start the OTP step the way the controller does, then return the emailed code. */
function request_otp(string $email, string $purpose = 'signin'): string
{
    $user = UserRepository::findByEmail($email);
    if ($user === null) {
        throw new RuntimeException('No user for ' . $email);
    }
    AuthService::startOtpChallenge($user, $purpose);
    $code = latest_otp_code($email);
    if ($code === null) {
        throw new RuntimeException('No OTP email for ' . $email);
    }
    return $code;
}

/** Extract the invitation token from an emailed link such as /invite/abc. */
function invitation_token_from_email(string $body): ?string
{
    if (preg_match('#/invite/([A-Za-z0-9_\-]+)#', $body, $matches)) {
        return $matches[1];
    }
    return null;
}

/**
 * Create an account through the real sign-up path.
 *
 * @return array{user: array<string, mixed>, team: array<string, mixed>, password: string}
 */
function make_user(string $email, string $name = 'Test Account', string $password = 'correct horse battery staple'): array
{
    $created = AuthService::signUp($name, $email, $password, 'en');
    $user = UserRepository::findById((string) $created['user']['id']);
    if ($user === null) {
        throw new RuntimeException('Failed to load created user');
    }
    return ['user' => $user, 'team' => $created['team'], 'password' => $password];
}

/**
 * Invite an email into a team and accept it as a new account, exercising the
 * real invitation flow.
 *
 * @return array{user: array<string, mixed>, membership: array<string, mixed>}
 */
function add_team_member(
    string $teamId,
    string $actorUserId,
    string $email,
    string $role,
    string $name = 'Team Member',
    string $password = 'correct horse battery staple',
): array {
    $invite = TeamService::invite($actorUserId, $teamId, $email, $role);
    $emailRow = latest_email($email, 'team_invitation');
    if ($emailRow === null) {
        throw new RuntimeException('Invitation email was not queued');
    }
    $token = invitation_token_from_email((string) $emailRow['body_text']);
    if ($token === null) {
        throw new RuntimeException('Invitation token missing from email');
    }

    $created = make_user($email, $name, $password);
    TeamService::acceptInvitation((string) $created['user']['id'], $email, $token);

    $membership = TeamRepository::findMembership($teamId, (string) $created['user']['id']);
    if ($membership === null) {
        throw new RuntimeException('Membership was not created');
    }

    return ['user' => $created['user'], 'membership' => $membership];
}
