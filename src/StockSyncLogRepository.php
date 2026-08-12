<?php

declare(strict_types=1);

namespace App;

/**
 * Auditlog van voorraadwijzigingen (wie/wat/waardoor) - zie docs/wholesale.md.
 * Wordt gevuld door de nog te bouwen StockSyncService (fase C+); deze
 * repository is er al zodat de log-viewer (backend/wholesale/sync-log.php) nu
 * al tegen de echte tabel kan draaien.
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
            "SELECT l.*, p.sku, p.title, wp.code AS platform_code, wp.name AS platform_name
             FROM stock_sync_log l
             LEFT JOIN products p ON p.id = l.product_id
             LEFT JOIN wholesale_platforms wp ON wp.id = l.platform_id
             {$where}
             ORDER BY l.created_at DESC
             LIMIT ?",
            $types,
            $params
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
