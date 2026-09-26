<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\CrossServiceCallContext;
use Aicountly\Api\Env;
use Aicountly\Api\Http;

/**
 * Base for every outbound call to another AICOUNTLY product.
 *
 * THE RULE THIS CLASS EXISTS TO KEEP: authoritative data owned by another
 * product is READ LIVE, here, on the request that needs it. It is never copied
 * into this product's database, never refreshed by a cron, never mirrored into
 * a "cache" table. A short-lived in-request memo (see $memo) is the only thing
 * that survives a call, and it dies with the process.
 *
 * Insights leans on that harder than any sibling: an overview screen asks five
 * products at once. So three extra things live here that do not exist in the
 * other products' copies of this class:
 *
 *  1. A HOST ALLOWLIST. `base()` may be overridden from the environment, and an
 *     environment variable is something an operator edits. Only hosts under
 *     aicountly.com / aicountly.org — plus loopback in local and test — are
 *     ever dialled, so a typo or a tampered .env cannot point a client with a
 *     live session key at somebody else's server.
 *
 *  2. BOUNDED PARALLELISM. `parallel()` runs a batch of GETs through one
 *     curl_multi handle with a hard cap, because five sequential 6-second
 *     timeouts is a 30-second dashboard.
 *
 *  3. A RETRY, FOR SAFE READS ONLY. One retry on a transport failure or a 429/
 *     503, never on a 4xx, and never for a method other than GET.
 */
abstract class ApiClient
{
    protected const CONNECT_TIMEOUT_OPTIONAL = 2;
    protected const TOTAL_TIMEOUT_OPTIONAL   = 8;
    protected const CONNECT_TIMEOUT_REQUIRED = 3;
    protected const TOTAL_TIMEOUT_REQUIRED   = 20;

    /** The most calls Insights will have in flight at once. */
    public const MAX_CONCURRENCY = 6;

    /** Hosts an outbound call may be sent to, whatever the configuration says. */
    private const ALLOWED_SUFFIXES = ['.aicountly.com', '.aicountly.org', 'aicountly.com', 'aicountly.org'];

    /**
     * Per-request memo of GET responses, keyed by method+url+authorization.
     *
     * Process-local and dies with the request. It exists so one screen that
     * needs the same company settings in three places costs one call, not
     * three — never so a later request can skip asking.
     *
     * @var array<string, array{ok:bool, status:int, body:?array, error:?string}>
     */
    private array $memo = [];

    /** Product name this client talks to: books | inventory | manage | sales | … */
    abstract public function service(): string;

    abstract protected function productionBase(): string;

    abstract protected function sandboxBase(): string;

    /** Environment variable that overrides the derived base, e.g. BOOKS_API_BASE. */
    abstract protected function baseEnvKey(): string;

    /** This product's own name, sent so the callee will not call us back inside our own request. */
    protected function selfName(): string
    {
        return Env::get('APP_PRODUCT_KEY', 'insights');
    }

    /**
     * Where this product's sibling lives.
     *
     * Derived from our own hostname so sandbox talks to sandbox without a
     * second set of environment variables to keep in step — an explicit env
     * override still wins, for local development and for a one-off cutover, but
     * only if it names an allowed host.
     */
    public function base(): string
    {
        $configured = trim(Env::get($this->baseEnvKey()));
        if ($configured !== '') {
            if (self::hostIsAllowed($configured)) {
                return rtrim($configured, '/');
            }
            error_log(sprintf(
                '[insights][cross-service] %s is set to a host outside the AICOUNTLY allowlist; ignoring it.',
                $this->baseEnvKey(),
            ));
        }

        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = explode(':', (string) preg_replace('/^www\./', '', $host))[0];

        if (
            $host === ''
            || str_contains($host, '.gh.aicountly.com')
            || str_starts_with($host, 'gh-')
            || str_contains($host, 'localhost')
            || str_starts_with($host, '127.')
            || strtolower(Env::get('APP_ENV')) === 'sandbox'
        ) {
            return $this->sandboxBase();
        }

        return $this->productionBase();
    }

    /** True when this product has somewhere to call at all. */
    public function isConfigured(): bool
    {
        return $this->base() !== '';
    }

    /** The API root. A configured base may already include /api, and a local server serves it at the root. */
    public function apiRoot(): string
    {
        $base = $this->base();
        if (preg_match('#/api$#', $base) === 1 || preg_match('#^https?://(127\.0\.0\.1|localhost)(:\d+)?$#', $base) === 1) {
            return $base;
        }

        return $base . '/api';
    }

    /**
     * One call to the other product.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     * @return array{ok:bool, status:int, body:?array, error:?string}
     */
    public function request(string $method, string $path, ?array $body = null, array $headers = [], bool $required = false): array
    {
        $method = strtoupper($method);

        // RE-ENTRY GUARD. If the product we are about to call is the one whose
        // request we are serving, its worker is already blocked on our
        // response. Calling it now parks a second one of its workers.
        if (CrossServiceCallContext::isInboundFrom($this->service())) {
            $this->log('suppressed', $path, 0, 0.0, 'inbound_from_' . $this->service());

            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => $this->service() . '_reentrant_call_refused'];
        }

        if (!$this->isConfigured()) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => $this->service() . '_not_configured'];
        }

        $url = $this->apiRoot() . '/' . ltrim($path, '/');
        $memoKey = $method . ' ' . $url . ' ' . ($headers['Authorization'] ?? $headers['X-Service-Key'] ?? '');
        if ($method === 'GET' && isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        $result = $this->send($method, $url, $body, $headers, $required);

        // One retry, GET only, and only where retrying is safe and might help.
        if ($method === 'GET' && $this->shouldRetry($result)) {
            usleep(180_000);
            $retried = $this->send($method, $url, $body, $headers, $required);
            if (($retried['ok'] ?? false) || (int) $retried['status'] >= 400) {
                $result = $retried;
            }
        }

        if ($method === 'GET') {
            $this->memo[$memoKey] = $result;
        }

        return $result;
    }

    /**
     * Several GETs at once, through one curl_multi handle.
     *
     * Bounded at MAX_CONCURRENCY: an overview that fans out to twenty widgets
     * must not open twenty sockets to one sibling and have it shed load.
     *
     * @param array<string, array{path:string, headers?:array<string,string>, required?:bool}> $calls keyed by caller's label
     * @return array<string, array{ok:bool, status:int, body:?array, error:?string}>
     */
    public function parallel(array $calls): array
    {
        if ($calls === []) {
            return [];
        }
        if (CrossServiceCallContext::isInboundFrom($this->service())) {
            $refused = ['ok' => false, 'status' => 0, 'body' => null, 'error' => $this->service() . '_reentrant_call_refused'];

            return array_map(static fn () => $refused, $calls);
        }
        if (!$this->isConfigured()) {
            $missing = ['ok' => false, 'status' => 0, 'body' => null, 'error' => $this->service() . '_not_configured'];

            return array_map(static fn () => $missing, $calls);
        }

        $results = [];
        foreach (array_chunk($calls, self::MAX_CONCURRENCY, true) as $chunk) {
            foreach ($this->runBatch($chunk) as $label => $result) {
                $results[$label] = $result;
            }
        }

        return $results;
    }

    /**
     * @param array<string, array{path:string, headers?:array<string,string>, required?:bool}> $chunk
     * @return array<string, array{ok:bool, status:int, body:?array, error:?string}>
     */
    private function runBatch(array $chunk): array
    {
        $multi = curl_multi_init();
        $handles = [];
        $results = [];
        $startedAt = microtime(true);

        foreach ($chunk as $label => $call) {
            $url = $this->apiRoot() . '/' . ltrim($call['path'], '/');
            $headers = $call['headers'] ?? [];
            $memoKey = 'GET ' . $url . ' ' . ($headers['Authorization'] ?? $headers['X-Service-Key'] ?? '');
            if (isset($this->memo[$memoKey])) {
                $results[$label] = $this->memo[$memoKey];
                continue;
            }

            $ch = curl_init($url);
            if ($ch === false) {
                $results[$label] = ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'curl_init_failed'];
                continue;
            }
            curl_setopt_array($ch, $this->options('GET', null, $headers, (bool) ($call['required'] ?? false)));
            curl_multi_add_handle($multi, $ch);
            $handles[$label] = ['handle' => $ch, 'memo' => $memoKey, 'path' => $call['path']];
        }

        if ($handles !== []) {
            $running = null;
            do {
                $status = curl_multi_exec($multi, $running);
                if ($running > 0) {
                    curl_multi_select($multi, 0.5);
                }
            } while ($running > 0 && $status === CURLM_OK);

            foreach ($handles as $label => $entry) {
                $ch = $entry['handle'];
                $raw = curl_multi_getcontent($ch);
                $result = $this->interpret(
                    $raw === null ? false : $raw,
                    (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                    curl_error($ch),
                    $entry['path'],
                    (microtime(true) - $startedAt) * 1000,
                );
                $results[$label] = $result;
                $this->memo[$entry['memo']] = $result;
                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
            }
        }

        curl_multi_close($multi);

        return $results;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     * @return array{ok:bool, status:int, body:?array, error:?string}
     */
    private function send(string $method, string $url, ?array $body, array $headers, bool $required): array
    {
        $startedAt = microtime(true);
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'curl_init_failed'];
        }

        curl_setopt_array($ch, $this->options($method, $body, $headers, $required));

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $transportError = curl_error($ch);
        curl_close($ch);

        return $this->interpret($raw, $status, $transportError, $url, (microtime(true) - $startedAt) * 1000);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     * @return array<int, mixed>
     */
    private function options(string $method, ?array $body, array $headers, bool $required): array
    {
        $wire = ['Accept: application/json'];
        if ($body !== null) {
            $wire[] = 'Content-Type: application/json';
        }
        // Name ourselves, so the callee suppresses its own calls back to us.
        $wire[] = CrossServiceCallContext::HEADER . ': ' . $this->selfName();
        $wire[] = 'X-Source-App: ' . $this->selfName();
        $wire[] = 'X-Correlation-Id: ' . Http::correlationId();
        foreach ($headers as $name => $value) {
            if ($value !== '') {
                $wire[] = $name . ': ' . $value;
            }
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $wire,
            CURLOPT_CONNECTTIMEOUT => $required ? self::CONNECT_TIMEOUT_REQUIRED : self::CONNECT_TIMEOUT_OPTIONAL,
            CURLOPT_TIMEOUT        => $required ? self::TOTAL_TIMEOUT_REQUIRED : self::TOTAL_TIMEOUT_OPTIONAL,
            // A redirect is how an allowlisted host hands a live session key to
            // one that is not. Nothing in this fleet needs one.
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }

        // Refuse anything that is not HTTP, so a redirect or a malformed base
        // can never make this client speak file:// or gopher://.
        //
        // WHICH CONSTANT EXISTS DEPENDS ON THE BUILD, NOT ON PHP. The _STR form
        // needs libcurl 7.85, and PHP simply does not define it when linked
        // against anything older — plenty of shared hosts are. Referencing an
        // undefined constant is a fatal Error in PHP 8, so naming it directly
        // made every cross-service call answer 500 on such a host while
        // /api/health, which makes none, stayed green. Portal::forward sets no
        // protocol option at all, which is why signing in worked and the very
        // next request did not.
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
        } elseif (defined('CURLOPT_PROTOCOLS')) {
            // Deprecated in libcurl 7.85, present since long before it.
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        return $options;
    }

    /**
     * @return array{ok:bool, status:int, body:?array, error:?string}
     */
    private function interpret(string|bool $raw, int $status, string $transportError, string $path, float $ms): array
    {
        if ($raw === false || $raw === '' && $status === 0 || $status === 0) {
            $this->log('failed', $path, $status, $ms, $transportError !== '' ? 'transport' : 'no_response');

            return [
                'ok'     => false,
                'status' => 0,
                'body'   => null,
                // The transport message can echo the URL, and the URL can carry
                // identifiers. Keep it generic.
                'error'  => $this->service() . ' could not be reached.',
            ];
        }

        $decoded = json_decode((string) $raw, true);
        $result = [
            'ok'     => $status >= 200 && $status < 300,
            'status' => $status,
            'body'   => is_array($decoded) ? $decoded : null,
            'error'  => null,
        ];

        if (!$result['ok']) {
            $result['error'] = is_array($decoded)
                ? (string) ($decoded['error']['message'] ?? $decoded['message'] ?? 'HTTP ' . $status)
                : 'HTTP ' . $status;
            $this->log('error', $path, $status, $ms, null);
        }

        return $result;
    }

    /** @param array{ok:bool, status:int} $result */
    private function shouldRetry(array $result): bool
    {
        if ($result['ok']) {
            return false;
        }
        $status = (int) $result['status'];

        // 0 is a transport failure; 429 and 503 are the two the callee itself
        // says to come back for. A 4xx is an answer and retrying it is rude.
        return $status === 0 || $status === 429 || $status === 503;
    }

    /**
     * Log the path only — never the query string, which carries identifiers,
     * and never tokens or response bodies. A fast success is silent: these run
     * on every company-scoped request and logging each one buries the failures.
     */
    private function log(string $outcome, string $path, int $status, float $ms, ?string $reason): void
    {
        $operation = explode('?', ltrim(parse_url($path, PHP_URL_PATH) ?: $path, '/'))[0];
        error_log(sprintf(
            '[insights][cross-service] service=%s outcome=%s operation=%s status=%d ms=%d corr=%s%s',
            $this->service(),
            $outcome,
            $operation,
            $status,
            (int) $ms,
            Http::correlationId(),
            $reason !== null ? ' reason=' . $reason : '',
        ));
    }

    /** Only AICOUNTLY hosts, plus loopback when this deployment is local or a test. */
    private static function hostIsAllowed(string $url): bool
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '') {
            return false;
        }

        $env = strtolower(Env::get('APP_ENV'));
        if (in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return in_array($env, ['local', 'test'], true);
        }

        if ($scheme !== 'https') {
            return false;
        }

        foreach (self::ALLOWED_SUFFIXES as $suffix) {
            if ($host === ltrim($suffix, '.') || str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** `?a=1&b=2` from a map, dropping nulls and empty strings. */
    protected static function query(array $params): string
    {
        $clean = [];
        foreach ($params as $key => $value) {
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }
            $clean[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        return $clean === [] ? '' : '?' . http_build_query($clean);
    }
}
