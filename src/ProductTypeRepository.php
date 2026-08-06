<?php

declare(strict_types=1);

namespace App;

final class ProductTypeRepository
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function findAll(): array
    {
        return Database::fetchAll('SELECT * FROM product_types ORDER BY name ASC');
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM product_types WHERE id = ?', 'i', [$id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findByName(string $name): ?array
    {
        return Database::fetchOne('SELECT * FROM product_types WHERE name = ?', 's', [$name]);
    }

    public static function nameExists(string $name, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $row = Database::fetchOne(
                'SELECT id FROM product_types WHERE name = ? AND id != ? LIMIT 1',
                'si',
                [$name, $excludeId]
            );
        } else {
            $row = Database::fetchOne('SELECT id FROM product_types WHERE name = ? LIMIT 1', 's', [$name]);
        }

        return $row !== null;
    }

    public static function create(string $name, ?string $comments): int
    {
        return Database::insert(
            'INSERT INTO product_types (name, comments) VALUES (?, ?)',
            'ss',
            [$name, $comments]
        );
    }

    public static function update(int $id, string $name, ?string $comments): void
    {
        Database::run(
            'UPDATE product_types SET name = ?, comments = ? WHERE id = ?',
            'ssi',
            [$name, $comments, $id]
        );
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM product_types WHERE id = ?', 'i', [$id]);
    }
}
