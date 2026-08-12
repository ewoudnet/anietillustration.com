<?php

declare(strict_types=1);

namespace App;

/**
 * Haalt orders op bij Faire/Orderchamp en schrijft ze naar wholesale_orders/
 * wholesale_order_items/shops. Gebruikt voor zowel de eenmalige historische
 * import (fase B) als - later - de nieuwe-order-webhooks (fase E), vandaar
 * de scheiding tussen "haal een pagina op" (FaireService/OrderchampService)
 * en "normaliseer + schrijf weg" (hier).
 *
 * Belangrijk: dit raakt NOOIT products.current_stock of cards.current_stock.
 * Voorraadaanpassing loopt uitsluitend via de nog te bouwen StockSyncService
 * (fase C+) en wordt bewust hier niet aangeroepen - zie docs/wholesale.md.
 */
final class WholesaleOrderImporter
{
    private const FAIRE_STATE_MAP = [
        'NEW' => 'open',
        'PENDING_RETAILER_CONFIRMATION' => 'open',
        'PROCESSING' => 'confirmed',
        'BACKORDERED' => 'confirmed',
        'PRE_TRANSIT' => 'shipped',
        'IN_TRANSIT' => 'shipped',
        'DELIVERED' => 'delivered',
        'CANCELED' => 'canceled',
    ];

    private const ORDERCHAMP_STATUS_MAP = [
        'DRAFT' => 'open',
        'OPEN' => 'open',
        'AWAITING_PAYMENT' => 'open',
        'AWAITING_ORDERCHAMP_APPROVAL' => 'open',
        'AWAITING_CONFIRMATION' => 'open',
        'AWAITING_FULFILMENT' => 'confirmed',
        'FULFILMENT_IN_PROGRESS' => 'confirmed',
        'AWAITING_PICKUP' => 'confirmed',
        'AWAITING_SHIPMENT' => 'confirmed',
        'ATTENTION_REQUIRED' => 'confirmed',
        'ISSUE_REPORTED' => 'confirmed',
        'BLOCKED' => 'confirmed',
        'CANCELLATION_REQUESTED' => 'confirmed',
        'AWAITING_DELIVERY' => 'shipped',
        'AWAITING_DROP_OFF' => 'shipped',
        'PARTIALLY_SHIPPED' => 'shipped',
        'COMPLETED' => 'delivered',
        'CANCELLED' => 'canceled',
    ];

    /**
     * @return array{imported: int, unmatchedSkus: array<int, string>, nextCursor: ?string, done: bool}
     */
    public static function importFairePage(?string $cursor, ?string $createdAtMin): array
    {
        $platform = WholesalePlatformRepository::findByCode('faire');
        if ($platform === null) {
            throw new \RuntimeException('Platform "faire" niet gevonden in wholesale_platforms - draai sql/migrations/005_wholesale_tables.sql.');
        }

        $page = FaireService::fetchOrdersPage($cursor, $createdAtMin);
        $unmatchedSkus = [];
        $retailerCache = [];

        foreach ($page['orders'] as $raw) {
            $normalized = self::normalizeFaireOrder($raw, $retailerCache);
            $unmatchedSkus = array_merge($unmatchedSkus, self::persist((int) $platform['id'], $normalized));
        }

        return [
            'imported' => count($page['orders']),
            'unmatchedSkus' => array_values(array_unique($unmatchedSkus)),
            'nextCursor' => $page['cursor'],
            'done' => $page['orders'] === [] || $page['cursor'] === null,
        ];
    }

    /**
     * @return array{imported: int, unmatchedSkus: array<int, string>, nextCursor: ?string, done: bool}
     */
    public static function importOrderchampPage(?string $cursor, ?string $since): array
    {
        $platform = WholesalePlatformRepository::findByCode('orderchamp');
        if ($platform === null) {
            throw new \RuntimeException('Platform "orderchamp" niet gevonden in wholesale_platforms - draai sql/migrations/005_wholesale_tables.sql.');
        }

        $page = OrderchampService::fetchOrdersPage($cursor, $since);
        $unmatchedSkus = [];

        foreach ($page['orders'] as $raw) {
            $normalized = self::normalizeOrderchampOrder($raw);
            $unmatchedSkus = array_merge($unmatchedSkus, self::persist((int) $platform['id'], $normalized));
        }

        return [
            'imported' => count($page['orders']),
            'unmatchedSkus' => array_values(array_unique($unmatchedSkus)),
            'nextCursor' => $page['cursor'],
            'done' => !$page['hasNextPage'],
        ];
    }

    /**
     * @param array<string, array<string, mixed>|null> $retailerCache retailer_id => profiel, om niet
     *                                                                 elke order een eigen API-call te
     *                                                                 laten doen voor dezelfde shop
     * @return array<string, mixed>
     */
    private static function normalizeFaireOrder(array $raw, array &$retailerCache): array
    {
        $items = [];
        $currency = 'USD';
        $totalCents = 0;
        foreach ($raw['items'] ?? [] as $item) {
            $unitCents = (int) ($item['price']['amount_minor'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 1);
            $currency = $item['price']['currency'] ?? $currency;
            $totalCents += $unitCents * $quantity;

            $items[] = [
                'sku' => (string) ($item['sku'] ?? ''),
                'title_snapshot' => (string) ($item['product_name'] ?? $item['variant_name'] ?? 'Onbekend'),
                'quantity' => $quantity,
                'unit_price_cents' => $unitCents,
            ];
        }

        $retailerId = $raw['retailer_id'] ?? null;
        $shop = null;
        if ($retailerId !== null) {
            if (!array_key_exists($retailerId, $retailerCache)) {
                $retailerCache[$retailerId] = FaireService::fetchRetailer($retailerId);
            }
            $retailer = $retailerCache[$retailerId];
            $address = $raw['address'] ?? [];

            $shop = [
                'external_shop_id' => $retailerId,
                'name' => $retailer['name'] ?? ($address['company_name'] ?? 'Onbekende winkel'),
                'street' => trim(($address['address1'] ?? '') . ' ' . ($address['address2'] ?? '')) ?: null,
                'city' => $address['city'] ?? null,
                'postal_code' => $address['postal_code'] ?? null,
                // ISO alpha-3 (Faire) - zie shops.country_code, bewust niet genormaliseerd naar alpha-2.
                'country_code' => $address['country_code'] ?? null,
            ];
        }

        $state = (string) ($raw['state'] ?? 'NEW');

        return [
            'external_order_id' => (string) ($raw['display_id'] ?? $raw['id']),
            'status' => self::FAIRE_STATE_MAP[$state] ?? 'open',
            'placed_at' => self::toMysqlDateTime($raw['created_at'] ?? null),
            'currency' => $currency,
            'total_amount_cents' => $totalCents,
            'canceled_at' => $state === 'CANCELED' ? self::toMysqlDateTime($raw['updated_at'] ?? null) : null,
            'raw_payload' => $raw,
            'shop' => $shop,
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeOrderchampOrder(array $raw): array
    {
        $items = [];
        foreach ($raw['products']['nodes'] ?? [] as $product) {
            $items[] = [
                'sku' => (string) ($product['sku'] ?? ''),
                'title_snapshot' => (string) ($product['title'] ?? 'Onbekend'),
                'quantity' => (int) ($product['quantity'] ?? 1),
                'unit_price_cents' => self::moneyToCents($product['unitPrice'] ?? null),
            ];
        }

        $customer = $raw['customer'] ?? null;
        $shop = null;
        if ($customer !== null) {
            $address = $customer['address'] ?? [];
            $street = trim(($address['street'] ?? '') . ' ' . ($address['houseNumber'] ?? '')) ?: null;

            $shop = [
                'external_shop_id' => (string) ($customer['id'] ?? ''),
                'name' => $customer['companyName'] ?? 'Onbekende winkel',
                'street' => $street,
                'city' => $address['city'] ?? null,
                'postal_code' => $address['postalCode'] ?? null,
                // ISO alpha-2 (Orderchamp) - zie shops.country_code, andere lengte dan Faire.
                'country_code' => $address['country'] ?? null,
            ];
        }

        $status = (string) ($raw['status'] ?? 'OPEN');

        return [
            'external_order_id' => (string) ($raw['number'] ?? $raw['reference'] ?? $raw['id']),
            'status' => self::ORDERCHAMP_STATUS_MAP[$status] ?? 'open',
            'placed_at' => self::toMysqlDateTime($raw['createdAt'] ?? null),
            'currency' => (string) ($raw['currency'] ?? 'EUR'),
            'total_amount_cents' => self::moneyToCents($raw['totalPrice'] ?? null),
            'canceled_at' => self::toMysqlDateTime($raw['cancelledAt'] ?? null),
            'raw_payload' => $raw,
            'shop' => $shop,
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $normalized
     * @return array<int, string> SKU's uit deze order die niet gematcht konden worden
     */
    private static function persist(int $platformId, array $normalized): array
    {
        $shopId = null;
        if ($normalized['shop'] !== null) {
            $shopId = ShopRepository::upsert([
                'platform_id' => $platformId,
                ...$normalized['shop'],
            ]);
        }

        $orderId = WholesaleOrderRepository::upsert([
            'platform_id' => $platformId,
            'external_order_id' => $normalized['external_order_id'],
            'shop_id' => $shopId,
            'status' => $normalized['status'],
            'placed_at' => $normalized['placed_at'],
            'currency' => $normalized['currency'],
            'total_amount_cents' => $normalized['total_amount_cents'],
            'canceled_at' => $normalized['canceled_at'],
            'raw_payload' => $normalized['raw_payload'],
        ]);

        $unmatchedSkus = [];
        $items = [];
        foreach ($normalized['items'] as $item) {
            $resolved = $item['sku'] !== '' ? SkuResolver::resolve($item['sku']) : ['type' => null, 'id' => null];
            if ($resolved['type'] === null && $item['sku'] !== '') {
                $unmatchedSkus[] = $item['sku'];
            }

            $items[] = [
                'sku' => $item['sku'],
                'title_snapshot' => $item['title_snapshot'],
                'quantity' => $item['quantity'],
                'unit_price_cents' => $item['unit_price_cents'],
                'product_id' => $resolved['type'] === 'product' ? $resolved['id'] : null,
                'card_id' => $resolved['type'] === 'card' ? $resolved['id'] : null,
            ];
        }

        WholesaleOrderRepository::replaceItems($orderId, $items);

        return $unmatchedSkus;
    }

    private static function toMysqlDateTime(?string $iso8601): ?string
    {
        if ($iso8601 === null || $iso8601 === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($iso8601))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Orderchamp's Money-scalar is een decimale string ("45.50"), geen
     * amount_minor/currency-object zoals bij Faire.
     */
    private static function moneyToCents(?string $money): int
    {
        if ($money === null || $money === '') {
            return 0;
        }

        return (int) round(((float) $money) * 100);
    }
}
