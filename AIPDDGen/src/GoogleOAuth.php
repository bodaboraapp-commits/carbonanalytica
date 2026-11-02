<?php

declare(strict_types=1);

namespace App;

/**
 * Google sign-in via OpenID Connect authorization code flow with PKCE.
 *
 * Implemented with PHP's cURL extension instead of an SDK, so the open-source
 * edition needs no Composer packages and administrators can plug in their own
 * Google credentials.
 */
final class GoogleOAuth
{
    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    public static function configured(): bool
    {
        return (string) Config::get('google.client_id') !== '' && (string) Config::get('google.client_secret') !== '';
    }

    public static function redirectUri(): string
    {
        return absolute_url('/auth/google/callback');
    }

    /** @return array{verifier: string, challenge: string} */
    public static function pkce(): array
    {
        $verifier = Tokens::generate(32);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return ['verifier' => $verifier, 'challenge' => $challenge];
    }

    public static function authorizeUrl(string $state, string $codeChallenge): string
    {
        $query = http_build_query([
            'client_id' => (string) Config::get('google.client_id'),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
            'access_type' => 'online',
        ]);

        return self::AUTHORIZE_URL . '?' . $query;
    }

    /** Exchange the authorization code for an access token. */
    public static function exchangeCode(string $code, string $codeVerifier): string
    {
        $response = self::post(self::TOKEN_URL, [
            'client_id' => (string) Config::get('google.client_id'),
            'client_secret' => (string) Config::get('google.client_secret'),
            'redirect_uri' => self::redirectUri(),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $codeVerifier,
        ]);

        if (!isset($response['access_token']) || !is_string($response['access_token'])) {
            throw AppError::notConfigured('Google sign-in');
        }

        return $response['access_token'];
    }

    /**
     * @return array{provider_account_id: string, email: string, email_verified: bool, name: string, image_url: ?string}
     */
    public static function profile(string $accessToken): array
    {
        $ch = curl_init(self::USERINFO_URL);
        if ($ch === false) {
            throw AppError::internal();
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Accept: application/json'],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $payload = is_string($body) ? json_decode($body, true) : null;
        if ($status !== 200 || !is_array($payload) || !isset($payload['sub'], $payload['email'])) {
            throw AppError::notConfigured('Google sign-in');
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            $name = (string) strstr((string) $payload['email'], '@', true);
        }

        return [
            'provider_account_id' => (string) $payload['sub'],
            'email' => mb_strtolower((string) $payload['email']),
            // Trust only an explicit true: linking an unverified address would be
            // an account-takeover vector.
            'email_verified' => ($payload['email_verified'] ?? false) === true,
            'name' => $name,
            'image_url' => isset($payload['picture']) ? (string) $payload['picture'] : null,
        ];
    }

    /** @param array<string, string> $fields */
    private static function post(string $url, array $fields): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw AppError::internal();
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $payload = is_string($body) ? json_decode($body, true) : null;
        if ($status !== 200 || !is_array($payload)) {
            return [];
        }
        return $payload;
    }
}
