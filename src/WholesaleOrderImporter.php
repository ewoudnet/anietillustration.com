<?php

declare(strict_types=1);

namespace App;

/**
 * Haalt orders op bij Faire/Orderchamp en schrijft ze naar wholesale_orders/
 * wholesale_order_items/shops. Gebruikt voor zowel de eenmalige historische
 * import (fase B, import.php) als de live nieuwe-order-detectie (fase E,
 * cron-faire.php + webhook-orderchamp.php), vandaar de scheiding tussen
 * "haal een pagina/order op" (FaireService/OrderchampService) en
 * "normaliseer + schrijf weg" (hier).
 *
 * Voorraadaanpassing (WholesaleStockDeductionService) gebeurt uitsluitend als
 * de aanroeper $deductStock=true meegeeft - de historische import (fase B)
 * geeft dit bewust NOOIT mee, want die orders zijn al lang fysiek verwerkt en
 * zouden de voorraad ten onrechte verlagen. Zie docs/wholesale.md.
 *
 * Roept zelf NOOIT fase D (WholesaleStockSyncService, outbound naar Faire/
 * Orderchamp) aan - dat blijft de verantwoordelijkheid van de aanroepende
 * entry-points (cron-faire.php/webhook-orderchamp.php), die daarvoor de
 * `stockChanged`-vlag in de return-waarde gebruiken. Zo blijft fase D/E dezelfde
 * scheiding houden als fase C/D al hadden.
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
     * @return array{imported: int, unmatchedSkus: array<int, string>, nextCursor: ?string, done: bool, stockChanged: bool}
     */
    public static function importFairePage(?string $cursor, ?string $createdAtMin, bool $deductStock = false): array
    {
        $platform = WholesalePlatformRepository::findByCode('faire');
        if ($platform === null) {
            throw new \RuntimeException('Platform "faire" niet gevonden in wholesale_platforms - draai sql/migrations/005_wholesale_tables.sql.');
        }

        $page = FaireService::fetchOrdersPage($cursor, $createdAtMin);
        $unmatchedSkus = [];
        $stockChanged = false;
        $retailerCache = [];

        foreach ($page['orders'] as $raw) {
            $normalized = self::normalizeFaireOrder($raw, $retailerCache);
            $persisted = self::persist((int) $platform['id'], $normalized, $deductStock);
            $unmatchedSkus = array_merge($unmatchedSkus, $persisted['unmatchedSkus']);
            $stockChanged = $stockChanged || $persisted['stockChanged'];
        }

        return [
            'imported' => count($page['orders']),
            'unmatchedSkus' => array_values(array_unique($unmatchedSkus)),
            'nextCursor' => $page['cursor'],
            'done' => $page['orders'] === [] || $page['cursor'] === null,
            'stockChanged' => $stockChanged,
        ];
    }

    /**
     * @return array{imported: int, unmatchedSkus: array<int, string>, nextCursor: ?string, done: bool, stockChanged: bool}
     */
    public static function importOrderchampPage(?string $cursor, ?string $since, bool $deductStock = false): array
    {
        $platform = WholesalePlatformRepository::findByCode('orderchamp');
        if ($platform === null) {
            throw new \RuntimeException('Platform "orderchamp" niet gevonden in wholesale_platforms - draai sql/migrations/005_wholesale_tables.sql.');
        }

        $page = OrderchampService::fetchOrdersPage($cursor, $since);
        $unmatchedSkus = [];
        $stockChanged = false;

        foreach ($page['orders'] as $raw) {
            $normalized = self::normalizeOrderchampOrder($raw);
            $persisted = self::persist((int) $platform['id'], $normalized, $deductStock);
            $unmatchedSkus = array_merge($unmatchedSkus, $persisted['unmatchedSkus']);
            $stockChanged = $stockChanged || $persisted['stockChanged'];
        }

        return [
            'imported' => count($page['orders']),
            'unmatchedSkus' => array_values(array_unique($unmatchedSkus)),
            'nextCursor' => $page['cursor'],
            'done' => !$page['hasNextPage'],
            'stockChanged' => $stockChanged,
        ];
    }

    /**
     * Verwerkt één Orderchamp-order op basis van zijn id - gebruikt door de
     * order-webhook (fase E), die zelf alleen een minimale payload
     * binnenkrijgt (zie OrderchampService::fetchOrderById()). Schrijft altijd
     * met voorraadaftrek, want dit pad bestaat per definitie alleen voor
     * live, nieuwe/gewijzigde orders - nooit voor historische import.
     *
     * @return array{unmatchedSkus: array<int, string>, stockChanged: bool}
     */
    public static function importOrderchampOrderById(string $orderchampOrderId): array
    {
        $platform = WholesalePlatformRepository::findByCode('orderchamp');
        if ($platform === null) {
            throw new \RuntimeException('Platform "orderchamp" niet gevonden in wholesale_platforms - draai sql/migrations/005_wholesale_tables.sql.');
        }

        $raw = OrderchampService::fetchOrderById($orderchampOrderId);
        if ($raw === null) {
            throw new \RuntimeException("Orderchamp-order {$orderchampOrderId} niet gevonden via de API.");
        }

        $normalized = self::normalizeOrderchampOrder($raw);

        return self::persist((int) $platform['id'], $normalized, true);
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

        // products(first: 30) kan in theorie afkappen bij een uitzonderlijk grote
        // order - dat mag nooit stil gebeuren, dus loggen i.p.v. negeren.
        if ($raw['products']['pageInfo']['hasNextPage'] ?? false) {
            error_log(sprintf(
                'WholesaleOrderImporter: order %s heeft meer dan 30 regels bij Orderchamp - alleen de eerste 30 zijn geïmporteerd.',
                $raw['number'] ?? $raw['id'] ?? '?'
            ));
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
     * @return array{unmatchedSkus: array<int, string>, stockChanged: bool}
     */
    private static function persist(int $platformId, array $normalized, bool $deductStock = false): array
    {
        // Vóór de upsert opzoeken - erna is niet meer te zien of dit een
        // nieuwe order was of een statuswijziging op een bekende order (fase E).
        $existing = $deductStock
            ? WholesaleOrderRepository::findByPlatformAndExternalId($platformId, $normalized['external_order_id'])
            : null;
        $previousStockDeductedAt = $existing['stock_deducted_at'] ?? null;

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

        $stockChanged = false;
        if ($deductStock) {
            $stockChanged = WholesaleStockDeductionService::reconcile(
                $orderId,
                $previousStockDeductedAt,
                $normalized['status'],
                $platformId,
                $items
            );
        }

        return ['unmatchedSkus' => $unmatchedSkus, 'stockChanged' => $stockChanged];
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
