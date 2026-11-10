-- Telt hoe vaak een bestand via de downloadlink van een order is opgehaald, zodat
-- misbruik (link delen) zichtbaar is in de orderlijst. Zie docs/features/instant-download.md.
ALTER TABLE orders
    ADD COLUMN download_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER download_token,
    ADD COLUMN last_downloaded_at DATETIME DEFAULT NULL AFTER download_count;
