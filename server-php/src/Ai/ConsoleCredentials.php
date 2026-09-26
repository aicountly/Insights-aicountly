<?php

declare(strict_types=1);

namespace Aicountly\Api\Ai;

use Aicountly\Api\Env;

/**
 * Insights' AI provider credentials, from Console.
 *
 * ## Why Console and not a key in this product's .env
 *
 * console.aicountly.org is the fleet's system of record for AI provider keys.
 * Console holds each key encrypted and hands it over on
 * `GET {CONSOLE_API_URL}/ai/credentials/resolve?domain=<host>&module=<key>`,
 * authenticated with the shared `CONSOLE_SERVICE_KEY`. Rotating a key is done
 * once, in Console, and takes effect here within the cache TTL — no deploy.
 *
 * There is deliberately no OPENAI_API_KEY / ANTHROPIC_API_KEY / GEMINI_API_KEY
 * anywhere in Insights, and no fallback to one. There is also no key input
 * field anywhere in the customer-facing app: a business owner using Insights is
 * not the person who buys model capacity, and offering them a box to paste a
 * key into is how a key ends up in a support ticket.
 *
 * NOTHING IS WRITTEN TO DISK HERE. The answer is held in process memory and,
 * where APCu exists, in shared memory. A provider key never comes to rest in a
 * file next to this code, never reaches a log, a response or the browser.
 *
 * ## The contract, as Console implements it
 *
 * Console matches `ai_domains.domain` EXACTLY against a real hostname — not a
 * product slug. `insights.aicountly.com` in production,
 * `insights.gh.aicountly.com` in the sandbox, each needing its own row in
 * Console. Sending a slug such as "insights" returns 404.
 *
 * The answer is the whole binding chain — primary first, then fallbacks — each
 * with provider, provider ROOT base_url, auth_method / auth_header, model,
 * max_tokens and the ids Console wants back on usage events.
 *
 * Adapted from the CRM and Lobby implementations, deliberately, so the fleet
 * has one way of doing this.
 */
final class ConsoleCredentials
{
    /** The Console module Insights resolves under. */
    public const DEFAULT_MODULE = 'insights_copilot';

    private const DEFAULT_TTL = 300;
    private const TIMEOUT = 4;
    private const CONNECT_TIMEOUT = 2;

    /**
     * How long a failure is remembered, in this process only.
     *
     * A down Console should cost one timeout per worker per half minute, not
     * one per question. Deliberately not shared through APCu: a negative result
     * shared across every worker would keep a recovered Console invisible for
     * as long as it lasted.
     */
    private const FAILURE_TTL = 30;

    /** @var array<string, array{value: ?array, expires: int}> */
    private static array $memo = [];

    public static function module(): string
    {
        $configured = trim(Env::get('INSIGHTS_AI_MODULE'));

        return $configured !== '' ? strtolower($configured) : self::DEFAULT_MODULE;
    }

    /**
     * The hostname Console has on record for this deployment.
     *
     * `CONSOLE_AI_DOMAIN` wins. Otherwise the sandbox is recognised from the
     * request host or APP_ENV, so a sandbox deployment never resolves
     * production's binding.
     */
    public static function domain(): string
    {
        $configured = trim(Env::get('CONSOLE_AI_DOMAIN'));
        if ($configured !== '') {
            return strtolower($configured);
        }

        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if (str_contains($host, '.gh.aicountly.com') || strtolower(Env::get('APP_ENV')) === 'sandbox') {
            return 'insights.gh.aicountly.com';
        }

        return 'insights.aicountly.com';
    }

    /** Whether this deployment is pointed at a Console at all. */
    public static function isConfigured(): bool
    {
        return self::baseUrl() !== '' && Env::get('CONSOLE_SERVICE_KEY') !== '';
    }

    /**
     * The binding chain, primary first. Empty when nothing is bound.
     *
     * @return list<array{
     *     api_key: string, model: string, provider: string, base_url: ?string,
     *     auth_method: string, auth_header: ?string, max_tokens: ?int,
     *     purpose: string, ids: array<string, int>
     * }>
     */
    public static function chain(): array
    {
        $cacheKey = self::domain() . '|' . self::module();

        if (isset(self::$memo[$cacheKey]) && self::$memo[$cacheKey]['expires'] > time()) {
            return self::$memo[$cacheKey]['value'] ?? [];
        }

        $shared = self::apcuGet($cacheKey);
        if ($shared !== null) {
            self::$memo[$cacheKey] = ['value' => $shared, 'expires' => time() + 30];

            return $shared;
        }

        $fromConsole = self::fetch();
        $shaped = $fromConsole === null ? [] : self::shape($fromConsole);

        if ($shaped === []) {
            self::$memo[$cacheKey] = ['value' => null, 'expires' => time() + self::FAILURE_TTL];

            return [];
        }

        $ttl = max(30, (int) ($fromConsole['ttl_seconds'] ?? self::DEFAULT_TTL));
        self::$memo[$cacheKey] = ['value' => $shaped, 'expires' => time() + $ttl];
        self::apcuSet($cacheKey, $shaped, $ttl);

        return $shaped;
    }

    /**
     * What a screen may say about AI, with no secret in it.
     *
     * The variable NAME goes in `admin_hint`, never a value, and the caller
     * shows that hint only to somebody who can change settings.
     *
     * @return array{available: bool, model: ?string, provider: ?string, reason: ?string, admin_hint: ?string, domain: string, module: string}
     */
    public static function status(): array
    {
        $base = ['domain' => self::domain(), 'module' => self::module()];

        if (!self::isConfigured()) {
            return $base + [
                'available'  => false,
                'model'      => null,
                'provider'   => null,
                'reason'     => 'Ask Insights is not connected for this deployment. Every other part of Insights works without it.',
                'admin_hint' => 'Set CONSOLE_API_URL and CONSOLE_SERVICE_KEY in the API .env on the server. The provider key itself lives in Console, not here.',
            ];
        }

        $chain = self::chain();
        if ($chain === []) {
            return $base + [
                'available'  => false,
                'model'      => null,
                'provider'   => null,
                'reason'     => 'Ask Insights is temporarily unavailable. Every other part of Insights works without it.',
                'admin_hint' => 'In Console, bind an active AI credential to the "' . self::module()
                    . '" module under the ' . self::domain() . ' domain.',
            ];
        }

        $primary = $chain[0];

        return $base + [
            'available'  => true,
            'model'      => $primary['model'] !== '' ? $primary['model'] : null,
            'provider'   => $primary['provider'],
            'reason'     => null,
            'admin_hint' => null,
        ];
    }

    /**
     * Report one AI call back to Console, in Console's own event schema.
     *
     * Fire-and-forget with a short budget: telemetry must never delay somebody's
     * answer. Only ids, counts and an outcome go across — never the prompt,
     * never the company's figures, never the key.
     *
     * @param array<string, mixed>            $binding
     * @param array{input?: int, output?: int} $tokens
     */
    public static function reportUsage(
        array $binding,
        string $outcome,
        ?string $errorCode,
        ?int $latencyMs,
        array $tokens,
        string $feature,
        ?int $cmpId,
        ?string $actorUuid,
    ): void {
        $base = self::baseUrl();
        $key = Env::get('CONSOLE_SERVICE_KEY');
        if ($base === '' || $key === '' || (PHP_SAPI === 'cli' && in_array(strtolower(Env::get('APP_ENV')), ['test', 'local'], true))) {
            return;
        }

        $ids = is_array($binding['ids'] ?? null) ? $binding['ids'] : [];
        $input = (int) ($tokens['input'] ?? 0);
        $output = (int) ($tokens['output'] ?? 0);

        $event = [
            'occurred_at'       => gmdate('c'),
            'domain_id'         => $ids['domain_id'] ?? null,
            'module_id'         => $ids['module_id'] ?? null,
            'provider_id'       => $ids['provider_id'] ?? null,
            'model_id'          => $ids['model_id'] ?? null,
            'credential_id'     => $ids['credential_id'] ?? null,
            'actor_uuid'        => $actorUuid === null ? null : substr($actorUuid, 0, 64),
            'actor_company'     => $cmpId === null ? null : (string) $cmpId,
            'outcome'           => in_array($outcome, ['success', 'error', 'blocked'], true) ? $outcome : 'error',
            'error_code'        => $errorCode === null ? null : substr($errorCode, 0, 48),
            'latency_ms'        => $latencyMs,
            'prompt_tokens'     => $input > 0 ? $input : null,
            'completion_tokens' => $output > 0 ? $output : null,
            'total_tokens'      => ($input + $output) > 0 ? $input + $output : null,
            'dimensions'        => ['surface' => 'insights', 'feature' => $feature],
        ];

        $ch = curl_init($base . '/ai/usage');
        if ($ch === false) {
            return;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => (string) json_encode(['events' => [$event]], JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT        => 2,
            CURLOPT_CONNECTTIMEOUT => 1,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    /** @return array<string, mixed>|null the decoded `data` envelope */
    private static function fetch(): ?array
    {
        $base = self::baseUrl();
        $key = Env::get('CONSOLE_SERVICE_KEY');
        if ($base === '' || $key === '') {
            return null;
        }

        $url = $base . '/ai/credentials/resolve?domain=' . rawurlencode(self::domain())
            . '&module=' . rawurlencode(self::module());

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key, 'Accept: application/json'],
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($raw) || $status !== 200) {
            // Deliberately generic, and without the URL: a transport message
            // can echo the request, and the request carries the service key.
            error_log(sprintf(
                '[insights-ai] Console resolve for %s/%s failed: %s',
                self::domain(),
                self::module(),
                $error !== '' ? 'transport error' : 'HTTP ' . $status,
            ));

            return null;
        }

        $json = json_decode($raw, true);

        return is_array($json['data'] ?? null) ? $json['data'] : null;
    }

    /**
     * The chain, flattened and validated.
     *
     * A binding with no key is dropped; so is one whose provider Insights does
     * not know how to speak to, because sending a live key to a URL in a format
     * that provider does not use is the one failure worse than "unavailable".
     *
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private static function shape(array $data): array
    {
        $list = $data['credentials'] ?? [];
        if (!is_array($list)) {
            return [];
        }

        $out = [];
        foreach ($list as $row) {
            if (!is_array($row) || trim((string) ($row['api_key'] ?? '')) === '') {
                continue;
            }
            $provider = strtolower((string) ($row['provider'] ?? ''));
            if (!AiClient::speaks($provider)) {
                error_log('[insights-ai] Console bound provider "' . $provider . '", which Insights does not speak; skipped.');
                continue;
            }
            $ids = [];
            foreach (is_array($row['ids'] ?? null) ? $row['ids'] : [] as $name => $value) {
                $ids[(string) $name] = (int) $value;
            }
            $out[] = [
                'api_key'     => (string) $row['api_key'],
                'model'       => (string) ($row['model'] ?? ''),
                'provider'    => $provider,
                'base_url'    => isset($row['base_url']) ? (string) $row['base_url'] : null,
                'auth_method' => (string) ($row['auth_method'] ?? 'bearer'),
                'auth_header' => isset($row['auth_header']) ? (string) $row['auth_header'] : null,
                'max_tokens'  => isset($row['max_tokens']) ? (int) $row['max_tokens'] : null,
                'purpose'     => (string) ($row['purpose'] ?? 'primary'),
                'ids'         => $ids,
            ];
        }

        return $out;
    }

    private static function baseUrl(): string
    {
        return rtrim(Env::get('CONSOLE_API_URL'), '/');
    }

    /** @return list<array<string, mixed>>|null */
    private static function apcuGet(string $key): ?array
    {
        if (!function_exists('apcu_fetch') || !ini_get('apc.enabled')) {
            return null;
        }
        $hit = false;
        $value = apcu_fetch('insights_ai_cred:' . $key, $hit);

        return ($hit && is_array($value)) ? $value : null;
    }

    /** @param list<array<string, mixed>> $value */
    private static function apcuSet(string $key, array $value, int $ttl): void
    {
        if (function_exists('apcu_store') && ini_get('apc.enabled')) {
            // Shared memory only — never a file.
            apcu_store('insights_ai_cred:' . $key, $value, $ttl);
        }
    }

    /**
     * CLI only. Lets the test suite exercise the AI paths without a Console.
     *
     * Guarded by SAPI rather than an environment flag: a flag is something a
     * misconfigured production host can end up carrying, and this accepts an
     * injected credential.
     *
     * @param list<array<string, mixed>>|null $chain null clears; [] simulates "nothing bound"
     */
    public static function overrideForTesting(?array $chain): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        if ($chain === null) {
            self::$memo = [];

            return;
        }
        self::$memo[self::domain() . '|' . self::module()] = [
            'value'   => $chain === [] ? null : $chain,
            'expires' => time() + 300,
        ];
    }
}
