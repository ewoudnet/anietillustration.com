-- Diagnose: hoeveel is er per product/kaart TE VEEL afgeschreven door de
-- race condition in WholesaleStockDeductionService (gefixt in de code, dit
-- is alleen om de bestaande data te herstellen). Puur leesend - past niets aan.
--
-- expected_deduction = wat er zou moeten zijn afgeschreven: 1x de bestelde
--   hoeveelheid per order die het systeem NU als "afgeschreven" beschouwt
--   (stock_deducted_at IS NOT NULL). Historische import-orders (fase B)
--   hebben stock_deducted_at nooit gezet, tellen dus terecht niet mee.
-- actual_deduction = wat er daadwerkelijk lokaal is afgetrokken, opgeteld uit
--   de audit-log (stock_sync_log).
-- overcounted = actual - expected: het teveel afgeschreven aantal, dat je
--   erbij op zou moeten tellen om current_stock te herstellen.

SELECT
    'product' AS type,
    p.id,
    p.sku,
    p.title,
    p.current_stock AS current_stock_now,
    COALESCE(expected.total_expected, 0) AS expected_deduction,
    COALESCE(actual.total_actual, 0) AS actual_deduction,
    COALESCE(actual.total_actual, 0) - COALESCE(expected.total_expected, 0) AS overcounted,
    p.current_stock + (COALESCE(actual.total_actual, 0) - COALESCE(expected.total_expected, 0)) AS suggested_corrected_stock
FROM products p
LEFT JOIN (
    SELECT woi.product_id, SUM(woi.quantity) AS total_expected
    FROM wholesale_order_items woi
    JOIN wholesale_orders wo ON wo.id = woi.wholesale_order_id
    WHERE woi.product_id IS NOT NULL AND wo.stock_deducted_at IS NOT NULL
    GROUP BY woi.product_id
) expected ON expected.product_id = p.id
LEFT JOIN (
    SELECT product_id, SUM(old_stock - new_stock) AS total_actual
    FROM stock_sync_log
    WHERE product_id IS NOT NULL
      AND trigger_type IN ('order_placed', 'order_canceled')
      AND success = 1
    GROUP BY product_id
) actual ON actual.product_id = p.id
HAVING overcounted <> 0

UNION ALL

SELECT
    'card' AS type,
    c.id,
    c.sku,
    c.title,
    c.current_stock AS current_stock_now,
    COALESCE(expected.total_expected, 0) AS expected_deduction,
    COALESCE(actual.total_actual, 0) AS actual_deduction,
    COALESCE(actual.total_actual, 0) - COALESCE(expected.total_expected, 0) AS overcounted,
    c.current_stock + (COALESCE(actual.total_actual, 0) - COALESCE(expected.total_expected, 0)) AS suggested_corrected_stock
FROM cards c
LEFT JOIN (
    SELECT woi.card_id, SUM(woi.quantity) AS total_expected
    FROM wholesale_order_items woi
    JOIN wholesale_orders wo ON wo.id = woi.wholesale_order_id
    WHERE woi.card_id IS NOT NULL AND wo.stock_deducted_at IS NOT NULL
    GROUP BY woi.card_id
) expected ON expected.card_id = c.id
LEFT JOIN (
    SELECT card_id, SUM(old_stock - new_stock) AS total_actual
    FROM stock_sync_log
    WHERE card_id IS NOT NULL
      AND trigger_type IN ('order_placed', 'order_canceled')
      AND success = 1
    GROUP BY card_id
) actual ON actual.card_id = c.id
HAVING overcounted <> 0;
