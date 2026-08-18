<?php

declare(strict_types=1);

namespace App;

/**
 * Fase D: voorraad SCHRIJVEN naar Faire/Orderchamp - de omgekeerde richting
 * van WholesaleStockChecker (fase C, alleen lezen/vergelijken). Werkt uit-
 * sluitend op basis van de laatst bekende platformvoorraad uit
 * product_platform_listings (dus: laat eerst WholesaleStockChecker draaien),
 * en alleen voor items die daar als "geplaatst" bekend staan met een
 * afwijkende voorraad - een niet-geplaatste SKU kan niet bijgewerkt worden.
 *
 * Blijft een proefdraai (loggen zonder te posten) zolang
 * wholesale_platforms.sync_enabled op 0 staat voor dat platform - pas bij 1
 * wordt er echt naar de externe API geschreven. Zie docs/wholesale.md.
 */
final class WholesaleStockSyncService
{
    /**
     * @return array<string, array{synced: int, error: ?string, dryRun: bool}>
     */
    public static function run(): array
    {
        $result = [];
        foreach (['faire' => FaireService::class, 'orderchamp' => OrderchampService::class] as $code => $serviceClass) {
            $platform = WholesalePlatformRepository::findByCode($code);
            if ($platform === null) {
                continue;
            }

            $platformId = (int) $platform['id'];
            $dryRun = (int) $platform['sync_enabled'] !== 1;
            $items = self::gatherDiscrepancies($code);

            if ($items === []) {
                $result[$code] = ['synced' => 0, 'error' => null, 'dryRun' => $dryRun];
                continue;
            }

            try {
                if (!$dryRun) {
                    if (!$serviceClass::isConfigured()) {
                        throw new \RuntimeException(ucfirst($code) . '-credentials zijn nog niet ingesteld in .env.');
                    }

                    // `current_stock` is al verminderd met toegewezen open orders
                    // (WholesaleStockDeductionService trekt af zodra een order
                    // gezien wordt, niet pas bij verzending) - het platform-veld
                    // dat we hier schrijven (Faire's on_hand_quantity, Orderchamp's
                    // SET-adjustment) verwacht juist het RUWE fysieke aantal, dus
                    // het toegewezen bedrag moet er weer bij vóór het versturen.
                    // Zonder dit trekt het platform diezelfde orders een tweede
                    // keer af (zie docs/wholesale.md).
                    $committed = WholesaleOrderRepository::committedQuantityByItem($platformId);
                    $skuToQuantity = [];
                    foreach ($items as $item) {
                        $key = $item['type'] . ':' . $item['id'];
                        $skuToQuantity[$item['sku']] = $item['current_stock'] + ($committed[$key] ?? 0);
                    }
                    $serviceClass::updateInventoryBySkus($skuToQuantity);
                }

                foreach ($items as $item) {
                    self::log($item, $platformId, $dryRun, true, null);

                    if (!$dryRun) {
                        ProductPlatformListingRepository::upsert(
                            $item['type'] === 'product' ? $item['id'] : null,
                            $item['type'] === 'card' ? $item['id'] : null,
                            $platformId,
                            $item['sku'],
                            true,
                            $item['current_stock']
                        );
                    }
                }

                $result[$code] = ['synced' => count($items), 'error' => null, 'dryRun' => $dryRun];
            } catch (\RuntimeException $e) {
                foreach ($items as $item) {
                    self::log($item, $platformId, $dryRun, false, $e->getMessage());
                }
                $result[$code] = ['synced' => 0, 'error' => $e->getMessage(), 'dryRun' => $dryRun];
            }
        }

        return $result;
    }

    /**
     * @return array<int, array{type: 'product'|'card', id: int, sku: string, current_stock: int, platform_stock: int}>
     */
    private static function gatherDiscrepancies(string $platformCode): array
    {
        $listingsByProduct = ProductPlatformListingRepository::allGroupedByProduct();
        $listingsByCard = ProductPlatformListingRepository::allGroupedByCard();

        $items = [];
        foreach (ProductRepository::findAllWithTypeName() as $product) {
            self::collect(
                $items,
                'product',
                (int) $product['id'],
                (string) $product['sku'],
                (int) $product['current_stock'],
                $listingsByProduct[(int) $product['id']][$platformCode] ?? null
            );
        }
        foreach (CardRepository::findWholesaleOnly() as $card) {
            self::collect(
                $items,
                'card',
                (int) $card['id'],
                (string) $card['sku'],
                (int) $card['current_stock'],
                $listingsByCard[(int) $card['id']][$platformCode] ?? null
            );
        }

        return $items;
    }

    /**
     * @param array<int, array{type: 'product'|'card', id: int, sku: string, current_stock: int, platform_stock: int}> $items
     */
    private static function collect(array &$items, string $type, int $id, string $sku, int $currentStock, ?array $listing): void
    {
        if ($sku === '' || $listing === null || (int) $listing['is_listed'] !== 1) {
            return;
        }
        if ($listing['last_seen_stock'] === null || (int) $listing['last_seen_stock'] === $currentStock) {
            return;
        }

        $items[] = [
            'type' => $type,
            'id' => $id,
            'sku' => $sku,
            'current_stock' => $currentStock,
            'platform_stock' => (int) $listing['last_seen_stock'],
        ];
    }

    /**
     * @param array{type: 'product'|'card', id: int, sku: string, current_stock: int, platform_stock: int} $item
     */
    private static function log(array $item, int $platformId, bool $dryRun, bool $success, ?string $errorMessage): void
    {
        StockSyncLogRepository::log(
            $item['type'] === 'product' ? $item['id'] : null,
            $item['type'] === 'card' ? $item['id'] : null,
            $platformId,
            'outbound',
            'reconciliation',
            $item['platform_stock'],
            $item['current_stock'],
            $success,
            $dryRun,
            $errorMessage
        );
    }
}
