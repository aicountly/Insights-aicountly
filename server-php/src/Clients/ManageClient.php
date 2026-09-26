<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

/**
 * Live reads against manage.aicountly.com.
 *
 * Manage owns the company, the branch and the financial year. Insights stores
 * `cmp_id`, `bo_id` and `fy_id` as bare references on its dashboards and
 * nothing else — no company name, no branch master, no financial-year dates.
 * A screen that needs the company's name or the year's start date asks here,
 * on that request.
 *
 * VERIFIED CONTRACT (manage-aicountly/server-php):
 *   GET /api/companyinfo?comp_id=N   company + branches + financial years, and
 *                                    the caller's access_type for it
 *   GET /api/companies?filter&page&per_page&q   companies this session may open
 */
final class ManageClient extends ApiClient
{
    public const LABEL = 'Manage';

    private string $authorization = '';

    public function service(): string
    {
        return 'manage';
    }

    protected function productionBase(): string
    {
        return 'https://manage.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://manage.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'MANAGE_API_BASE';
    }

    public function withSession(string $sesKey): self
    {
        $this->authorization = 'Bearer ' . $sesKey;

        return $this;
    }

    /**
     * Company, its branches and its financial years, in one call.
     *
     * Memoised for the request by ApiClient: the context check runs on every
     * scoped endpoint, and asking per endpoint would put Manage in the hot path
     * of every screen this product draws.
     *
     * @return array{ok:bool, status:int, body:?array, error:?string}
     */
    public function companyInfo(int $cmpId): array
    {
        return $this->request('GET', 'companyinfo' . self::query(['comp_id' => $cmpId]), null, ['Authorization' => $this->authorization]);
    }

    /**
     * Companies this session may open — the company switcher.
     *
     * @param array<string, mixed> $filters
     * @return array{ok:bool, status:int, body:?array, error:?string}
     */
    public function companies(array $filters = []): array
    {
        return $this->request('GET', 'companies' . self::query($filters), null, ['Authorization' => $this->authorization]);
    }
}
