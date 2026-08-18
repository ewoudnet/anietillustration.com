-- Fase E-vervolg: netto-uitbetaling (na commissie) naast het bruto-ordertotaal.
-- Zie docs/wholesale.md voor de rationale.
--
-- Voer dit handmatig uit op elke database waar 005_wholesale_tables.sql al
-- gedraaid is (schema.sql volstaat voor nieuwe installaties).
--
-- Idempotent: veilig opnieuw uit te voeren. Bestaande orders krijgen 0 totdat
-- ze opnieuw geïmporteerd worden (historische import via import.php is
-- idempotent en overschrijft bestaande rijen) - geen backfill-script, want
-- import.php kan gewoon opnieuw doorlopen worden.

ALTER TABLE wholesale_orders
    ADD COLUMN IF NOT EXISTS payout_amount_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER total_amount_cents,
    ADD COLUMN IF NOT EXISTS commission_amount_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER payout_amount_cents;
