<?php

declare(strict_types=1);

namespace App;

/**
 * Lookup van wholesale-platformen (Faire, Orderchamp), geseed via
 * sql/migrations/005_wholesale_tables.sql. sync_enabled is de kill switch per
 * platform: zolang 0, wordt er alleen gelezen/gelogd, nooit teruggeschreven.
 */
final class WholesalePlatformRepository
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function findAll(): array
    {
        return Database::fetchAll('SELECT * FROM wholesale_platforms ORDER BY name ASC');
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM wholesale_platforms WHERE id = ?', 'i', [$id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findByCode(string $code): ?array
    {
        return Database::fetchOne('SELECT * FROM wholesale_platforms WHERE code = ?', 's', [$code]);
    }

    public static function setSyncEnabled(int $id, bool $enabled): void
    {
        Database::run('UPDATE wholesale_platforms SET sync_enabled = ? WHERE id = ?', 'ii', [$enabled ? 1 : 0, $id]);
    }
}
