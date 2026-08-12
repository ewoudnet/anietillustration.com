<?php

declare(strict_types=1);

namespace App;

/**
 * Fase E: voorraad AFSCHRIJVEN zodra een nieuwe wholesale-order binnenkomt
 * (Faire-cron of Orderchamp-webhook), en weer TERUGBOEKEN als die order
 * alsnog geannuleerd wordt. De omgekeerde richting van WholesaleStockSyncService
 * (fase D, eigen voorraad -> platform): dit is platform-order -> eigen
 * voorraad, dus `direction = 'inbound'` in stock_sync_log.
 *
 * Bewust NIET aangeroepen vanuit de historische import (fase B, import.php) -
 * die orders zijn al lang fysiek verwerkt, dus zouden de voorraad ten
 * onrechte verlagen. Alleen de "live" nieuwe-order-paden (WholesaleOrderImporter
 * met $deductStock=true) roepen dit aan.
 *
 * Idempotent via wholesale_orders.stock_deducted_at: een order wordt precies
 * één keer afgeschreven, ongeacht hoe vaak dezelfde webhook/cron-run hem
 * opnieuw tegenkomt (retries, overlappende polls).
 */
final class WholesaleStockDeductionService
{
    /**
     * @param array<int, array{sku: string, quantity: int, product_id: ?int, card_id: ?int}> $items
     */
    public static function reconcile(
        int $orderId,
        ?string $previousStockDeductedAt,
        string $newStatus,
        ?int $platformId,
        array $items
    ): void {
        $wasDeducted = $previousStockDeductedAt !== null;
        $isCanceled = $newStatus === 'canceled';

        if ($isCanceled && $wasDeducted) {
            self::adjust($items, 1, $platformId, 'order_canceled');
            WholesaleOrderRepository::setStockDeductedAt($orderId, null);

            return;
        }

        if (!$isCanceled && !$wasDeducted) {
            self::adjust($items, -1, $platformId, 'order_placed');
            WholesaleOrderRepository::setStockDeductedAt($orderId, (new \DateTimeImmutable())->format('Y-m-d H:i:s'));
        }

        // Overige combinaties (bv. open -> confirmed, of nogmaals dezelfde
        // status) raken de voorraad niet - die is al in de juiste staat.
    }

    /**
     * @param array<int, array{sku: string, quantity: int, product_id: ?int, card_id: ?int}> $items
     * @param int $sign +1 = terugboeken (annulering), -1 = afschrijven (nieuwe order)
     */
    private static function adjust(array $items, int $sign, ?int $platformId, string $triggerType): void
    {
        foreach ($items as $item) {
            $delta = $sign * $item['quantity'];

            if ($item['product_id'] !== null) {
                $product = ProductRepository::find($item['product_id']);
                $old = $product !== null ? (int) ($product['current_stock'] ?? 0) : null;
                ProductRepository::adjustCurrentStock($item['product_id'], $delta);
                StockSyncLogRepository::log(
                    $item['product_id'],
                    null,
                    $platformId,
                    'inbound',
                    $triggerType,
                    $old,
                    $old !== null ? $old + $delta : null,
                    true,
                    false,
                    null
                );
            } elseif ($item['card_id'] !== null) {
                $card = CardRepository::find($item['card_id']);
                $old = $card !== null ? (int) ($card['current_stock'] ?? 0) : null;
                CardRepository::adjustCurrentStock($item['card_id'], $delta);
                StockSyncLogRepository::log(
                    null,
                    $item['card_id'],
                    $platformId,
                    'inbound',
                    $triggerType,
                    $old,
                    $old !== null ? $old + $delta : null,
                    true,
                    false,
                    null
                );
            }
            // Onopgeloste SKU (product_id/card_id allebei null): niets lokaal
            // om af te schrijven, geen logregel - zie wholesale_order_items.sku
            // voor de onbekende SKU zelf.
        }
    }
}
