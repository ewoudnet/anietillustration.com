-- Voegt soft-delete en bronherkenning toe aan orders, analoog aan
-- adventskaarten-bestellen/sql/migrations/005_add_deleted_at.sql en 006_add_stats_tracking.sql.
-- Voer dit handmatig uit op elke database waar `orders` al bestaat (schema.sql
-- volstaat voor nieuwe installaties).

ALTER TABLE orders
    ADD COLUMN traffic_source VARCHAR(100) DEFAULT NULL AFTER source,
    ADD COLUMN deleted_at DATETIME DEFAULT NULL AFTER confirmation_email_sent_at;
