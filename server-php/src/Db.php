<?php

declare(strict_types=1);

namespace Aicountly\Api;

use PDO;
use PDOException;
use PDOStatement;

/**
 * PostgreSQL connection for this product's OWN database.
 *
 * It reaches exactly one schema: the tables Insights owns — dashboards, widget
 * layouts, templates, custom metric definitions, shares, revisions, report
 * definitions, anomaly review metadata, preferences and a little audit. It is
 * NEVER pointed at Books, Inventory, Sales or Manage.
 *
 * That is the whole architecture in one sentence: Insights stores its own
 * configuration and reads every business figure live over HTTP. A warehouse
 * filled by copying other products' transactions would give this product a
 * second, quietly diverging answer to questions those products already answer,
 * and something would then have to reconcile them.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function connect(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = Env::get('DB_HOST', '127.0.0.1');
        $port = Env::get('DB_PORT', '5432');
        $name = Env::get('DB_NAME');
        $user = Env::get('DB_USER');
        $pass = Env::get('DB_PASS');

        if ($name === '' || $user === '') {
            throw new PDOException('Database is not configured (DB_NAME / DB_USER missing from api/.env).');
        }

        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name);

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real prepares. Emulation would send NUMERIC(18,4) as a string
            // literal and let PostgreSQL guess the type.
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $schema = Env::get('DB_SCHEMA');
        if ($schema !== '') {
            $pdo->exec('SET search_path TO ' . self::quoteIdentifier($schema) . ', public');
        }

        return self::$pdo = $pdo;
    }

    /** Drop the connection. Tests only, between fixtures. */
    public static function reset(): void
    {
        self::$pdo = null;
    }

    /** @param array<string|int, mixed> $params */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute(self::bindable($params));

        return $stmt;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public static function first(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return list<array<string, mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** @param array<string|int, mixed> $params */
    public static function scalar(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * INSERT ... RETURNING, so the generated key comes back on the same round trip.
     *
     * @param array<string, mixed> $values
     */
    public static function insert(string $table, array $values, string $returning = 'id'): mixed
    {
        $columns = array_keys($values);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) RETURNING %s',
            self::quoteIdentifier($table),
            implode(', ', array_map([self::class, 'quoteIdentifier'], $columns)),
            implode(', ', array_map(static fn (string $c) => ':' . $c, $columns)),
            self::quoteIdentifier($returning),
        );

        return self::run($sql, $values)->fetchColumn();
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $where
     */
    public static function update(string $table, array $values, array $where): int
    {
        if ($values === [] || $where === []) {
            return 0;
        }

        $set = [];
        $params = [];
        foreach ($values as $column => $value) {
            $set[] = self::quoteIdentifier($column) . ' = :set_' . $column;
            $params['set_' . $column] = $value;
        }

        $conditions = [];
        foreach ($where as $column => $value) {
            $conditions[] = self::quoteIdentifier($column) . ' = :where_' . $column;
            $params['where_' . $column] = $value;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            self::quoteIdentifier($table),
            implode(', ', $set),
            implode(' AND ', $conditions),
        );

        return self::run($sql, $params)->rowCount();
    }

    /** @param array<string, mixed> $where */
    public static function delete(string $table, array $where): int
    {
        if ($where === []) {
            // A DELETE with no predicate is never what was meant.
            throw new PDOException('Refusing an unfiltered DELETE on ' . $table . '.');
        }

        $conditions = [];
        $params = [];
        foreach ($where as $column => $value) {
            $conditions[] = self::quoteIdentifier($column) . ' = :' . $column;
            $params[$column] = $value;
        }

        return self::run(
            sprintf('DELETE FROM %s WHERE %s', self::quoteIdentifier($table), implode(' AND ', $conditions)),
            $params,
        )->rowCount();
    }

    /**
     * Run a closure inside one transaction.
     *
     * Save-and-publish writes a dashboard, its widgets and a revision row; a
     * half-written dashboard is a dashboard nobody can open.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public static function transaction(callable $work): mixed
    {
        $pdo = self::connect();
        $owned = !$pdo->inTransaction();
        if ($owned) {
            $pdo->beginTransaction();
        }

        try {
            $result = $work();
            if ($owned) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($owned && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Decode a JSONB column into an array.
     *
     * PostgreSQL hands JSONB back as text through PDO; every caller doing its
     * own json_decode is every caller having its own opinion about what a
     * malformed value means.
     *
     * @return array<string|int, mixed>
     */
    public static function jsonColumn(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** Encode a value for a JSONB column. */
    public static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Bind-safe values: booleans as PostgreSQL literals, arrays as JSON.
     *
     * @param array<string|int, mixed> $values
     * @return array<string|int, mixed>
     */
    private static function bindable(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (is_bool($value)) {
                $out[$key] = $value ? 'true' : 'false';
                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::json($value);
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    public static function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
