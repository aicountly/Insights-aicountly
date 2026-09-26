<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Clients\ManageClient;

/**
 * The company / branch / financial year every scoped request carries.
 *
 * Three ids and nothing else. The names and dates behind them belong to Manage
 * and are read from Manage at the point of use — a financial year whose dates
 * were copied once keeps the old dates after somebody corrects them, and every
 * figure near the boundary then lands in the wrong year.
 *
 * TENANT ISOLATION: `cmp_id` arriving in a query string is a claim, not a fact.
 * assertAllowed() checks it against what Manage says this session may open, and
 * a company the session has no access to is a 403 — never a query that quietly
 * returns nothing, which leaks the difference between "no rows" and "not yours"
 * and breaks the moment a query forgets its WHERE clause.
 */
final class Context
{
    /**
     * Company id + session -> the role Manage reported, memoised for the request.
     *
     * @var array<string, ?int>
     */
    private static array $verified = [];

    private function __construct(
        public readonly int $cmpId,
        public readonly int $fyId,
        /** 0 = consolidated, all branches. */
        public readonly int $boId,
    ) {
    }

    /** Read the scope out of the request, refusing anything incomplete. */
    public static function fromRequest(): self
    {
        $cmpId = Http::intParam('cmp_id', 0) ?? 0;
        $fyId  = Http::intParam('fy_id', 0) ?? 0;
        $boId  = Http::intParam('bo_id', 0) ?? 0;

        if ($cmpId <= 0 || $fyId <= 0) {
            Http::error(400, 'context_required', 'Pick a company and financial year first (cmp_id and fy_id are required).');
        }

        return new self($cmpId, $fyId, max(0, $boId));
    }

    /** An explicit scope, for a stored dashboard or a test. */
    public static function of(int $cmpId, int $fyId, int $boId = 0): self
    {
        return new self($cmpId, $fyId, max(0, $boId));
    }

    /**
     * Confirm this session may open this company, per Manage.
     *
     * Memoised per request because it runs on every scoped endpoint; a failure
     * to reach Manage is a 503 and not an allow, because the alternative is
     * serving one tenant's data to another whenever Manage has a bad minute.
     */
    public function assertAllowed(Auth $auth): void
    {
        if ($auth->isService()) {
            return;
        }

        $key = $this->cmpId . ':' . $auth->fingerprint();
        if (array_key_exists($key, self::$verified)) {
            // Re-noted, not skipped: the memo saves the round trip, not the
            // answer. Returning without telling Auth the role would leave every
            // request after the first one looking like a stranger.
            $auth->noteCompanyAccess($this->cmpId, self::$verified[$key]);

            return;
        }

        $result = (new ManageClient())->withSession($auth->sesKey())->companyInfo($this->cmpId);

        if (!$result['ok']) {
            // A REFUSAL AND AN OUTAGE ARE DIFFERENT ANSWERS. Manage answering
            // 401/403/404 is a definite "not yours", and telling the caller to
            // retry would be wrong and would hide a real access problem behind
            // a transient-looking error. Anything else — a timeout, a 5xx — is
            // an answer we could not get, and that is a 503.
            //
            // Neither is "allowed". A tenant check that fails open is not a
            // tenant check.
            $status = (int) ($result['status'] ?? 0);
            if (in_array($status, [401, 403, 404], true)) {
                Http::forbidden('You do not have access to this company.');
            }

            Http::error(503, 'context_unavailable', 'Cannot confirm company access right now. Please retry.', ['retryable' => true]);
        }

        $body = $result['body'] ?? [];
        $company = $body['data'] ?? $body['company'] ?? $body;
        $resolved = (int) ($company['cmp_id'] ?? $company['comp_id'] ?? $company['id'] ?? 0);

        if ($resolved !== $this->cmpId) {
            Http::forbidden('You do not have access to this company.');
        }

        $accessType = CompanyAccess::fromPayload(is_array($company) ? $company : []);
        if ($accessType === null) {
            $accessType = CompanyAccess::fromPayload(is_array($body) ? $body : []);
        }
        if ($accessType === null) {
            error_log(sprintf(
                '[insights][context] Manage reported no role for company %d; owner access will not apply. Payload keys: %s',
                $this->cmpId,
                implode(',', array_slice(array_keys(is_array($company) ? $company : []), 0, 20)),
            ));
        }

        $auth->noteCompanyAccess($this->cmpId, $accessType);
        self::$verified[$key] = $accessType;
    }

    /**
     * Whether this session may open this company — without stopping the request.
     *
     * Used where a refusal is an answer rather than an error: re-checking a
     * dashboard viewer's access before returning somebody else's saved layout.
     */
    public function isAllowed(Auth $auth): bool
    {
        if ($auth->isService()) {
            return true;
        }

        $key = $this->cmpId . ':' . $auth->fingerprint();
        if (array_key_exists($key, self::$verified)) {
            $auth->noteCompanyAccess($this->cmpId, self::$verified[$key]);

            return true;
        }

        $result = (new ManageClient())->withSession($auth->sesKey())->companyInfo($this->cmpId);
        if (!($result['ok'] ?? false)) {
            return false;
        }

        $body = $result['body'] ?? [];
        $company = $body['data'] ?? $body['company'] ?? $body;
        if ((int) ($company['cmp_id'] ?? $company['comp_id'] ?? $company['id'] ?? 0) !== $this->cmpId) {
            return false;
        }

        $accessType = CompanyAccess::fromPayload(is_array($company) ? $company : [])
            ?? CompanyAccess::fromPayload(is_array($body) ? $body : []);
        $auth->noteCompanyAccess($this->cmpId, $accessType);
        self::$verified[$key] = $accessType;

        return true;
    }

    /** Drop the memoised company checks. Tests only. */
    public static function forgetVerified(): void
    {
        self::$verified = [];
    }

    /** Tests only: pre-seed the answer so a test needs no Manage. */
    public static function seedVerifiedForTesting(int $cmpId, Auth $auth, ?int $accessType): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        self::$verified[$cmpId . ':' . $auth->fingerprint()] = $accessType;
        $auth->noteCompanyAccess($cmpId, $accessType);
    }

    /** @return array{cmp_id:int, fy_id:int, bo_id:int} */
    public function asQuery(): array
    {
        return ['cmp_id' => $this->cmpId, 'fy_id' => $this->fyId, 'bo_id' => $this->boId];
    }

    /** @return array{cmp_id:int, fy_id:int, bo_id:int} */
    public function asBody(): array
    {
        return $this->asQuery();
    }

    /** A stable key for request-scoped memoisation of a query in this scope. */
    public function key(): string
    {
        return $this->cmpId . ':' . $this->fyId . ':' . $this->boId;
    }

    /**
     * The WHERE fragment and bindings every Insights-owned query starts with.
     *
     * `bo_id` is not part of it: Insights' own rows (dashboards, templates,
     * preferences) belong to a company, not to a branch. The branch narrows the
     * business query sent to the owning product, not the dashboard definition.
     *
     * @return array{0:string, 1:array<string, mixed>}
     */
    public function scopeClause(string $alias = ''): array
    {
        $prefix = $alias === '' ? '' : $alias . '.';

        return [$prefix . 'cmp_id = :ctx_cmp_id', ['ctx_cmp_id' => $this->cmpId]];
    }
}
