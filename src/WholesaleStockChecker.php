<?php

declare(strict_types=1);

namespace App;

/**
 * Fase C: voorraad LEZEN bij Faire/Orderchamp en vergelijken met de eigen
 * voorraad - schrijft uitsluitend naar product_platform_listings, nooit naar
 * products.current_stock/cards.current_stock. Voedt het
 * SKU/sales-channel-vergelijkingsoverzicht (backend/wholesale/sku-comparison.php).
 * Voorraad daadwerkelijk terugschrijven naar de platformen is fase D.
 */
final class WholesaleStockChecker
{
    /**
     * @return array<string, array{checked: int, listed: int, error: ?string}> platform-code => resultaat
     */
    public static function run(): array
    {
        $items = self::gatherLocalItems();
        $skus = array_column($items, 'sku');

        $result = [];
        foreach (['faire' => FaireService::class, 'orderchamp' => OrderchampService::class] as $code => $serviceClass) {
            $platform = WholesalePlatformRepository::findByCode($code);
            if ($platform === null) {
                continue;
            }

            try {
                if (!$serviceClass::isConfigured()) {
                    throw new \RuntimeException(ucfirst($code) . '-credentials zijn nog niet ingesteld in .env.');
                }

                $inventory = $serviceClass::fetchInventoryBySkus($skus);
                $listedCount = 0;

                foreach ($items as $item) {
                    $isListed = array_key_exists($item['sku'], $inventory);
                    ProductPlatformListingRepository::upsert(
                        $item['type'] === 'product' ? $item['id'] : null,
                        $item['type'] === 'card' ? $item['id'] : null,
                        (int) $platform['id'],
                        $item['sku'],
                        $isListed,
                        $inventory[$item['sku']] ?? null
                    );

                    if ($isListed) {
                        $listedCount++;
                    }
                }

                $result[$code] = ['checked' => count($items), 'listed' => $listedCount, 'error' => null];
            } catch (\RuntimeException $e) {
                $result[$code] = ['checked' => 0, 'listed' => 0, 'error' => $e->getMessage()];
            }
        }

        return $result;
    }

    /**
     * @return array<int, array{type: 'product'|'card', id: int, sku: string}>
     */
    private static function gatherLocalItems(): array
    {
        $items = [];
        foreach (ProductRepository::findAllWithTypeName() as $product) {
            if ($product['sku'] !== '') {
                $items[] = ['type' => 'product', 'id' => (int) $product['id'], 'sku' => $product['sku']];
            }
        }
        foreach (CardRepository::findWholesaleOnly() as $card) {
            if ($card['sku'] !== '') {
                $items[] = ['type' => 'card', 'id' => (int) $card['id'], 'sku' => $card['sku']];
            }
        }

        return $items;
    }
}
