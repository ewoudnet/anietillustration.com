<?php

declare(strict_types=1);

namespace App;

/**
 * Koppeling product <-> wholesale-platform (is dit product op Faire/Orderchamp
 * geplaatst, onder welke externe SKU, en wat was de laatst geziene voorraad).
 * Voedt het SKU/sales-channel-vergelijkingsoverzicht (backend/wholesale/sku-comparison.php).
 */
final class ProductPlatformListingRepository
{
    /**
     * Alle listings, gegroepeerd per product_id en dan per platform-code, zodat
     * de comparison-pagina eenvoudig products x platformen kan tonen.
     *
     * @return array<int, array<string, array<string, mixed>>> product_id => [platform_code => listing]
     */
    public static function allGroupedByProduct(): array
    {
        $rows = Database::fetchAll(
            'SELECT ppl.*, wp.code AS platform_code
             FROM product_platform_listings ppl
             INNER JOIN wholesale_platforms wp ON wp.id = ppl.platform_id'
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['product_id']][$row['platform_code']] = $row;
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
