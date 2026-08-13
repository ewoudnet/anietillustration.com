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

    /**
     * Haalt een bestaande order op vóór een upsert, zodat de aanroeper de
     * vorige status/stock_deducted_at kan vergelijken met de nieuwe (fase E,
     * zie WholesaleStockDeductionService) - anders is na de upsert niet meer
     * te zien of dit een nieuwe order of een statuswijziging was.
     *
     * @return array<string, mixed>|null
     */
    public static function findByPlatformAndExternalId(int $platformId, string $externalOrderId): ?array
    {
        return Database::fetchOne(
            'SELECT * FROM wholesale_orders WHERE platform_id = ? AND external_order_id = ?',
            'is',
            [$platformId, $externalOrderId]
        );
    }

    public static function setStockDeductedAt(int $id, ?string $datetime): void
    {
        Database::run('UPDATE wholesale_orders SET stock_deducted_at = ? WHERE id = ?', 'si', [$datetime, $id]);
    }

    /**
     * Alle SKU's uit orderregels die niet aan een product/kaart gekoppeld konden
     * worden, gegroepeerd per SKU - voedt backend/wholesale/unmatched-skus.php.
     * Zwaarste bovenaan (meest bestelde), want dat zijn de SKU's waar het
     * ontbreken van een product de meeste voorraadafwijking oplevert.
     *
     * De titel is MAX() en dus "een van de gebruikte titels": Faire/Orderchamp
     * leveren de titel zoals die op het moment van bestellen was, en die kan per
     * order verschillen voor dezelfde SKU.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function unmatchedSkuSummary(): array
    {
        return Database::fetchAll(
            "SELECT woi.sku,
                    MAX(woi.title_snapshot) AS title_snapshot,
                    COUNT(*) AS line_count,
                    SUM(woi.quantity) AS total_quantity,
                    SUM(CASE WHEN wo.status = 'canceled' THEN woi.quantity ELSE 0 END) AS canceled_quantity,
                    MAX(wo.placed_at) AS last_ordered_at,
                    GROUP_CONCAT(DISTINCT wp.name ORDER BY wp.name SEPARATOR ', ') AS platforms
             FROM wholesale_order_items woi
             INNER JOIN wholesale_orders wo ON wo.id = woi.wholesale_order_id
             INNER JOIN wholesale_platforms wp ON wp.id = wo.platform_id
             WHERE woi.product_id IS NULL AND woi.card_id IS NULL
             GROUP BY woi.sku
             ORDER BY total_quantity DESC, woi.sku ASC"
        );
    }

    public static function countUnmatchedSkus(): int
    {
        $row = Database::fetchOne(
            'SELECT COUNT(DISTINCT sku) AS total FROM wholesale_order_items
             WHERE product_id IS NULL AND card_id IS NULL'
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Maakt een order aan of werkt hem bij (op platform_id + external_order_id),
     * en geeft altijd het (bestaande of nieuwe) id terug - gebruikt door
     * WholesaleOrderImporter. Nooit aangeroepen buiten import/sync-code, en
     * raakt zelf nooit products.current_stock/cards.current_stock.
     *
     * @param array{platform_id: int, external_order_id: string, shop_id: ?int, status: string, placed_at: string, currency: string, total_amount_cents: int, canceled_at: ?string, raw_payload: array<string, mixed>} $data
     */
    public static function upsert(array $data): int
    {
        return Database::insert(
            'INSERT INTO wholesale_orders
                (platform_id, external_order_id, shop_id, status, placed_at, currency, total_amount_cents, canceled_at, raw_payload)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                shop_id = VALUES(shop_id),
                status = VALUES(status),
                placed_at = VALUES(placed_at),
                currency = VALUES(currency),
                total_amount_cents = VALUES(total_amount_cents),
                canceled_at = VALUES(canceled_at),
                raw_payload = VALUES(raw_payload)',
            'isisssiss',
            [
                $data['platform_id'],
                $data['external_order_id'],
                $data['shop_id'],
                $data['status'],
                $data['placed_at'],
                $data['currency'],
                $data['total_amount_cents'],
                $data['canceled_at'],
                json_encode($data['raw_payload'], JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    /**
     * Vervangt alle regels van een order door de gegeven set - eenvoudiger en
     * veiliger dan proberen te diffen tegen de vorige import, en Faire/
     * Orderchamp leveren toch altijd de volledige orderinhoud. Raakt nooit
     * voorraad.
     *
     * @param array<int, array{sku: string, title_snapshot: string, quantity: int, unit_price_cents: int, product_id: ?int, card_id: ?int}> $items
     */
    public static function replaceItems(int $wholesaleOrderId, array $items): void
    {
        Database::transaction(static function () use ($wholesaleOrderId, $items): void {
            Database::run('DELETE FROM wholesale_order_items WHERE wholesale_order_id = ?', 'i', [$wholesaleOrderId]);

            foreach ($items as $item) {
                Database::run(
                    'INSERT INTO wholesale_order_items
                        (wholesale_order_id, product_id, card_id, sku, title_snapshot, quantity, unit_price_cents)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    'iiissii',
                    [
                        $wholesaleOrderId,
                        $item['product_id'],
                        $item['card_id'],
                        $item['sku'],
                        $item['title_snapshot'],
                        $item['quantity'],
                        $item['unit_price_cents'],
                    ]
                );
            }
        });
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
