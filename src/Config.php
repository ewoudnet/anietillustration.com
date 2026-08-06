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

    /**
     * De echte, live specials-URL zoals klanten hem zien - onafhankelijk van APP_URL (dat
     * per omgeving verschilt: lokaal :8002, later de live submap). Gebruikt door de backend
     * voor "bekijk live"-links, zodat die nooit naar localhost wijzen.
     */
    public static function publicUrl(): string
    {
        return rtrim(self::get('PUBLIC_URL', 'https://aniet.nl/specials') ?? '', '/');
    }
}
