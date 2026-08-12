-- Faire/Orderchamp matchen SKU's tegen zowel de `products`- als de `cards`-
-- tabel (zelfde als de bestaande, eenrichtings-Faire-voorraadsync al doet -
-- zie ProductRepository/CardRepository::allIdsBySku()). De tabellen uit
-- 005_wholesale_tables.sql konden alleen naar `products` verwijzen; dit voegt
-- kaart-ondersteuning toe zodat orderregels/listings met een kaart-SKU niet
-- ten onrechte als "niet gematcht" verschijnen. Zie docs/wholesale.md.
--
-- Voer dit handmatig uit op elke database waar 005_wholesale_tables.sql al
-- gedraaid is (schema.sql volstaat voor nieuwe installaties).
--
-- Idempotent: veilig opnieuw uit te voeren.

ALTER TABLE product_platform_listings
    MODIFY COLUMN product_id INT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS card_id INT UNSIGNED DEFAULT NULL AFTER product_id,
    ADD CONSTRAINT fk_ppl_card FOREIGN KEY IF NOT EXISTS (card_id) REFERENCES cards (id) ON DELETE CASCADE,
    ADD UNIQUE KEY IF NOT EXISTS uq_ppl_card_platform (card_id, platform_id);

ALTER TABLE wholesale_order_items
    ADD COLUMN IF NOT EXISTS card_id INT UNSIGNED DEFAULT NULL AFTER product_id,
    ADD KEY IF NOT EXISTS idx_woi_card_id (card_id),
    ADD CONSTRAINT fk_woi_card FOREIGN KEY IF NOT EXISTS (card_id) REFERENCES cards (id) ON DELETE SET NULL;

ALTER TABLE stock_sync_log
    ADD COLUMN IF NOT EXISTS card_id INT UNSIGNED DEFAULT NULL AFTER product_id,
    ADD KEY IF NOT EXISTS idx_ssl_card_id (card_id);

-- Faire levert ISO alpha-3 landcodes (bv. "CAN"), Orderchamp ISO alpha-2
-- (bv. "NL") - CHAR(2) was te smal voor Faire-adressen.
ALTER TABLE shops
    MODIFY COLUMN country_code VARCHAR(3) DEFAULT NULL;
