<?php

declare(strict_types=1);

namespace App;

/**
 * Resend (https://resend.com) email delivery over its HTTP API.
 *
 * Deliberately dependency-free: cURL when the extension is present, PHP's
 * stream wrapper otherwise. Failures are returned as data, never thrown, so a
 * broken mail provider cannot roll back a successful sign-up; the caller
 * records the error in `email_outbox`.
 */
final class ResendTransport
{
    public static function configured(): bool
    {
        return trim((string) Config::get('resend.api_key')) !== '';
    }

    /**
     * @return array{ok: bool, id: string|null, error: string|null}
     */
    public static function send(string $to, string $subject, string $text): array
    {
        $apiKey = trim((string) Config::get('resend.api_key'));
        if ($apiKey === '') {
            return ['ok' => false, 'id' => null, 'error' => 'RESEND_API_KEY is not configured.'];
        }

        $payload = json_encode([
            'from' => (string) Config::get('resend.from'),
            'to' => [$to],
            'subject' => $subject,
            'text' => $text,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($payload)) {
            return ['ok' => false, 'id' => null, 'error' => 'Could not encode the Resend payload.'];
        }

        $endpoint = (string) Config::get('resend.endpoint');
        $timeout = (int) Config::get('resend.timeout');

        return function_exists('curl_init')
            ? self::sendWithCurl($endpoint, $apiKey, $payload, $timeout)
            : self::sendWithStream($endpoint, $apiKey, $payload, $timeout);
    }

    /** @return array{ok: bool, id: string|null, error: string|null} */
    private static function sendWithCurl(string $endpoint, string $apiKey, string $payload, int $timeout): array
    {
        $handle = curl_init($endpoint);
        if ($handle === false) {
            return ['ok' => false, 'id' => null, 'error' => 'Could not create the Resend HTTP client.'];
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, max(1, $timeout)),
            CURLOPT_TIMEOUT => max(1, $timeout),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            return ['ok' => false, 'id' => null, 'error' => 'Resend request failed: ' . $curlError];
        }

        return self::interpretResponse($status, (string) $body);
    }

    /** @return array{ok: bool, id: string|null, error: string|null} */
    private static function sendWithStream(string $endpoint, string $apiKey, string $payload, int $timeout): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                    'Accept: application/json',
                ]) . "\r\n",
                'content' => $payload,
                'timeout' => max(1, $timeout),
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($endpoint, false, $context);
        $status = 0;
        $headers = $http_response_header ?? [];
        if (is_array($headers) && isset($headers[0]) && preg_match('#\s(\d{3})\s#', (string) $headers[0], $matches)) {
            $status = (int) $matches[1];
        }

        if ($body === false) {
            return ['ok' => false, 'id' => null, 'error' => 'Resend request failed (no response).'];
        }

        return self::interpretResponse($status, $body);
    }

    /** @return array{ok: bool, id: string|null, error: string|null} */
    private static function interpretResponse(int $status, string $body): array
    {
        $decoded = json_decode($body, true);
        $id = is_array($decoded) && isset($decoded['id']) && is_string($decoded['id']) ? $decoded['id'] : null;

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'id' => $id, 'error' => null];
        }

        $detail = is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])
            ? $decoded['message']
            : mb_substr(trim($body), 0, 300);

        return ['ok' => false, 'id' => $id, 'error' => sprintf('Resend responded HTTP %d: %s', $status, $detail)];
    }
}
