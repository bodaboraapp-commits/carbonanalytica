<?php

declare(strict_types=1);

namespace App\Services;

use App\AppError;

/**
 * OpenAI-compatible chat-completions client.
 *
 * Every provider the product targets (OpenAI, Claude via Anthropic's
 * OpenAI-compatible layer, DeepSeek, OpenRouter, Ollama, ...) speaks the same
 * `POST {base_url}/chat/completions` protocol, so one small client covers them
 * all. Staff configure the base URL, model and key in the console; there is no
 * provider-specific code path beyond a few documented compatibility shims:
 *
 *  - base_url is normalised (spaces, trailing '/', an already-appended
 *    '/chat/completions' segment) so a pasted full endpoint still works;
 *  - Anthropic hosts/`provider=claude` also get `x-api-key` +
 *    `anthropic-version` headers for the compatibility layer;
 *  - some current models reject `temperature` (Claude 5.x) — one retry without
 *    it, remembered for the rest of this client instance;
 *  - o-series reasoning models (o1/o3/o4...) use `max_completion_tokens`; a
 *    provider that rejects `max_tokens` triggers one retry with that field;
 *  - non-2xx provider bodies are parsed for a SAFE error detail
 *    (error.message/type/param/code, truncated, secrets stripped) so the admin
 *    sees "model not found" / "invalid x-api-key" instead of a generic line.
 *
 * The HTTP transport is injectable so tests never touch the network. A
 * transport is a callable(array $request): array that returns
 * `['status' => int, 'body' => string]` and throws on a network failure. The
 * client retries once on a 5xx response or a transport failure.
 *
 * Secrets: the API key only ever appears in outgoing headers. It is never put
 * in a URL, an exception message, a log line or a user-visible error. Provider
 * response bodies are only surfaced through safeProviderDetail(), truncated and
 * secret-stripped.
 */
final class LlmClient
{
    /** Default request timeout for one chat-completions call (seconds). */
    public const DEFAULT_TIMEOUT = 60;

    /** Anthropic compatibility-layer API version header value. */
    public const ANTHROPIC_VERSION = '2023-06-01';

    /** Maximum length of a sanitised provider error detail shown to people. */
    public const MAX_DETAIL_LENGTH = 200;

    /**
     * @var callable(array{url:string,headers:list<string>,body:string,timeout:int}): array{status:int,body:string}
     */
    private $transport;

    private int $timeoutSeconds;

    /** Set once a provider has rejected `temperature`; later calls omit it. */
    private bool $omitTemperature = false;

    /** Set once a provider has rejected `max_tokens`; later calls use the new field. */
    private bool $useMaxCompletionTokens = false;

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
     * Normalise an admin-entered API root: trim, drop trailing slashes and an
     * already-appended `/chat/completions` segment. Throws a clear error for an
     * unusable URL instead of sending a malformed request.
     */
    public static function normalizeBaseUrl(string $raw): string
    {
        $url = trim($raw);
        while (str_ends_with($url, '/')) {
            $url = substr($url, 0, -1);
        }
        if (preg_match('#/chat/completions$#i', $url)) {
            $url = (string) preg_replace('#/chat/completions$#i', '', $url);
            $url = rtrim($url, '/');
        }
        if ($url === '' || !preg_match('#^https?://[^\s]+$#i', $url)) {
            throw new AppError('ai_engine_base_url', 503, 'The AI engine base URL is not a valid http(s) URL.');
        }

        return $url;
    }

    /**
     * Send one chat completion and return the assistant's text.
     *
     * @param array{provider?: string, base_url?: string, api_key?: string, model?: string, temperature?: float|int|string, max_tokens?: int} $settings
     * @param list<array{role: string, content: string}> $messages
     * @return array{content: string, model: string}
     *
     * @throws AppError with a safe, translatable error code
     */
    public function chat(array $settings, array $messages, ?int $maxTokens = null): array
    {
        $baseUrl = self::normalizeBaseUrl((string) ($settings['base_url'] ?? ''));
        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        $model = trim((string) ($settings['model'] ?? ''));
        $provider = trim((string) ($settings['provider'] ?? ''));

        if ($apiKey === '') {
            throw new AppError('ai_engine_key_missing', 503, 'The AI engine API key is not configured.');
        }
        if ($model === '') {
            throw new AppError('ai_engine_model_missing', 503, 'The AI engine model is not configured.');
        }
        if ($messages === []) {
            throw new AppError('ai_engine_llm_response', 502, 'The AI engine request was empty.');
        }

        $tokenLimit = $maxTokens ?? (int) ($settings['max_tokens'] ?? 1200);
        $payload = [
            'model' => $model,
            'messages' => array_values($messages),
            'stream' => false,
        ];
        if ($this->useMaxCompletionTokens || preg_match('/^o\d/i', $model) === 1) {
            $payload['max_completion_tokens'] = $tokenLimit;
        } else {
            $payload['max_tokens'] = $tokenLimit;
        }
        if (!$this->omitTemperature) {
            $payload['temperature'] = (float) ($settings['temperature'] ?? 0.2);
        }

        $response = $this->send($this->buildRequest($payload, $baseUrl, $apiKey, $provider));

        // Compatibility: some current models reject temperature (Claude 5.x).
        if (self::temperatureRejected($response)) {
            $this->omitTemperature = true;
            unset($payload['temperature']);
            $response = $this->send($this->buildRequest($payload, $baseUrl, $apiKey, $provider));
        }

        // Compatibility: providers that retired `max_tokens` want the newer name.
        if (self::maxTokensRejected($response) && array_key_exists('max_tokens', $payload)) {
            $this->useMaxCompletionTokens = true;
            unset($payload['max_tokens']);
            $payload['max_completion_tokens'] = $tokenLimit;
            $response = $this->send($this->buildRequest($payload, $baseUrl, $apiKey, $provider));
        }

        $status = (int) ($response['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            throw self::providerError($status, $response, $apiKey);
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
     * Build the outgoing request. The API key only travels in headers.
     *
     * @param array<string, mixed> $payload
     * @return array{url:string,headers:list<string>,body:string,timeout:int}
     */
    private function buildRequest(array $payload, string $baseUrl, string $apiKey, string $provider): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new AppError('ai_engine_llm_response', 502, 'The AI engine request could not be encoded.');
        }

        $host = strtolower((string) (parse_url($baseUrl, PHP_URL_HOST) ?? ''));
        $isAnthropic = $provider === 'claude'
            || $host === 'api.anthropic.com'
            || str_ends_with($host, '.anthropic.com');

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if ($isAnthropic) {
            // Anthropic's OpenAI-compatibility layer accepts the native headers
            // as well; sending both keeps it and gateways happy.
            $headers[] = 'x-api-key: ' . $apiKey;
            $headers[] = 'anthropic-version: ' . self::ANTHROPIC_VERSION;
        }
        $headers[] = 'Authorization: Bearer ' . $apiKey;

        return [
            'url' => $baseUrl . '/chat/completions',
            'headers' => $headers,
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

    /** Did the provider reject `max_tokens` in favour of `max_completion_tokens`? */
    private static function maxTokensRejected(array $response): bool
    {
        $status = (int) ($response['status'] ?? 0);
        if ($status !== 400 && $status !== 422) {
            return false;
        }
        $body = mb_strtolower((string) ($response['body'] ?? ''));
        if (!str_contains($body, 'max_tokens') && !str_contains($body, 'max completion tokens')) {
            return false;
        }

        return str_contains($body, 'max_completion_tokens')
            || str_contains($body, 'not supported')
            || str_contains($body, 'unsupported')
            || str_contains($body, 'deprecated');
    }

    /**
     * Map a non-2xx response to a safe, translatable AppError with the provider's
     * sanitised explanation in fields['detail'].
     */
    private static function providerError(int $status, array $response, string $apiKey): AppError
    {
        if ($status === 0) {
            return new AppError('ai_engine_llm_network', 502, 'The AI provider could not be reached.');
        }

        $detail = self::safeProviderDetail($response, $apiKey);
        $fields = $detail !== '' ? ['detail' => $detail] : [];

        if ($status === 401 || $status === 403) {
            return new AppError('ai_engine_llm_auth', 502, 'The AI provider rejected the API key.', $fields);
        }
        if ($status === 429) {
            return new AppError('ai_engine_llm_rate_limited', 429, 'The AI provider is rate limiting requests.', $fields);
        }
        if ($status === 408 || $status === 504) {
            return new AppError('ai_engine_llm_timeout', 504, 'The AI provider did not respond in time.', $fields);
        }
        if ($status === 404) {
            return new AppError('ai_engine_llm_model', 502, 'The AI provider does not know this model or base URL.', $fields);
        }
        if ($status === 400 && $detail !== '' && preg_match('/\bmodel\b/i', $detail) === 1) {
            return new AppError('ai_engine_llm_model', 502, 'The AI provider does not accept this model.', $fields);
        }

        return new AppError('ai_engine_llm_request', 502, 'The AI provider rejected the request.', $fields);
    }

    /**
     * Extract a short, safe explanation from a non-2xx body: JSON error
     * message/type/param/code, whitespace-collapsed, secrets stripped, 200 chars.
     */
    private static function safeProviderDetail(array $response, string $apiKey): string
    {
        $body = trim((string) ($response['body'] ?? ''));
        if ($body === '') {
            return '';
        }

        $parts = [];
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : $decoded;
            foreach (['message', 'type', 'param', 'code'] as $key) {
                $value = $error[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $parts[] = trim($value);
                }
            }
        }
        if ($parts === []) {
            // Non-JSON error body (proxy, HTML page, plain text): keep a snippet.
            $parts[] = $body;
        }

        $detail = implode(' — ', array_values(array_unique($parts)));
        $detail = (string) preg_replace('/\s+/', ' ', $detail);
        $detail = self::stripSecrets($detail, $apiKey);

        return mb_substr(trim($detail), 0, self::MAX_DETAIL_LENGTH);
    }

    /** Remove the API key and any token-looking run from provider text. */
    private static function stripSecrets(string $text, string $apiKey): string
    {
        if ($apiKey !== '') {
            $text = str_replace($apiKey, '[redacted]', $text);
        }
        $patterns = [
            '/Bearer\s+[A-Za-z0-9._\-]+/i',
            '/\bsk-[A-Za-z0-9_\-]{6,}/',
            '/\b[A-Za-z0-9_\-]{40,}\b/',
        ];
        foreach ($patterns as $pattern) {
            $text = (string) preg_replace($pattern, '[redacted]', $text);
        }

        return $text;
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
                throw new AppError('ai_engine_llm_network', 502, 'The AI provider could not be reached.');
            }

            $status = (int) ($response['status'] ?? 0);
            $result = ['status' => $status, 'body' => (string) ($response['body'] ?? '')];
            if ($status >= 200 && $status < 300) {
                return $result;
            }
            if ($status < 500) {
                // Client-side failures are not retried here (chat() may apply the
                // temperature / max_tokens compatibility fallbacks).
                return $result;
            }
            $lastResponse = $result;
            if ($attempt === 1) {
                usleep(200_000);
            }
        }

        return $lastResponse ?? ['status' => 0, 'body' => ''];
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
