<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Readiness, not just liveness.
 *
 * `GET /api/health` answers 200 whenever PHP is serving, so an uptime monitor
 * pointed at it keeps behaving as it always has. The database block below is
 * what tells you whether the product can actually be used — reporting only the
 * former is how a deploy goes green on an app whose every real endpoint
 * answers 503.
 */
final class Health
{
    /** Tables that must exist before any Insights endpoint can work. */
    private const REQUIRED_TABLES = [
        'insights_dashboards',
        'insights_dashboard_widgets',
        'insights_dashboard_versions',
        'insights_dashboard_shares',
        'insights_metric_definitions',
        'insights_report_definitions',
        'insights_anomaly_reviews',
        'insights_user_preferences',
    ];

    /** @return array{configured:bool, reachable:bool, schema:array{ready:bool, missing:list<string>}, error:?string} */
    public static function database(): array
    {
        $configured = Env::get('DB_NAME') !== '' && Env::get('DB_USER') !== '';
        if (!$configured) {
            return [
                'configured' => false,
                'reachable'  => false,
                'schema'     => ['ready' => false, 'missing' => self::REQUIRED_TABLES],
                'error'      => 'DB_NAME / DB_USER are not set in api/.env.',
            ];
        }

        try {
            $rows = Db::all(
                'SELECT table_name FROM information_schema.tables
                  WHERE table_schema = ANY (current_schemas(false)) AND table_name = ANY (:names)',
                ['names' => '{' . implode(',', self::REQUIRED_TABLES) . '}'],
            );
        } catch (\Throwable $e) {
            return [
                'configured' => true,
                'reachable'  => false,
                'schema'     => ['ready' => false, 'missing' => self::REQUIRED_TABLES],
                // The message can carry a host and a user name, never a password.
                'error'      => 'The Insights database is not reachable.',
            ];
        }

        $present = array_map(static fn (array $row) => (string) $row['table_name'], $rows);
        $missing = array_values(array_diff(self::REQUIRED_TABLES, $present));

        return [
            'configured' => true,
            'reachable'  => true,
            'schema'     => ['ready' => $missing === [], 'missing' => $missing],
            'error'      => $missing === [] ? null : 'Run server-php/bin/migrate.php — ' . count($missing) . ' table(s) missing.',
        ];
    }
}
