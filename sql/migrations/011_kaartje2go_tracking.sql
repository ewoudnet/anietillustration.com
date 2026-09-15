-- Voegt een Kaartje2Go-tracking-sectie toe aan `cards`, analoog aan de bestaande
-- Greetz-sectie (briefing/ingestuurd/nog in te sturen + submission/rejected date).
-- Kaartje2Go heeft geen PSD-bestandsnaam, dat is Greetz-specifiek.
--
-- De bestaande Greetz-kolommen `submission_date`/`rejected_date` worden hernoemd naar
-- `greetz_submission_date`/`greetz_rejected_date`, zodat ze niet botsen met de nieuwe
-- Kaartje2Go-kolommen. Zie CardRepository/bootstrap.php/card-form.php/cards.php/
-- card-duplicate.php/settings/backup.php voor de bijbehorende codewijzigingen.
--
-- LET OP: `cards` staat niet in sql/schema.sql - die tabel wordt beheerd door de
-- losstaande aniet.nl/backoffice-tool (zelfde database, zie backend/bootstrap.php).
--
-- Deze migratie is al handmatig uitgevoerd op productie (2026-09-15) - alleen nog
-- nodig op andere omgevingen (lokaal/staging) die dezelfde cards-tabel gebruiken.
-- Niet idempotent (RENAME COLUMN faalt als de kolom al hernoemd is) - controleer dat
-- vooraf als je hem alsnog los moet draaien.

ALTER TABLE cards
    CHANGE COLUMN submission_date greetz_submission_date DATE DEFAULT NULL,
    CHANGE COLUMN rejected_date greetz_rejected_date DATE DEFAULT NULL,
    ADD COLUMN kaartje2go_type ENUM('briefing', 'ingestuurd', 'nog_in_te_sturen') DEFAULT NULL AFTER psd_filename,
    ADD COLUMN kaartje2go_submission_date DATE DEFAULT NULL AFTER kaartje2go_type,
    ADD COLUMN kaartje2go_rejected_date DATE DEFAULT NULL AFTER kaartje2go_submission_date;
