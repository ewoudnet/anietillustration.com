-- Fase E: nieuwe-order-detectie (Faire-cron + Orderchamp-webhook) en de
-- bijbehorende voorraadaftrek. Zie docs/wholesale.md.
--
-- Voer dit handmatig uit op elke database waar 005_wholesale_tables.sql al
-- gedraaid is (schema.sql volstaat voor nieuwe installaties).
--
-- Idempotent: veilig opnieuw uit te voeren.

ALTER TABLE wholesale_orders
    -- NULL = nog niet afgeschreven van products.current_stock/cards.current_stock.
    -- Gezet zodra WholesaleStockDeductionService voor deze order heeft
    -- afgeschreven; teruggezet naar NULL zodra een geannuleerde order is
    -- teruggeboekt. Voorkomt dubbel afschrijven bij herhaalde webhook-
    -- aanroepen of overlappende cron-runs.
    ADD COLUMN IF NOT EXISTS stock_deducted_at DATETIME DEFAULT NULL AFTER canceled_at;

ALTER TABLE wholesale_platforms
    -- Hoogwatermerk voor de Faire-cronpoller (created_at_min bij de volgende
    -- run) - vastgelegd bij de START van een run, niet het einde, zodat
    -- orders die tijdens de run binnenkomen niet worden overgeslagen.
    ADD COLUMN IF NOT EXISTS last_synced_at DATETIME DEFAULT NULL;
