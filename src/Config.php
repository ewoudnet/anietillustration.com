<?php

declare(strict_types=1);

namespace App;

use Dotenv\Dotenv;

final class Config
{
    private static bool $loaded = false;

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        $root = dirname(__DIR__);
        if (file_exists($root . '/.env')) {
            Dotenv::createImmutable($root)->load();
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        self::load();
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return $value === false || $value === null || $value === '' ? $default : (string) $value;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return $value === null ? $default : (int) $value;
    }

    public static function appUrl(): string
    {
        return rtrim(self::get('APP_URL', 'http://localhost:8000') ?? '', '/');
    }
}
