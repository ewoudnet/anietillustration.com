<?php

declare(strict_types=1);

namespace App;

/**
 * Gesynchroniseerde Faire/Orderchamp-orders (los van de Mollie-checkout-orders
 * in OrderRepository - zie docs/wholesale.md voor de rationale). Historische
 * import schrijft hier rechtstreeks in en raakt nooit products.current_stock;
 * dat loopt uitsluitend via de nog te bouwen StockSyncService (fase C+).
 */
final class WholesaleOrderRepository
{
    /**
     * @param array{q?: string, platform_id?: string, status?: string, date_from?: string, date_to?: string} $filters
     * @return array<int, array<string, mixed>>
     */
    public static function search(array $filters = []): array
    {
        [$where, $types, $params] = self::buildWhereClause($filters);

        return Database::fetchAll(
            "SELECT wo.*, wp.code AS platform_code, wp.name AS platform_name, wp.color AS platform_color,
                    wp.icon AS platform_icon, s.name AS shop_name
             FROM wholesale_orders wo
             INNER JOIN wholesale_platforms wp ON wp.id = wo.platform_id
             LEFT JOIN shops s ON s.id = wo.shop_id
             {$where}
             ORDER BY wo.placed_at DESC",
            $types,
            $params
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $order = Database::fetchOne(
            'SELECT wo.*, wp.code AS platform_code, wp.name AS platform_name, wp.color AS platform_color,
                    wp.icon AS platform_icon, s.name AS shop_name
             FROM wholesale_orders wo
             INNER JOIN wholesale_platforms wp ON wp.id = wo.platform_id
             LEFT JOIN shops s ON s.id = wo.shop_id
             WHERE wo.id = ?',
            'i',
            [$id]
        );

        if ($order === null) {
            return null;
        }

        $order['items'] = Database::fetchAll(
            'SELECT * FROM wholesale_order_items WHERE wholesale_order_id = ? ORDER BY id ASC',
            'i',
            [$id]
        );

        return $order;
    }

    public static function countUnmatchedSkus(): int
    {
        $row = Database::fetchOne(
            'SELECT COUNT(DISTINCT sku) AS total FROM wholesale_order_items WHERE product_id IS NULL'
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * @param array{q?: string, platform_id?: string, status?: string, date_from?: string, date_to?: string} $filters
     * @return array{0: string, 1: string, 2: array<int, mixed>}
     */
    private static function buildWhereClause(array $filters): array
    {
        $conditions = [];
        $types = '';
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $conditions[] = '(s.name LIKE ? OR wo.external_order_id LIKE ?
                OR EXISTS (SELECT 1 FROM wholesale_order_items woi
                           WHERE woi.wholesale_order_id = wo.id
                             AND (woi.sku LIKE ? OR woi.title_snapshot LIKE ?)))';
            $types .= 'ssss';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $platformId = trim((string) ($filters['platform_id'] ?? ''));
        if ($platformId !== '') {
            $conditions[] = 'wo.platform_id = ?';
            $types .= 'i';
            $params[] = (int) $platformId;
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $conditions[] = 'wo.status = ?';
            $types .= 's';
            $params[] = $status;
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $conditions[] = 'wo.placed_at >= ?';
            $types .= 's';
            $params[] = $dateFrom . ' 00:00:00';
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $conditions[] = 'wo.placed_at <= ?';
            $types .= 's';
            $params[] = $dateTo . ' 23:59:59';
        }

        if ($conditions === []) {
            return ['', '', []];
        }

        return ['WHERE ' . implode(' AND ', $conditions), $types, $params];
    }
}
