<?php

declare(strict_types=1);

namespace App;

/**
 * Voorraadsimulatie (backend/wholesale/simulatie.php) - laat zien wat de
 * sync ZOU doen, zonder ooit iets te versturen. Bedoeld als observatieperiode
 * ("doen alsof") na het voorraadcorruptie-incident van 2026-08-18: Faire-
 * voorraad lezen, orders laten binnenkomen, lokale voorraad herberekenen -
 * allemaal al bestaande, veilige leesalleen-paden - en hier naast elkaar
 * tonen zodat vertrouwen kan opbouwen vóór `sync_enabled` ooit weer aan gaat.
 *
 * Roept NERGENS updateInventoryBySkus() aan en raakt nooit products/cards
 * of product_platform_listings - puur berekenen en tonen.
 */
final class WholesaleStockSimulationService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function run(): array
    {
        $items = self::gatherLocalItems();
        $skus = array_column($items, 'sku');

        $fairePlatform = WholesalePlatformRepository::findByCode('faire');
        $orderchampPlatform = WholesalePlatformRepository::findByCode('orderchamp');

        $faireAvailable = ($fairePlatform !== null && FaireService::isConfigured())
            ? FaireService::fetchInventoryBySkus($skus)
            : [];
        $orderchampFull = ($orderchampPlatform !== null && OrderchampService::isConfigured())
            ? OrderchampService::fetchFullInventoryBySkus($skus)
            : [];

        $faireCommitted = $fairePlatform !== null
            ? WholesaleOrderRepository::committedQuantityByItem((int) $fairePlatform['id'])
            : [];
        $orderchampCommitted = $orderchampPlatform !== null
            ? WholesaleOrderRepository::committedQuantityByItem((int) $orderchampPlatform['id'])
            : [];

        $rows = [];
        foreach ($items as $item) {
            $key = $item['type'] . ':' . $item['id'];
            $sku = $item['sku'];

            $faireCommittedQty = $faireCommitted[$key] ?? 0;
            $orderchampCommittedQty = $orderchampCommitted[$key] ?? 0;

            $rows[] = [
                'type' => $item['type'],
                'id' => $item['id'],
                'sku' => $sku,
                'title' => $item['title'],
                'image_path' => $item['image_path'],
                'current_stock' => $item['current_stock'],
                'faire' => [
                    // Alleen `available_quantity` is bevestigd leesbaar bij Faire
                    // (zie FaireService-docblock) - een "echt on-hand"-vergelijking
                    // is daarom niet mogelijk, alleen deze beschikbaar-vergelijking.
                    'live_available' => $faireAvailable[$sku] ?? null,
                    'committed' => $faireCommittedQty,
                    'simulated_on_hand' => $item['current_stock'] + $faireCommittedQty,
                    'matches' => array_key_exists($sku, $faireAvailable)
                        ? $faireAvailable[$sku] === $item['current_stock']
                        : null,
                ],
                'orderchamp' => [
                    'live_on_hand' => $orderchampFull[$sku]['onHand'] ?? null,
                    'live_available' => $orderchampFull[$sku]['available'] ?? null,
                    'committed' => $orderchampCommittedQty,
                    'simulated_on_hand' => $item['current_stock'] + $orderchampCommittedQty,
                    'matches' => isset($orderchampFull[$sku]['available'])
                        ? $orderchampFull[$sku]['available'] === $item['current_stock']
                        : null,
                ],
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array{type: 'product'|'card', id: int, sku: string, title: string, image_path: ?string, current_stock: int}>
     */
    private static function gatherLocalItems(): array
    {
        $items = [];
        foreach (ProductRepository::findAllWithTypeName() as $product) {
            if ($product['sku'] === '') {
                continue;
            }
            $items[] = [
                'type' => 'product',
                'id' => (int) $product['id'],
                'sku' => $product['sku'],
                'title' => $product['title'],
                'image_path' => $product['image_path'] ?? null,
                'current_stock' => (int) ($product['current_stock'] ?? 0),
            ];
        }
        foreach (CardRepository::findWholesaleOnly() as $card) {
            if ($card['sku'] === '') {
                continue;
            }
            $items[] = [
                'type' => 'card',
                'id' => (int) $card['id'],
                'sku' => $card['sku'],
                'title' => $card['title'],
                'image_path' => $card['image_path'] ?? null,
                'current_stock' => (int) ($card['current_stock'] ?? 0),
            ];
        }

        return $items;
    }
}
