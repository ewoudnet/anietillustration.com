-- Fase D: voorraad SCHRIJVEN naar Faire/Orderchamp. Voegt een dry_run-kolom toe
-- aan stock_sync_log zodat een proefdraai (sync_enabled=0, alleen loggen wat
-- er verstuurd ZOU worden) zichtbaar onderscheiden blijft van een echte,
-- verstuurde synchronisatie (sync_enabled=1). Zie docs/wholesale.md.
--
-- Voer dit handmatig uit op elke database waar 005_wholesale_tables.sql al
-- gedraaid is (schema.sql volstaat voor nieuwe installaties).
--
-- Idempotent: veilig opnieuw uit te voeren.

ALTER TABLE stock_sync_log
    ADD COLUMN IF NOT EXISTS dry_run TINYINT(1) NOT NULL DEFAULT 0 AFTER success;
