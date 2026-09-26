<?php

declare(strict_types=1);

namespace Aicountly\Api\Ai;

use Aicountly\Api\Db;
use Aicountly\Api\Env;

/**
 * One model call for Ask Insights, through whichever provider Console has bound
 * to this product.
 *
 * THE KEY NEVER TOUCHES THIS PRODUCT'S CONFIGURATION. It arrives from Console
 * (ConsoleCredentials), is used for one request, and is never logged, stored or
 * returned. The binding chain is tried in order: a provider that is down or
 * rate-limited falls through to the next binding Console lists; a provider that
 * REFUSED is not retried elsewhere — a refusal is an answer.
 *
 * STRUCTURED OUTPUT, VALIDATED. Every feature asks for JSON against a schema
 * and validates what comes back before any of it reaches a screen, so a
 * malformed answer is reported as "Insights could not answer", never shown
 * half-parsed. A dashboard proposal goes through WidgetSchema on top of that,
 * so what the model produced is checked twice before anybody can apply it.
 *
 * THE MODEL NEVER CALCULATES AN ACCOUNTING TOTAL. It is handed figures that
 * were already fetched and computed with exact decimal arithmetic, and it
 * writes prose about them or chooses between named configurations. It has no
 * database, no query language and no arithmetic role.
 *
 * LOGGED WITHOUT CONTENT. insights_ai_usage records the feature, provider,
 * model, latency, token counts and outcome. Never the prompt, never the answer,
 * never a business figure.
 *
 * Three wire formats, because Console's registry has three providers:
 * Anthropic Messages, OpenAI Chat Completions, Google generateContent.
 */
final class AiClient
{
    private const PROVIDERS = ['anthropic', 'openai', 'google'];

    private const ANTHROPIC_VERSION = '2023-06-01';

    private const DEFAULT_MODELS = [
        'anthropic' => 'claude-opus-5',
        'openai'    => 'gpt-5',
        'google'    => 'gemini-2.5-pro',
    ];

    private const DEFAULT_ROOTS = [
        'anthropic' => 'https://api.anthropic.com/v1',
        'openai'    => 'https://api.openai.com/v1',
        'google'    => 'https://generativelanguage.googleapis.com/v1beta',
    ];

    private const MAX_TOKENS_CEILING = 8000;
    private const MAX_RESPONSE_BYTES = 1048576;

    /** Whether Insights can speak this provider's wire format. */
    public static function speaks(string $provider): bool
    {
        return in_array(strtolower($provider), self::PROVIDERS, true);
    }

    /**
     * Ask the model.
     *
     * @param array{
     *     feature: string, system: string, prompt: string, schema: array<string, mixed>,
     *     max_tokens?: int, effort?: string, sources?: list<string>,
     *     cmp_id?: int, bo_id?: int, actor_uuid?: ?string, account_id?: ?int
     * } $request
     * @return array{ok: bool, code: ?string, message: ?string, data: ?array, run_id: ?int, model: ?string, provider: ?string, latency_ms: ?int}
     */
    public static function structured(array $request): array
    {
        $feature = $request['feature'];
        if (!ConsoleCredentials::isConfigured() && !self::testing()) {
            return self::result(false, 'ai_not_configured', ConsoleCredentials::status()['reason'], null,
                self::log($request, null, 'unavailable', null, [], 'not_configured'));
        }

        $chain = ConsoleCredentials::chain();
        if ($chain === []) {
            return self::result(false, 'ai_unavailable', ConsoleCredentials::status()['reason'], null,
                self::log($request, null, 'unavailable', null, [], 'no_binding'));
        }

        $lastMessage = 'Ask Insights is temporarily unavailable.';
        foreach ($chain as $binding) {
            $started = microtime(true);
            $answer = self::call($binding, $request);
            $latency = (int) round((microtime(true) - $started) * 1000);
            $tokens = $answer['tokens'];

            if ($answer['ok']) {
                $data = json_decode(self::stripFence((string) $answer['text']), true);
                $errors = is_array($data) ? Schema::validate($data, $request['schema']) : ['not JSON'];
                if ($errors !== []) {
                    ConsoleCredentials::reportUsage($binding, 'error', 'invalid_output', $latency, $tokens, $feature,
                        $request['cmp_id'] ?? null, $request['actor_uuid'] ?? null);
                    $runId = self::log($request, $binding, 'error', $latency, $tokens, 'invalid_output');

                    return self::result(false, 'ai_unavailable', 'The model returned an answer Insights could not use. Nothing has been changed. Try again.', null, $runId, $binding, $latency);
                }
                ConsoleCredentials::reportUsage($binding, 'success', null, $latency, $tokens, $feature,
                    $request['cmp_id'] ?? null, $request['actor_uuid'] ?? null);
                $runId = self::log($request, $binding, 'ok', $latency, $tokens, null);

                return self::result(true, null, null, $data, $runId, $binding, $latency);
            }

            if ($answer['refused']) {
                ConsoleCredentials::reportUsage($binding, 'blocked', 'refusal', $latency, $tokens, $feature,
                    $request['cmp_id'] ?? null, $request['actor_uuid'] ?? null);
                $runId = self::log($request, $binding, 'policy_blocked', $latency, $tokens, 'refusal');

                return self::result(false, 'policy_blocked', 'The model declined to answer this request.', null, $runId, $binding, $latency);
            }

            ConsoleCredentials::reportUsage($binding, 'error', $answer['error_code'], $latency, $tokens, $feature,
                $request['cmp_id'] ?? null, $request['actor_uuid'] ?? null);
            self::log($request, $binding, 'error', $latency, $tokens, $answer['error_code']);
            $lastMessage = $answer['message'];
            if (!$answer['retryable']) {
                break;
            }
        }

        return self::result(false, 'ai_unavailable', $lastMessage, null, null);
    }

    /**
     * One provider call. Never throws; never returns the key.
     *
     * @param array<string, mixed> $binding
     * @param array<string, mixed> $request
     * @return array{ok: bool, text: ?string, refused: bool, retryable: bool, error_code: ?string, message: string, tokens: array{input:int, output:int}}
     */
    private static function call(array $binding, array $request): array
    {
        $provider = (string) $binding['provider'];
        $model = (string) ($binding['model'] !== '' ? $binding['model'] : self::DEFAULT_MODELS[$provider]);
        $maxTokens = min(self::MAX_TOKENS_CEILING, max(256, (int) ($request['max_tokens'] ?? 1500)),
            (int) ($binding['max_tokens'] ?? self::MAX_TOKENS_CEILING) ?: self::MAX_TOKENS_CEILING);
        $root = self::root($binding);
        $auth = self::authHeaders($binding);

        switch ($provider) {
            case 'anthropic':
                $url = $root . '/messages';
                $body = [
                    'model'      => $model,
                    'max_tokens' => $maxTokens,
                    'system'     => $request['system'],
                    'messages'   => [['role' => 'user', 'content' => $request['prompt']]],
                    'output_config' => [
                        'effort' => in_array($request['effort'] ?? 'low', ['low', 'medium', 'high'], true) ? ($request['effort'] ?? 'low') : 'low',
                        'format' => ['type' => 'json_schema', 'schema' => $request['schema']],
                    ],
                ];
                $headers = $auth + ['anthropic-version' => self::ANTHROPIC_VERSION];
                $response = self::post($url, $headers, $body);
                // An older model or a proxy that does not know output_config:
                // ask once more without the optional fields, and rely on the
                // prompt's schema and on validation.
                if ($response['status'] === 400) {
                    unset($body['output_config']);
                    $body['system'] .= "\n\nRespond with a single JSON object and nothing else.";
                    $response = self::post($url, $headers, $body);
                }
                break;

            case 'openai':
                $url = $root . '/chat/completions';
                $body = [
                    'model'    => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $request['system']],
                        ['role' => 'user', 'content' => $request['prompt']],
                    ],
                    'max_completion_tokens' => $maxTokens,
                    'response_format' => ['type' => 'json_schema', 'json_schema' => [
                        'name' => preg_replace('/[^a-z0-9_]/', '_', strtolower((string) $request['feature'])) ?: 'answer',
                        'schema' => $request['schema'],
                    ]],
                ];
                $response = self::post($url, $auth, $body);
                if ($response['status'] === 400) {
                    $body['response_format'] = ['type' => 'json_object'];
                    $response = self::post($url, $auth, $body);
                }
                break;

            case 'google':
                $url = $root . '/models/' . rawurlencode($model) . ':generateContent';
                $body = [
                    'systemInstruction' => ['parts' => [['text' => $request['system']
                        . "\n\nRespond with one JSON object matching this JSON Schema:\n" . json_encode($request['schema'])]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $request['prompt']]]]],
                    'generationConfig' => ['responseMimeType' => 'application/json', 'maxOutputTokens' => $maxTokens],
                ];
                $response = self::post($url, $auth, $body);
                break;

            default:
                return self::failure('unsupported_provider', 'Insights cannot speak to this provider.', false);
        }

        if ($response['error'] !== '') {
            return self::failure('transport', 'The AI provider could not be reached.', true);
        }
        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            return self::failure('unreadable', 'The AI provider returned something Insights could not read.', true);
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $retryable = $response['status'] === 429 || $response['status'] >= 500 || in_array($response['status'], [401, 403, 404], true);

            return self::failure('http_' . $response['status'], self::describe($response['status']), $retryable);
        }

        return match ($provider) {
            'anthropic' => self::readAnthropic($data),
            'openai'    => self::readOpenAi($data),
            default     => self::readGoogle($data),
        };
    }

    /** @param array<string, mixed> $data */
    private static function readAnthropic(array $data): array
    {
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $tokens = ['input' => (int) ($usage['input_tokens'] ?? 0), 'output' => (int) ($usage['output_tokens'] ?? 0)];
        $stop = (string) ($data['stop_reason'] ?? '');
        if ($stop === 'refusal') {
            return ['ok' => false, 'text' => null, 'refused' => true, 'retryable' => false, 'error_code' => 'refusal', 'message' => 'Declined.', 'tokens' => $tokens];
        }
        if ($stop === 'max_tokens') {
            return self::failure('max_tokens', 'The answer was cut short.', false) + ['tokens' => $tokens];
        }
        $text = '';
        foreach (is_array($data['content'] ?? null) ? $data['content'] : [] as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        return ['ok' => trim($text) !== '', 'text' => $text, 'refused' => false, 'retryable' => false,
            'error_code' => trim($text) === '' ? 'empty' : null, 'message' => 'The model returned no answer.', 'tokens' => $tokens];
    }

    /** @param array<string, mixed> $data */
    private static function readOpenAi(array $data): array
    {
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $tokens = ['input' => (int) ($usage['prompt_tokens'] ?? 0), 'output' => (int) ($usage['completion_tokens'] ?? 0)];
        $message = is_array($data['choices'][0]['message'] ?? null) ? $data['choices'][0]['message'] : [];
        if (is_string($message['refusal'] ?? null) && $message['refusal'] !== '') {
            return ['ok' => false, 'text' => null, 'refused' => true, 'retryable' => false, 'error_code' => 'refusal', 'message' => 'Declined.', 'tokens' => $tokens];
        }
        $text = is_string($message['content'] ?? null) ? $message['content'] : '';
        if (($data['choices'][0]['finish_reason'] ?? '') === 'length') {
            return self::failure('max_tokens', 'The answer was cut short.', false) + ['tokens' => $tokens];
        }

        return ['ok' => trim($text) !== '', 'text' => $text, 'refused' => false, 'retryable' => false,
            'error_code' => trim($text) === '' ? 'empty' : null, 'message' => 'The model returned no answer.', 'tokens' => $tokens];
    }

    /** @param array<string, mixed> $data */
    private static function readGoogle(array $data): array
    {
        $usage = is_array($data['usageMetadata'] ?? null) ? $data['usageMetadata'] : [];
        $tokens = ['input' => (int) ($usage['promptTokenCount'] ?? 0), 'output' => (int) ($usage['candidatesTokenCount'] ?? 0)];
        $candidate = is_array($data['candidates'][0] ?? null) ? $data['candidates'][0] : [];
        $finish = (string) ($candidate['finishReason'] ?? '');
        if (in_array($finish, ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'RECITATION'], true) || isset($data['promptFeedback']['blockReason'])) {
            return ['ok' => false, 'text' => null, 'refused' => true, 'retryable' => false, 'error_code' => 'refusal', 'message' => 'Declined.', 'tokens' => $tokens];
        }
        if ($finish === 'MAX_TOKENS') {
            return self::failure('max_tokens', 'The answer was cut short.', false) + ['tokens' => $tokens];
        }
        $text = '';
        foreach (is_array($candidate['content']['parts'] ?? null) ? $candidate['content']['parts'] : [] as $part) {
            $text .= is_array($part) ? (string) ($part['text'] ?? '') : '';
        }

        return ['ok' => trim($text) !== '', 'text' => $text, 'refused' => false, 'retryable' => false,
            'error_code' => trim($text) === '' ? 'empty' : null, 'message' => 'The model returned no answer.', 'tokens' => $tokens];
    }

    /**
     * The provider root from Console, https only — a malformed registry row
     * must not be able to send a live key over plaintext. A loopback http root
     * is accepted only on a local or test deployment, for the test suite's
     * stand-in provider.
     *
     * @param array<string, mixed> $binding
     */
    private static function root(array $binding): string
    {
        $base = rtrim((string) ($binding['base_url'] ?? ''), '/');
        $provider = (string) $binding['provider'];
        if ($base !== '' && str_starts_with(strtolower($base), 'https://')) {
            return $base;
        }
        if ($base !== '' && in_array(strtolower(Env::get('APP_ENV')), ['local', 'test'], true)
            && preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?(/|$)#', $base) === 1) {
            return $base;
        }

        return self::DEFAULT_ROOTS[$provider] ?? '';
    }

    /**
     * How this binding presents its key, as Console recorded it.
     *
     * @param array<string, mixed> $binding
     * @return array<string, string>
     */
    private static function authHeaders(array $binding): array
    {
        $key = (string) $binding['api_key'];
        if (strtolower((string) $binding['auth_method']) === 'bearer') {
            return ['authorization' => 'Bearer ' . $key];
        }
        $header = (string) ($binding['auth_header'] ?? '');
        if ($header === '' || preg_match('/^[A-Za-z0-9-]{1,64}$/', $header) !== 1) {
            $header = match ((string) $binding['provider']) {
                'google' => 'x-goog-api-key',
                'openai' => 'authorization',
                default  => 'x-api-key',
            };
        }

        return $header === 'authorization' ? ['authorization' => 'Bearer ' . $key] : [$header => $key];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $body
     * @return array{status:int, body:string, error:string}
     */
    private static function post(string $url, array $headers, array $body): array
    {
        $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || $url === '') {
            return ['status' => 0, 'body' => '', 'error' => 'encode'];
        }
        $wire = ['content-type: application/json', 'accept: application/json'];
        foreach ($headers as $name => $value) {
            $wire[] = $name . ': ' . $value;
        }
        $received = '';
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'error' => 'init'];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $encoded,
            CURLOPT_HTTPHEADER     => $wire,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => max(10, min(120, (int) Env::get('INSIGHTS_AI_TIMEOUT_SECONDS', '45'))),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION  => static function ($handle, string $chunk) use (&$received): int {
                // A bounded read: a misbehaving endpoint cannot fill memory.
                if (strlen($received) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    return 0;
                }
                $received .= $chunk;

                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $ok === false ? 'transport' : '';
        curl_close($ch);

        return ['status' => $status, 'body' => $received, 'error' => $error];
    }

    /** Models sometimes wrap JSON in a Markdown fence despite being asked not to. */
    private static function stripFence(string $text): string
    {
        $text = trim($text);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $m) === 1) {
            return $m[1];
        }

        return $text;
    }

    private static function describe(int $status): string
    {
        return match (true) {
            $status === 401, $status === 403 => 'The AI provider rejected the credential Insights presented. An administrator needs to check the binding in Console.',
            $status === 404 => 'The AI model bound in Console was not found at the provider.',
            $status === 429 => 'The AI provider is rate-limiting requests. Try again in a minute.',
            $status >= 500  => 'The AI provider is having problems. Try again shortly.',
            default         => 'The AI provider could not answer this request.',
        };
    }

    /** @return array{ok:false, text:null, refused:false, retryable:bool, error_code:string, message:string, tokens:array{input:int, output:int}} */
    private static function failure(string $code, string $message, bool $retryable): array
    {
        return ['ok' => false, 'text' => null, 'refused' => false, 'retryable' => $retryable, 'error_code' => $code,
            'message' => $message, 'tokens' => ['input' => 0, 'output' => 0]];
    }

    /**
     * @param array<string, mixed>|null $binding
     * @param array<string, mixed>|null $data
     * @return array{ok: bool, code: ?string, message: ?string, data: ?array, run_id: ?int, model: ?string, provider: ?string, latency_ms: ?int}
     */
    private static function result(bool $ok, ?string $code, ?string $message, ?array $data, ?int $runId, ?array $binding = null, ?int $latency = null): array
    {
        return [
            'ok' => $ok, 'code' => $code, 'message' => $message, 'data' => $data, 'run_id' => $runId,
            'model' => $binding === null ? null : ((string) $binding['model'] !== '' ? (string) $binding['model'] : self::DEFAULT_MODELS[(string) $binding['provider']] ?? null),
            'provider' => $binding['provider'] ?? null, 'latency_ms' => $latency,
        ];
    }

    /**
     * The usage log. Never the prompt, never the answer.
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed>|null $binding
     * @param array{input?:int, output?:int} $tokens
     */
    private static function log(array $request, ?array $binding, string $status, ?int $latency, array $tokens, ?string $error): ?int
    {
        if (!isset($request['cmp_id'])) {
            return null;
        }

        $outcome = match ($status) {
            'ok'             => 'success',
            'policy_blocked' => 'blocked',
            'unavailable'    => 'rules_only',
            default          => 'error',
        };

        try {
            return (int) Db::insert('insights_ai_usage', [
                'cmp_id'        => (int) $request['cmp_id'],
                'user_uuid'     => (string) ($request['actor_uuid'] ?? 'unknown'),
                'feature'       => mb_substr((string) $request['feature'], 0, 60),
                'outcome'       => $outcome,
                'error_code'    => $error === null ? null : mb_substr($error, 0, 48),
                'latency_ms'    => $latency,
                'prompt_tokens' => ($tokens['input'] ?? 0) > 0 ? (int) $tokens['input'] : null,
                'output_tokens' => ($tokens['output'] ?? 0) > 0 ? (int) $tokens['output'] : null,
                'model'         => $binding === null ? null : ((string) $binding['model'] !== '' ? (string) $binding['model'] : null),
                'provider'      => $binding['provider'] ?? null,
            ], 'usage_id');
        } catch (\Throwable $e) {
            // Telemetry must never fail the answer it is recording.
            error_log('[insights-ai] could not log usage: ' . $e->getMessage());

            return null;
        }
    }

    /** CLI on a test deployment, where the suite injects a binding without a Console. */
    private static function testing(): bool
    {
        return PHP_SAPI === 'cli' && in_array(strtolower(Env::get('APP_ENV')), ['test', 'local'], true);
    }

    /** Only for Context-free callers that need the status wording. */
    public static function available(): bool
    {
        return (ConsoleCredentials::isConfigured() || self::testing()) && ConsoleCredentials::chain() !== [];
    }
}
