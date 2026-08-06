<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

final class SpecialsDatabase
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        Config::load();

        $host = Config::get('DB_HOST', 'localhost');
        $name = Config::get('DB_NAME');
        $user = Config::get('DB_USER');
        $pass = Config::get('DB_PASS', '');

        $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";

        try {
            self::$connection = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Emulated prepares (net als mysqli) omzeilt ENUM-buggy driver/PHP-combinaties
                // op sommige hosting en is met onze parameterized queries nog steeds veilig.
                PDO::ATTR_EMULATE_PREPARES => true,
            ]);
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw new PDOException('Kan geen verbinding maken met de database.');
        }

        return self::$connection;
    }
}
