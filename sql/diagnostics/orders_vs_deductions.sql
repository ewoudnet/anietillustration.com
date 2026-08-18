-- Volledige, exacte reconstructie nu er maar 7 bestellingen zijn: alle orders
-- met hun regels naast alle daadwerkelijk uitgevoerde voorraadmutaties.
-- Puur leesend.

-- Query A: alle wholesale-orders met hun regels, chronologisch.
SELECT
    wo.id AS order_id,
    wo.external_order_id,
    wo.placed_at,
    wo.status,
    wo.stock_deducted_at,
    woi.id AS item_id,
    woi.sku AS sku_op_order,
    woi.quantity,
    COALESCE(p.sku, c.sku) AS sku_nu_gekoppeld,
    COALESCE(p.title, c.title) AS title
FROM wholesale_orders wo
JOIN wholesale_order_items woi ON woi.wholesale_order_id = wo.id
LEFT JOIN products p ON p.id = woi.product_id
LEFT JOIN cards c ON c.id = woi.card_id
ORDER BY wo.placed_at, wo.id, woi.id;

-- Query B: elke voorraadmutatie die het systeem ooit heeft gelogd n.a.v. een
-- order (dus geen reconciliation/outbound-regels) - chronologisch. Meerdere
-- regels voor dezelfde SKU rond dezelfde tijd = teken van dubbele afschrijving.
SELECT
    l.id AS log_id,
    l.created_at,
    CASE WHEN l.product_id IS NOT NULL THEN 'product' ELSE 'card' END AS type,
    COALESCE(p.sku, c.sku) AS sku,
    COALESCE(p.title, c.title) AS title,
    l.trigger_type,
    l.old_stock,
    l.new_stock,
    (l.new_stock - l.old_stock) AS mutatie,
    l.success,
    wp.name AS platform
FROM stock_sync_log l
LEFT JOIN products p ON p.id = l.product_id
LEFT JOIN cards c ON c.id = l.card_id
LEFT JOIN wholesale_platforms wp ON wp.id = l.platform_id
WHERE l.trigger_type IN ('order_placed', 'order_canceled')
ORDER BY l.created_at, l.id;
