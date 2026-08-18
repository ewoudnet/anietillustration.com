-- Reconstructie: ga terug naar het laatst door Faire zelf BEVESTIGDE aantal
-- (gelezen via WholesaleStockChecker, "Vernieuw voorraadvergelijking"), van
-- vóór de allereerste keer dat de sync-knop iets heeft teruggeschreven, en
-- trek daarna alle wholesale-orderregels na dat moment eraf. Puur leesend.
--
-- Werking: de eerste 'reconciliation'-regel per product/kaart in stock_sync_log
-- heeft als old_stock precies het laatst geziene Faire-aantal ZOALS DAT WAS
-- VOORDAT wij ooit iets teruggeschreven hebben - dat is het vertrouwde
-- ijkpunt. Alles wat daarna aan (niet-geannuleerde) orderregels is
-- binnengekomen, trekken we daar handmatig van af.
--
-- LET OP - twee dingen die dit NIET meeneemt:
--   1. Verkopen via het eigen kanaal (webshop/specials) die in dezelfde
--      periode ook current_stock hebben verlaagd - dit gaat puur over de
--      wholesale-vraag.
--   2. SKU's die vóór de allereerste sync nog nooit gecontroleerd zijn via
--      "Vernieuw voorraadvergelijking" hebben geen ijkpunt en staan niet in
--      dit resultaat - zie het tweede query-blok hieronder voor die lijst.

WITH baseline AS (
    SELECT
        l.product_id,
        l.card_id,
        l.old_stock AS baseline_stock,
        l.created_at AS baseline_at
    FROM stock_sync_log l
    INNER JOIN (
        SELECT product_id, card_id, MIN(id) AS first_id
        FROM stock_sync_log
        WHERE trigger_type = 'reconciliation'
          AND direction = 'outbound'
          AND platform_id = (SELECT id FROM wholesale_platforms WHERE code = 'faire')
        GROUP BY product_id, card_id
    ) first_row ON first_row.first_id = l.id
),
demand AS (
    SELECT
        woi.product_id,
        woi.card_id,
        SUM(woi.quantity) AS total_ordered_since_baseline
    FROM wholesale_order_items woi
    INNER JOIN wholesale_orders wo ON wo.id = woi.wholesale_order_id
    INNER JOIN baseline b
        ON b.product_id <=> woi.product_id AND b.card_id <=> woi.card_id
    WHERE wo.status <> 'canceled'
      AND wo.placed_at > b.baseline_at
    GROUP BY woi.product_id, woi.card_id
)
SELECT
    CASE WHEN p.id IS NOT NULL THEN 'product' ELSE 'card' END AS type,
    COALESCE(p.id, c.id) AS id,
    COALESCE(p.sku, c.sku) AS sku,
    COALESCE(p.title, c.title) AS title,
    COALESCE(p.current_stock, c.current_stock) AS current_stock_now,
    b.baseline_stock AS faire_baseline_voor_eerste_sync,
    b.baseline_at,
    COALESCE(d.total_ordered_since_baseline, 0) AS wholesale_vraag_sinds_baseline,
    b.baseline_stock - COALESCE(d.total_ordered_since_baseline, 0) AS reconstructed_stock
FROM baseline b
LEFT JOIN products p ON p.id = b.product_id
LEFT JOIN cards c ON c.id = b.card_id
LEFT JOIN demand d ON d.product_id <=> b.product_id AND d.card_id <=> b.card_id
ORDER BY sku;

-- Losstaand: SKU's/items zonder ijkpunt (nooit gecontroleerd vóór de eerste
-- sync-poging) - voor deze kan bovenstaande reconstructie niet worden
-- toegepast, die moet je apart tegen de fysieke voorraad aanhouden.
SELECT
    CASE WHEN p.id IS NOT NULL THEN 'product' ELSE 'card' END AS type,
    COALESCE(p.id, c.id) AS id,
    COALESCE(p.sku, c.sku) AS sku,
    COALESCE(p.title, c.title) AS title,
    COALESCE(p.current_stock, c.current_stock) AS current_stock_now
FROM products p
LEFT JOIN cards c ON 1 = 0
WHERE p.sku IS NOT NULL AND p.sku <> ''
  AND NOT EXISTS (
      SELECT 1 FROM stock_sync_log l
      WHERE l.product_id = p.id
        AND l.trigger_type = 'reconciliation' AND l.direction = 'outbound'
        AND l.platform_id = (SELECT id FROM wholesale_platforms WHERE code = 'faire')
  )
  AND EXISTS (
      SELECT 1 FROM wholesale_order_items woi WHERE woi.product_id = p.id
  )
UNION ALL
SELECT
    'card' AS type,
    c.id,
    c.sku,
    c.title,
    c.current_stock AS current_stock_now
FROM cards c
WHERE c.sku IS NOT NULL AND c.sku <> ''
  AND NOT EXISTS (
      SELECT 1 FROM stock_sync_log l
      WHERE l.card_id = c.id
        AND l.trigger_type = 'reconciliation' AND l.direction = 'outbound'
        AND l.platform_id = (SELECT id FROM wholesale_platforms WHERE code = 'faire')
  )
  AND EXISTS (
      SELECT 1 FROM wholesale_order_items woi WHERE woi.card_id = c.id
  );
