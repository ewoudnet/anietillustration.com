-- Volledig overzicht voor het telformulier: alle kaarten + wholesale-producten
-- met afbeelding, huidig (onbetrouwbaar) systeemgetal, en of de SKU ooit in
-- een van de 7 echte wholesale-orders voorkwam (prioriteit 1) of niet
-- (prioriteit 2). Puur leesend.

SELECT
    'card' AS type,
    c.id,
    c.sku,
    c.title,
    c.image_path,
    c.current_stock AS current_stock_now,
    CASE WHEN EXISTS (
        SELECT 1 FROM wholesale_order_items woi WHERE woi.card_id = c.id
    ) THEN 1 ELSE 0 END AS ooit_besteld
FROM cards c
WHERE c.sku IS NOT NULL AND c.sku <> ''

UNION ALL

SELECT
    'product' AS type,
    p.id,
    p.sku,
    p.title,
    p.image_path,
    p.current_stock AS current_stock_now,
    CASE WHEN EXISTS (
        SELECT 1 FROM wholesale_order_items woi WHERE woi.product_id = p.id
    ) THEN 1 ELSE 0 END AS ooit_besteld
FROM products p
WHERE p.sku IS NOT NULL AND p.sku <> ''

ORDER BY ooit_besteld DESC, sku;
