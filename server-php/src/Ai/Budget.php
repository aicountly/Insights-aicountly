<?php

declare(strict_types=1);

namespace Aicountly\Api\Ai;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Env;

/**
 * How much AI a company and a person may use.
 *
 * A model call costs money on somebody's account, and a screen that can trigger
 * one is a screen somebody can hold down the refresh key on. Two limits, both
 * counted from what was actually spent rather than from an in-memory counter
 * that resets with the worker:
 *
 *   per user   a short window, so one person cannot monopolise the budget
 *   per company a day, so a tenant's spend is bounded
 *
 * Refused calls are refused BEFORE the provider is dialled, and they are
 * refused with a sentence saying when the limit resets — a bare "rate limited"
 * teaches people to keep clicking.
 *
 * Counted against `insights_ai_usage`, which holds counts and outcomes only.
 */
final class Budget
{
    public const TABLE = 'insights_ai_usage';

    private const DEFAULT_PER_USER_PER_HOUR = 40;
    private const DEFAULT_PER_COMPANY_PER_DAY = 400;

    /**
     * @return array{allowed: bool, reason: ?string, used: array{user_hour:int, company_day:int}, limits: array{user_hour:int, company_day:int}}
     */
    public static function check(Context $ctx, Auth $auth): array
    {
        $limits = [
            'user_hour'   => self::limit('INSIGHTS_AI_USER_HOURLY_LIMIT', self::DEFAULT_PER_USER_PER_HOUR),
            'company_day' => self::limit('INSIGHTS_AI_COMPANY_DAILY_LIMIT', self::DEFAULT_PER_COMPANY_PER_DAY),
        ];

        try {
            $userHour = (int) Db::scalar(
                'SELECT COUNT(*) FROM ' . self::TABLE . '
                  WHERE cmp_id = :cmp AND user_uuid = :uuid
                    AND outcome IN (\'success\', \'error\', \'blocked\')
                    AND created_at > NOW() - INTERVAL \'1 hour\'',
                ['cmp' => $ctx->cmpId, 'uuid' => $auth->uuid],
            );

            $companyDay = (int) Db::scalar(
                'SELECT COUNT(*) FROM ' . self::TABLE . '
                  WHERE cmp_id = :cmp
                    AND outcome IN (\'success\', \'error\', \'blocked\')
                    AND created_at > NOW() - INTERVAL \'1 day\'',
                ['cmp' => $ctx->cmpId],
            );
        } catch (\Throwable $e) {
            // A budget check that cannot run must not become an open door. It
            // also must not lock everybody out of a working feature, so it
            // fails closed for the expensive path only: the caller falls back
            // to the rules-based answer, which costs nothing.
            error_log('[insights-ai] budget check failed: ' . $e->getMessage());

            return [
                'allowed' => false,
                'reason'  => 'The AI usage budget could not be checked, so this answer is rules-based. Nothing is wrong with your data.',
                'used'    => ['user_hour' => 0, 'company_day' => 0],
                'limits'  => $limits,
            ];
        }

        if ($userHour >= $limits['user_hour']) {
            return [
                'allowed' => false,
                'reason'  => 'You have asked Insights ' . $userHour . ' questions in the last hour, which is this deployment\'s limit. It resets as those calls age past the hour.',
                'used'    => ['user_hour' => $userHour, 'company_day' => $companyDay],
                'limits'  => $limits,
            ];
        }

        if ($companyDay >= $limits['company_day']) {
            return [
                'allowed' => false,
                'reason'  => 'This company has used its daily AI allowance of ' . $limits['company_day'] . ' calls. It resets over the next 24 hours.',
                'used'    => ['user_hour' => $userHour, 'company_day' => $companyDay],
                'limits'  => $limits,
            ];
        }

        return [
            'allowed' => true,
            'reason'  => null,
            'used'    => ['user_hour' => $userHour, 'company_day' => $companyDay],
            'limits'  => $limits,
        ];
    }

    /** Record a call that never reached a provider, so it still counts. */
    public static function recordRefusal(Context $ctx, Auth $auth, string $feature, string $code): void
    {
        try {
            Db::insert(self::TABLE, [
                'cmp_id'     => $ctx->cmpId,
                'user_uuid'  => $auth->uuid,
                'feature'    => mb_substr($feature, 0, 60),
                'outcome'    => 'rules_only',
                'error_code' => mb_substr($code, 0, 48),
            ], 'usage_id');
        } catch (\Throwable $e) {
            error_log('[insights-ai] could not record refusal: ' . $e->getMessage());
        }
    }

    private static function limit(string $key, int $default): int
    {
        $configured = (int) Env::get($key, (string) $default);

        return $configured > 0 ? min(5000, $configured) : $default;
    }
}
