<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth;
use Aicountly\Api\Clients\ManageClient;
use Aicountly\Api\Http;

/**
 * Read-through to Manage for the company switcher.
 *
 * Manage owns the company, the branch and the financial year. Insights stores
 * their ids and nothing else. But a person cannot type an id they do not know,
 * so the switcher has to SHOW them the list — and showing a list is a read, not
 * a copy. Nothing returned here is written down; the next page load asks again.
 *
 * NOT COMPANY-SCOPED, and it cannot be: this is what the caller uses to choose
 * the company. It authenticates the user and passes their own session key
 * through, so Manage returns exactly the companies that person may open — the
 * authorisation is Manage's, made against their identity, not ours.
 *
 * GET only. There is no path here that changes anything in Manage.
 */
final class ManageController extends Controller
{
    public static function companies(): void
    {
        $auth = Auth::require();

        $filters = array_filter([
            'filter'   => Http::param('filter', 'all'),
            'page'     => Http::intParam('page'),
            'per_page' => Http::intParam('per_page'),
            'q'        => Http::param('q'),
        ], static fn ($v) => $v !== null && $v !== '');

        self::relay((new ManageClient())->withSession($auth->sesKey())->companies($filters));
    }

    public static function companyInfo(): void
    {
        $auth = Auth::require();

        $cmpId = Http::intParam('comp_id') ?? Http::intParam('cmp_id');
        if ($cmpId === null || $cmpId <= 0) {
            Http::validationFailed('Which company?', ['field' => 'comp_id']);
        }

        self::relay((new ManageClient())->withSession($auth->sesKey())->companyInfo($cmpId));
    }

    /**
     * Hand Manage's answer to the browser unchanged.
     *
     * Unchanged on purpose: Manage's list envelope has grown several shapes
     * over the years, and the front end already normalises all of them.
     *
     * An unreachable Manage is reported as unreachable. Never as an empty list:
     * a switcher showing "no companies" to someone who has twelve is how people
     * conclude their data is gone.
     *
     * @param array<string, mixed> $response
     */
    private static function relay(array $response): void
    {
        if (!$response['ok']) {
            $status = (int) $response['status'];
            Http::error(
                $status >= 400 && $status < 500 ? $status : 502,
                'manage_unavailable',
                (string) ($response['error'] ?? 'The company list could not be fetched right now.'),
                ['retryable' => true],
            );
        }

        $body = $response['body'] ?? [];
        Http::json(200, is_array($body) ? $body : ['data' => $body]);
    }
}
