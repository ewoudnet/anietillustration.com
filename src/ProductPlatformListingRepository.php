<?php

declare(strict_types=1);

namespace App;

/**
 * Koppeling product/kaart <-> wholesale-platform (is dit item op Faire/
 * Orderchamp geplaatst, onder welke externe SKU, en wat was de laatst geziene
 * voorraad). Voedt het SKU/sales-channel-vergelijkingsoverzicht
 * (backend/wholesale/sku-comparison.php). Precies één van product_id/card_id
 * is gezet per rij, zie sql/migrations/006_wholesale_card_support.sql.
 */
final class ProductPlatformListingRepository
{
    /**
     * @return array<int, array<string, array<string, mixed>>> product_id => [platform_code => listing]
     */
    public static function allGroupedByProduct(): array
    {
        return self::grouped('product_id');
    }

    /**
     * @return array<int, array<string, array<string, mixed>>> card_id => [platform_code => listing]
     */
    public static function allGroupedByCard(): array
    {
        return self::grouped('card_id');
    }

    /**
     * @return array<int, array<string, array<string, mixed>>>
     */
    private static function grouped(string $keyColumn): array
    {
        $rows = Database::fetchAll(
            "SELECT ppl.*, wp.code AS platform_code
             FROM product_platform_listings ppl
             INNER JOIN wholesale_platforms wp ON wp.id = ppl.platform_id
             WHERE ppl.{$keyColumn} IS NOT NULL"
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row[$keyColumn]][$row['platform_code']] = $row;
        }

        return $grouped;
    }

    public static function countListed(int $platformId): int
    {
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS total FROM product_platform_listings WHERE platform_id = ? AND is_listed = 1',
            'i',
            [$platformId]
        );

        return (int) ($row['total'] ?? 0);
    }
}
