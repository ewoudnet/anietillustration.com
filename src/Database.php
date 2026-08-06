<?php

declare(strict_types=1);

namespace App;

use mysqli;
use mysqli_sql_exception;
use mysqli_stmt;

/**
 * Backoffice-database (Aniet Illustration + Settings, tabellen users/sections/cards/
 * products/...) - zit in dezelfde live database als SpecialsDatabase (Specials, PDO;
 * tabelnamen botsen niet), dus dezelfde DB_*-env-keys. Twee losse connecties/drivers
 * (mysqli hier, PDO in SpecialsDatabase) omdat de geporte backoffice-code overal op
 * mysqli's bind_param-API is gebouwd - geen aparte database nodig.
 */
final class Database
{
    private static ?mysqli $connection = null;

    public static function connection(): mysqli
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        Config::load();

        $host = Config::get('DB_HOST', 'localhost');
        $name = Config::get('DB_NAME');
        $user = Config::get('DB_USER');
        $pass = Config::get('DB_PASS', '') ?? '';

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            $connection = new mysqli((string) $host, (string) $user, (string) $pass, (string) $name);
            $connection->set_charset('utf8mb4');
        } catch (mysqli_sql_exception $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw new mysqli_sql_exception('Kan geen verbinding maken met de database.');
        }

        self::$connection = $connection;

        return self::$connection;
    }

    /**
     * @param array<int, mixed> $params
     */
    public static function run(string $sql, string $types = '', array $params = []): mysqli_stmt
    {
        $stmt = self::connection()->prepare($sql);

        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();

        return $stmt;
    }

    /**
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public static function fetchAll(string $sql, string $types = '', array $params = []): array
    {
        $result = self::run($sql, $types, $params)->get_result();

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * @param array<int, mixed> $params
     * @return array<string, mixed>|null
     */
    public static function fetchOne(string $sql, string $types = '', array $params = []): ?array
    {
        $row = self::run($sql, $types, $params)->get_result()->fetch_assoc();

        return $row ?? null;
    }

    /**
     * Voert een INSERT uit en geeft het nieuwe auto-increment-id terug.
     *
     * @param array<int, mixed> $params
     */
    public static function insert(string $sql, string $types, array $params): int
    {
        self::run($sql, $types, $params);

        return (int) self::connection()->insert_id;
    }

    /**
     * @param array<int, mixed> $params
     */
    public static function affectedRows(string $sql, string $types, array $params): int
    {
        return self::run($sql, $types, $params)->affected_rows;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $connection = self::connection();
        $connection->begin_transaction();

        try {
            $result = $callback();
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $connection->rollback();
            throw $e;
        }
    }
}
