-- Voegt de sectie "Wholesale" toe aan de sections-tabel (dezelfde database als Specials
-- en de backoffice-tabellen, zie DB_* in .env), zodat toegang tot backend/wholesale/ per
-- gebruiker instelbaar is via user_sections, net als de bestaande secties
-- "Aniet Illustration" en "Specials". Handmatig uitvoeren op zowel lokaal als live
-- (deploy.yml raakt geen database's aan).
--
-- Idempotent: veilig opnieuw uit te voeren.

INSERT INTO sections (slug, name, icon, sort_order)
SELECT 'wholesale', 'Wholesale', '📦',
       COALESCE((SELECT MAX(sort_order) + 1 FROM sections), 0)
WHERE NOT EXISTS (SELECT 1 FROM sections WHERE slug = 'wholesale');
