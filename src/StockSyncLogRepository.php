<?php

declare(strict_types=1);

namespace App;

/**
 * Auditlog van voorraadwijzigingen (wie/wat/waardoor) - zie docs/wholesale.md.
 * Gevuld door WholesaleStockSyncService (fase D, outbound naar Faire/
 * Orderchamp) en later ook door de webhook-handlers (fase E, inbound).
 */
final class StockSyncLogRepository
{
    /**
     * @param array{product_id?: string, platform_id?: string, trigger_type?: string} $filters
     * @return array<int, array<string, mixed>>
     */
    public static function search(array $filters = [], int $limit = 100): array
    {
        [$where, $types, $params] = self::buildWhereClause($filters);

        $types .= 'i';
        $params[] = $limit;

        return Database::fetchAll(
            "SELECT l.*, COALESCE(p.sku, c.sku) AS sku, COALESCE(p.title, c.title) AS title,
                    wp.code AS platform_code, wp.name AS platform_name
             FROM stock_sync_log l
             LEFT JOIN products p ON p.id = l.product_id
             LEFT JOIN cards c ON c.id = l.card_id
             LEFT JOIN wholesale_platforms wp ON wp.id = l.platform_id
             {$where}
             ORDER BY l.created_at DESC
             LIMIT ?",
            $types,
            $params
        );
    }

    /**
     * Schrijft één auditlogregel. Precies één van $productId/$cardId hoort
     * gezet te zijn (of geen van beide voor een niet-productgebonden regel).
     */
    public static function log(
        ?int $productId,
        ?int $cardId,
        ?int $platformId,
        string $direction,
        string $triggerType,
        ?int $oldStock,
        ?int $newStock,
        bool $success,
        bool $dryRun,
        ?string $errorMessage
    ): void {
        Database::run(
            'INSERT INTO stock_sync_log
                (product_id, card_id, platform_id, direction, trigger_type, old_stock, new_stock, success, dry_run, error_message)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'iiissiiiis',
            [
                $productId,
                $cardId,
                $platformId,
                $direction,
                $triggerType,
                $oldStock,
                $newStock,
                $success ? 1 : 0,
                $dryRun ? 1 : 0,
                $errorMessage,
            ]
        );
    }

    /**
     * @param array{product_id?: string, platform_id?: string, trigger_type?: string} $filters
     * @return array{0: string, 1: string, 2: array<int, mixed>}
     */
    private static function buildWhereClause(array $filters): array
    {
        $conditions = [];
        $types = '';
        $params = [];

        $productId = trim((string) ($filters['product_id'] ?? ''));
        if ($productId !== '') {
            $conditions[] = 'l.product_id = ?';
            $types .= 'i';
            $params[] = (int) $productId;
        }

        $platformId = trim((string) ($filters['platform_id'] ?? ''));
        if ($platformId !== '') {
            $conditions[] = 'l.platform_id = ?';
            $types .= 'i';
            $params[] = (int) $platformId;
        }

        $triggerType = trim((string) ($filters['trigger_type'] ?? ''));
        if ($triggerType !== '') {
            $conditions[] = 'l.trigger_type = ?';
            $types .= 's';
            $params[] = $triggerType;
        }

        if ($conditions === []) {
            return ['', '', []];
        }

        return ['WHERE ' . implode(' AND ', $conditions), $types, $params];
    }
}
