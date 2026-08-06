-- Voegt een unieke, door de admin instelbare slug toe aan specials, zodat er een
-- betrouwbare deelbare URL is (bijv. /specials/kalender2027) i.p.v. /specials/index.php?s=1.
-- Voer dit handmatig uit op elke database waar `specials` al bestaat (schema.sql
-- volstaat voor nieuwe installaties).

ALTER TABLE specials
    ADD COLUMN slug VARCHAR(80) DEFAULT NULL AFTER title,
    ADD UNIQUE KEY uq_slug (slug);

-- Bestaande specials krijgen een startslug op basis van hun titel, zodat ze direct
-- een pretty URL hebben. Bij een titel-botsing moet de admin de slug in het
-- bewerkformulier daarna handmatig uniek maken.
UPDATE specials
SET slug = LOWER(TRIM(REGEXP_REPLACE(REGEXP_REPLACE(title, '[^a-zA-Z0-9]+', '-'), '(^-+|-+$)', '')))
WHERE slug IS NULL;
