-- Voegt land-gebaseerde prijszones (NL / EU / wereld) toe aan specials en hun
-- prijsvarianten. Voer dit handmatig uit op elke database waar `specials` en
-- `special_price_variants` al bestaan (schema.sql volstaat voor nieuwe
-- installaties). Idempotent te maken door de ALTER-regels eventueel te
-- verwijderen als de kolom al bestaat.

ALTER TABLE specials
    ADD COLUMN ship_eu TINYINT(1) NOT NULL DEFAULT 1 AFTER active,
    ADD COLUMN ship_world TINYINT(1) NOT NULL DEFAULT 0 AFTER ship_eu;

ALTER TABLE special_price_variants
    ADD COLUMN price_nl_cents INT UNSIGNED DEFAULT NULL AFTER label,
    ADD COLUMN price_eu_cents INT UNSIGNED DEFAULT NULL AFTER price_nl_cents,
    ADD COLUMN price_world_cents INT UNSIGNED DEFAULT NULL AFTER price_eu_cents;

-- Bestaande vaste prijs (price_cents) was NL-only (geen zone-onderscheid) -
-- gebruik die waarde als startpunt voor alle drie de zones, zodat bestaande
-- varianten na de migratie nog steeds bestelbaar zijn. Pas de EU/wereld-
-- prijzen daarna handmatig aan in de backend.
UPDATE special_price_variants
SET price_nl_cents = price_cents,
    price_eu_cents = price_cents,
    price_world_cents = price_cents
WHERE price_cents IS NOT NULL;

ALTER TABLE special_price_variants
    MODIFY COLUMN price_nl_cents INT UNSIGNED NOT NULL,
    DROP COLUMN price_cents;
