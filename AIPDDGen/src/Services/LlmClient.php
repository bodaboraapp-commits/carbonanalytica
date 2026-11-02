<?php

declare(strict_types=1);

namespace App\Services;

use App\AppError;

/**
 * OpenAI-compatible chat-completions client.
 *
 * Every provider the product targets (OpenAI, DeepSeek, OpenRouter, Ollama, ...)
 * speaks the same `POST {base_url}/chat/completions` protocol with the same
 * request and response shape, so one small client covers them all. Staff only
 * configure the base URL, model and key in the console; there is no
 * provider-specific code path.
 *
 * The HTTP transport is injectable so tests never touch the network. A
 * transport is a callable(array $request): array that returns
 * `['status' => int, 'body' => string]` and throws on a network failure. The
 * client retries once on a 5xx response or a transport failure, as agreed for
 * the MVP.
 *
 * One compatibility shim: some current models reject the optional `temperature`
 * parameter (Anthropic's newest Claude models answer 400 "temperature is
 * deprecated"). The client retries once without temperature and then keeps
 * omitting it for the rest of the client instance, so one generic configuration
 * works across models without provider-specific code paths.
 *
 * Secrets: the API key only ever appears in the outgoing Authorization header.
 * It is never put in a URL, an exception message, a log line or an error the
 * user can see. Provider response bodies are not re-shown to users either.
 */
final class LlmClient
{
    /** Default request timeout for one chat-completions call (seconds). */
    public const DEFAULT_TIMEOUT = 60;

    /**
     * @var callable(array{url:string,headers:list<string>,body:string,timeout:int}): array{status:int,body:string}
     */
    private $transport;

    private int $timeoutSeconds;

    /** Set once a provider has rejected `temperature`; later calls omit it. */
    private bool $omitTemperature = false;

    /**
     * @param (callable(array{url:string,headers:list<string>,body:string,timeout:int}): array{status:int,body:string})|null $transport
     */
    public function __construct(?callable $transport = null, int $timeoutSeconds = self::DEFAULT_TIMEOUT)
    {
        $this->transport = $transport ?? self::curlTransport(...);
        $this->timeoutSeconds = max(5, min($timeoutSeconds, 300));
    }

    /**
     * A client that uses the deployment timeout from config.
     *
     * @param array<string, mixed> $settings
     */
    public static function forSettings(array $settings, ?callable $transport = null): self
    {
        $timeout = (int) ($settings['timeout'] ?? self::DEFAULT_TIMEOUT);

        return new self($transport, $timeout);
    }

    /**
     * Send one chat completion and return the assistant's text.
     *
     * @param array{base_url?: string, api_key?: string, model?: string, temperature?: float|int|string, max_tokens?: int} $settings
     * @param list<array{role: string, content: string}> $messages
     * @return array{content: string, model: string}
     *
     * @throws AppError with a safe, translatable error code
     */
    public function chat(array $settings, array $messages, ?int $maxTokens = null): array
    {
        $baseUrl = rtrim(trim((string) ($settings['base_url'] ?? '')), '/');
        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        $model = trim((string) ($settings['model'] ?? ''));

        if (!preg_match('#^https?://[^\s]+$#i', $baseUrl)) {
            throw new AppError('ai_engine_base_url_invalid', 503, 'The AI engine base URL is not configured correctly.');
        }
        if ($apiKey === '') {
            throw new AppError('ai_engine_key_missing', 503, 'The AI engine API key is not configured.');
        }
        if ($model === '') {
            throw new AppError('ai_engine_model_missing', 503, 'The AI engine model is not configured.');
        }
        if ($messages === []) {
            throw new AppError('ai_engine_llm_response', 502, 'The AI engine request was empty.');
        }

        $payload = [
            'model' => $model,
            'messages' => array_values($messages),
            'max_tokens' => $maxTokens ?? (int) ($settings['max_tokens'] ?? 1200),
            'stream' => false,
        ];
        if (!$this->omitTemperature) {
            $payload['temperature'] = (float) ($settings['temperature'] ?? 0.2);
        }

        $response = $this->send($this->buildRequest($payload, $baseUrl, $apiKey));

        // Compatibility: some current models (e.g. Anthropic's newest Claude
        // models) reject the optional temperature parameter. Retry once without
        // it, then keep omitting it for the rest of this client instance.
        if (self::temperatureRejected($response)) {
            $this->omitTemperature = true;
            unset($payload['temperature']);
            $response = $this->send($this->buildRequest($payload, $baseUrl, $apiKey));
        }

        $status = (int) ($response['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            throw self::httpError($status === 0 ? 'network' : $status);
        }

        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        if (!is_array($decoded)) {
            throw new AppError('ai_engine_llm_response', 502, 'The AI provider returned an unexpected response.');
        }

        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if (is_array($content)) {
            // Some providers return content as a list of text parts.
            $parts = [];
            foreach ($content as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $parts[] = $part['text'];
                }
            }
            $content = implode('', $parts);
        }
        if (!is_string($content) || trim($content) === '') {
            throw new AppError('ai_engine_llm_response', 502, 'The AI provider returned an empty response.');
        }

        return [
            'content' => $content,
            'model' => is_string($decoded['model'] ?? null) ? (string) $decoded['model'] : $model,
        ];
    }

    /**
     * Build the outgoing request. The API key only travels in the header.
     *
     * @param array<string, mixed> $payload
     * @return array{url:string,headers:list<string>,body:string,timeout:int}
     */
    private function buildRequest(array $payload, string $baseUrl, string $apiKey): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new AppError('ai_engine_llm_response', 502, 'The AI engine request could not be encoded.');
        }

        return [
            'url' => $baseUrl . '/chat/completions',
            'headers' => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            'body' => $body,
            'timeout' => $this->timeoutSeconds,
        ];
    }

    /** Did the provider reject the optional temperature parameter? */
    private static function temperatureRejected(array $response): bool
    {
        $status = (int) ($response['status'] ?? 0);
        if ($status < 400 || $status >= 500) {
            return false;
        }
        $body = mb_strtolower((string) ($response['body'] ?? ''));
        if (!str_contains($body, 'temperature')) {
            return false;
        }

        return str_contains($body, 'deprecated')
            || str_contains($body, 'not supported')
            || str_contains($body, 'unsupported')
            || str_contains($body, 'does not support');
    }

    /**
     * Perform the request. Returns the provider response for any HTTP status
     * (chat() maps it to a safe error); retries once on a 5xx response and on a
     * transport failure.
     *
     * @param array{url:string,headers:list<string>,body:string,timeout:int} $request
     * @return array{status:int,body:string}
     */
    private function send(array $request): array
    {
        $lastResponse = null;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = ($this->transport)($request);
            } catch (\Throwable) {
                // A transport failure (DNS, connection, timeout). The exception
                // message can contain provider/URL detail, so it is not logged or
                // surfaced: the error code below is all the caller sees.
                if ($attempt === 1) {
                    usleep(200_000);
                    continue;
                }
                throw self::httpError('network');
            }

            $status = (int) ($response['status'] ?? 0);
            $result = ['status' => $status, 'body' => (string) ($response['body'] ?? '')];
            if ($status >= 200 && $status < 300) {
                return $result;
            }
            if ($status < 500) {
                // Client-side failures are not retried here (chat() may apply the
                // temperature compatibility fallback).
                return $result;
            }
            $lastResponse = $result;
            if ($attempt === 1) {
                usleep(200_000);
            }
        }

        return $lastResponse ?? ['status' => 0, 'body' => ''];
    }

    /** Map the last failure to a safe, translatable AppError. */
    private static function httpError(mixed $lastFailure): AppError
    {
        if ($lastFailure === 'network' || $lastFailure === null) {
            return new AppError('ai_engine_llm_network', 502, 'The AI provider could not be reached.');
        }

        $status = (int) $lastFailure;
        if ($status === 401 || $status === 403) {
            return new AppError('ai_engine_llm_auth', 502, 'The AI provider rejected the API key.');
        }
        if ($status === 429) {
            return new AppError('ai_engine_llm_rate_limited', 429, 'The AI provider is rate limiting requests.');
        }
        if ($status === 408 || $status === 504) {
            return new AppError('ai_engine_llm_timeout', 504, 'The AI provider did not respond in time.');
        }

        return new AppError('ai_engine_llm_http', 502, 'The AI provider rejected the request.');
    }

    /**
     * Default cURL transport. Returns the raw HTTP status and body; never logs
     * headers (the API key travels in one) and never echoes the body.
     *
     * @param array{url:string,headers:list<string>,body:string,timeout:int} $request
     * @return array{status:int,body:string}
     */
    private static function curlTransport(array $request): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('curl_unavailable');
        }

        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL => $request['url'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $request['body'],
            CURLOPT_HTTPHEADER => $request['headers'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => max(5, (int) $request['timeout']),
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if (!is_string($body)) {
            // Deliberately no curl_error(): it can carry host detail into a log.
            throw new \RuntimeException('transport_failure');
        }

        return ['status' => $status, 'body' => $body];
    }
}
