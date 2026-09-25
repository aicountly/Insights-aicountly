<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Who is calling.
 *
 * Two ways in, exactly as Books, Sales and Billing accept:
 *
 *  1. A human — `Authorization: Bearer <ses_key>`, validated at my.aicountly.com.
 *     THE SES_KEY IS KEPT, and that is the load-bearing fact of this whole
 *     product: every figure Insights shows is fetched from the owning product
 *     AS THAT USER. Their permissions over there apply, unchanged, and Insights
 *     never has to re-implement them — nor can it exceed them.
 *
 *  2. A trusted product backend — `X-Service-Key`, plus `X-Actor-Uuid` naming the
 *     human it is acting for, for the audit trail.
 *
 * There is deliberately NO broad "Insights service token" used to read business
 * data. A token that could read every company's ledger would make dashboard
 * sharing equivalent to data sharing, and the two must stay separate.
 */
final class Auth
{
    private function __construct(
        public readonly string $uuid,
        public readonly string $kind,      // 'user' | 'service'
        public readonly string $sourceApp,
        private readonly string $sesKey,
        /** @var array<string, mixed>|null */
        private readonly ?array $session,
    ) {
    }

    /**
     * The caller a CLI test has stood in as.
     *
     * CLI ONLY. Under a web SAPI this is ignored outright, so it cannot become
     * an authentication bypass however it is called.
     */
    private static ?self $adopted = null;

    public static function adopt(?self $auth): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        self::$adopted = $auth;
    }

    /** Build an identity for a test to adopt. CLI only. */
    public static function forTesting(string $uuid, string $sesKey = 'test-ses-key', string $kind = 'user'): self
    {
        return new self($uuid, $kind, Env::get('APP_PRODUCT_KEY', 'insights'), $sesKey, ['uuid_aictly' => $uuid]);
    }

    /** Resolve the caller, or answer 401 and stop. */
    public static function require(): self
    {
        if (PHP_SAPI === 'cli' && self::$adopted !== null) {
            return self::$adopted;
        }

        $resolved = self::resolve();
        if ($resolved === null) {
            Http::unauthorized();
        }

        return $resolved;
    }

    public static function resolve(): ?self
    {
        $serviceKey = Http::header('X-Service-Key');
        if ($serviceKey !== '') {
            $app = ServiceKeys::resolveApp($serviceKey);
            if ($app === null) {
                return null;
            }
            // Proven by the key, not claimed in a header. Recording it is what
            // stops us calling that product back inside its own request.
            CrossServiceCallContext::adoptAuthenticatedOrigin($app);
            $actor = Http::header('X-Actor-Uuid');

            return new self(
                $actor !== '' ? $actor : 'service:' . $app,
                'service',
                $app,
                '',
                null,
            );
        }

        $sesKey = self::bearer();
        if ($sesKey === '') {
            return null;
        }

        $session = Portal::validateSesKey($sesKey);
        if ($session === null) {
            return null;
        }

        return new self(
            (string) ($session['uuid_aictly'] ?? $session['uuid'] ?? ''),
            'user',
            Env::get('APP_PRODUCT_KEY', 'insights'),
            $sesKey,
            $session,
        );
    }

    public function isService(): bool
    {
        return $this->kind === 'service';
    }

    /**
     * The session key, for reading Books / Inventory / Sales as this user.
     *
     * Empty for a service caller, which is correct: a service acts with its own
     * key over there, not with a borrowed human session.
     */
    public function sesKey(): string
    {
        return $this->sesKey;
    }

    /** Stable per-session identifier for memo keys. Never the key itself. */
    public function fingerprint(): string
    {
        return substr(hash('sha256', $this->kind . '|' . $this->uuid . '|' . $this->sesKey), 0, 32);
    }

    /**
     * What Manage said this session's role is, per company. 1 = owner.
     *
     * @var array<int, ?int>
     */
    private array $companyAccess = [];

    public function noteCompanyAccess(int $cmpId, ?int $accessType): void
    {
        $this->companyAccess[$cmpId] = $accessType;
    }

    /** The role Manage reported, or null if it never reported one. Null is not zero. */
    public function accessTypeFor(int $cmpId): ?int
    {
        return $this->companyAccess[$cmpId] ?? null;
    }

    /** True only when Manage said so. An unanswered lookup is not ownership. */
    public function ownsCompany(int $cmpId): bool
    {
        return $this->accessTypeFor($cmpId) === CompanyAccess::OWNER;
    }

    /** Whether Manage reported a role at all — "not the owner" vs "never asked". */
    public function companyAccessResolved(int $cmpId): bool
    {
        return array_key_exists($cmpId, $this->companyAccess) && $this->companyAccess[$cmpId] !== null;
    }

    public function displayName(): string
    {
        foreach (['name', 'full_name', 'user_name', 'email'] as $field) {
            $value = $this->session[$field] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $this->uuid;
    }

    private static function bearer(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!is_string($header) || $header === '') {
            if (function_exists('apache_request_headers')) {
                foreach ((array) apache_request_headers() as $name => $value) {
                    if (strcasecmp((string) $name, 'Authorization') === 0) {
                        $header = (string) $value;
                        break;
                    }
                }
            }
        }
        if (!is_string($header) || preg_match('/Bearer\s+(.+)/i', $header, $m) !== 1) {
            return '';
        }

        return trim($m[1]);
    }
}
