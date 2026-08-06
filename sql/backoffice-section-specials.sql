-- Voegt de sectie "Specials" toe aan de backoffice-database (aniet_backoffice), zodat
-- toegang tot backend/specials/ per gebruiker instelbaar is via user_sections, net als
-- de bestaande sectie "Aniet Illustration". Handmatig uitvoeren op zowel de lokale als
-- de live aniet_backoffice-database (deploy.yml raakt geen database's aan).
--
-- Idempotent: veilig opnieuw uit te voeren.

INSERT INTO sections (slug, name, icon, sort_order)
SELECT 'specials', 'Specials', '🎁',
       COALESCE((SELECT MAX(sort_order) + 1 FROM sections), 0)
WHERE NOT EXISTS (SELECT 1 FROM sections WHERE slug = 'specials');
