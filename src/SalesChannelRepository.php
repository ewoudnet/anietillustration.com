<?php

declare(strict_types=1);

namespace App;

final class SalesChannelRepository
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function findAll(): array
    {
        return self::attachProductTypes(
            Database::fetchAll('SELECT * FROM sales_channels ORDER BY sort_order ASC, name ASC')
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $channel = Database::fetchOne('SELECT * FROM sales_channels WHERE id = ?', 'i', [$id]);

        if ($channel === null) {
            return null;
        }

        $channel['product_type_ids'] = array_map(
            static fn (array $row): int => (int) $row['product_type_id'],
            Database::fetchAll(
                'SELECT product_type_id FROM sales_channel_product_types WHERE sales_channel_id = ?',
                'i',
                [$id]
            )
        );

        return $channel;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findByName(string $name): ?array
    {
        $channel = Database::fetchOne('SELECT * FROM sales_channels WHERE name = ?', 's', [$name]);

        if ($channel === null) {
            return null;
        }

        $channel['product_type_ids'] = array_map(
            static fn (array $row): int => (int) $row['product_type_id'],
            Database::fetchAll(
                'SELECT product_type_id FROM sales_channel_product_types WHERE sales_channel_id = ?',
                'i',
                [(int) $channel['id']]
            )
        );

        return $channel;
    }

    public static function nameExists(string $name, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $row = Database::fetchOne(
                'SELECT id FROM sales_channels WHERE name = ? AND id != ? LIMIT 1',
                'si',
                [$name, $excludeId]
            );
        } else {
            $row = Database::fetchOne('SELECT id FROM sales_channels WHERE name = ? LIMIT 1', 's', [$name]);
        }

        return $row !== null;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, int> $productTypeIds
     */
    public static function create(array $data, array $productTypeIds): int
    {
        return Database::transaction(static function () use ($data, $productTypeIds): int {
            $id = Database::insert(
                'INSERT INTO sales_channels (name, abbreviation, color, sort_order, logo_path, comments, active)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                'sssissi',
                [
                    $data['name'],
                    $data['abbreviation'],
                    $data['color'],
                    $data['sort_order'],
                    $data['logo_path'],
                    $data['comments'],
                    $data['active'],
                ]
            );

            self::syncProductTypes($id, $productTypeIds);

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, int> $productTypeIds
     */
    public static function update(int $id, array $data, array $productTypeIds): void
    {
        Database::transaction(static function () use ($id, $data, $productTypeIds): void {
            Database::run(
                'UPDATE sales_channels SET
                    name = ?, abbreviation = ?, color = ?, sort_order = ?, logo_path = ?, comments = ?, active = ?
                 WHERE id = ?',
                'sssissii',
                [
                    $data['name'],
                    $data['abbreviation'],
                    $data['color'],
                    $data['sort_order'],
                    $data['logo_path'],
                    $data['comments'],
                    $data['active'],
                    $id,
                ]
            );

            self::syncProductTypes($id, $productTypeIds);
        });
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM sales_channels WHERE id = ?', 'i', [$id]);
    }

    /**
     * @param array<int, int> $productTypeIds
     */
    private static function syncProductTypes(int $channelId, array $productTypeIds): void
    {
        Database::run('DELETE FROM sales_channel_product_types WHERE sales_channel_id = ?', 'i', [$channelId]);

        foreach (array_unique(array_map('intval', $productTypeIds)) as $productTypeId) {
            Database::run(
                'INSERT INTO sales_channel_product_types (sales_channel_id, product_type_id) VALUES (?, ?)',
                'ii',
                [$channelId, $productTypeId]
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $channels
     * @return array<int, array<string, mixed>>
     */
    private static function attachProductTypes(array $channels): array
    {
        if ($channels === []) {
            return [];
        }

        $ids = array_map(static fn (array $c): int => (int) $c['id'], $channels);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));

        $rows = Database::fetchAll(
            "SELECT scpt.sales_channel_id AS channel_id, pt.name AS name
             FROM sales_channel_product_types scpt
             INNER JOIN product_types pt ON pt.id = scpt.product_type_id
             WHERE scpt.sales_channel_id IN ({$placeholders})
             ORDER BY pt.name ASC",
            $types,
            $ids
        );

        $byChannel = [];
        foreach ($rows as $row) {
            $byChannel[(int) $row['channel_id']][] = $row['name'];
        }

        foreach ($channels as &$channel) {
            $channel['product_type_names'] = $byChannel[(int) $channel['id']] ?? [];
        }
        unset($channel);

        return $channels;
    }
}
