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
 * Idempotent via een atomische claim op wholesale_orders.stock_deducted_at
 * (WholesaleOrderRepository::claimStockDeduction()/releaseStockDeduction()):
 * een order wordt precies één keer afgeschreven, ook als dezelfde webhook/
 * cron-run elkaar overlappen (retries, overlappende polls op trage/drukke
 * runs). Bewust GEEN lees-dan-schrijf op stock_deducted_at meer (dat was de
 * bug: twee overlappende runs zagen allebei "nog niet afgeschreven" en
 * schreven de voorraad allebei af) - de UPDATE ... WHERE stock_deducted_at
 * IS NULL/IS NOT NULL is zelf de enige bron van waarheid over wie "wint".
 */
final class WholesaleStockDeductionService
{
    /**
     * @param array<int, array{sku: string, quantity: int, product_id: ?int, card_id: ?int}> $items
     * @return bool of de lokale voorraad daadwerkelijk is aangepast - de
     *              aanroeper gebruikt dit om te bepalen of fase D (outbound
     *              sync naar Faire/Orderchamp) nog moet draaien.
     */
    public static function reconcile(
        int $orderId,
        string $newStatus,
        ?int $platformId,
        array $items
    ): bool {
        if ($newStatus === 'canceled') {
            if (!WholesaleOrderRepository::releaseStockDeduction($orderId)) {
                // Was al niet (meer) afgeschreven - niets terug te boeken.
                return false;
            }

            self::adjust($items, 1, $platformId, 'order_canceled');

            return true;
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        if (!WholesaleOrderRepository::claimStockDeduction($orderId, $now)) {
            // Al afgeschreven door deze of een overlappende aanroep - niet nogmaals.
            return false;
        }

        self::adjust($items, -1, $platformId, 'order_placed');

        return true;
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
