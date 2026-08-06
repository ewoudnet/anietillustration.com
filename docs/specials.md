# Module: specials (fase 1)

## Doel
Generiek systeem voor tijdelijke aanbiedingen die particulieren zonder inlog
kunnen bestellen — de opvolger van het huidige losse advent-concept, maar
herbruikbaar voor meerdere specials naast elkaar (banner, tekst,
prijsvarianten, status aan/uit). Publiek zichtbaar via een overzicht van
lopende specials met daaronder de verlopen specials.

## Belangrijkste bestanden/locaties
- Publiek: `specials/index.php` (overzicht zonder `?s=`, bestelpagina met
  `?s={id}`), `specials/process-order.php`, `specials/webhook.php`,
  `specials/success.php`
- Backend: `backend/specials/index.php` (overzicht), `form.php` (aanmaken/
  bewerken), `delete.php`, `toggle-active.php`
- `src/SpecialRepository.php` — CRUD specials + prijsvarianten,
  `findPublicActive()`/`findPublicExpired()`/`findOrderable()`
- `src/ImageUpload.php` — banner-upload (resize via GD), zie
  `docs/backend.md` voor waarom dit naar `specials/assets/uploads/banners/`
  schrijft i.p.v. `backend/assets/`

## Status / openstaande punten
- [x] Databaseschema: `specials` (`title`, `slug`, `banner_path`, `description`,
  `active`, `ship_eu`, `ship_world`, `starts_at`, `ends_at`) +
  `special_price_variants` (`label`, `price_nl_cents`, `price_eu_cents`,
  `price_world_cents`, `sort_order`, `active`), zie `sql/schema.sql` (bestaande
  database: `sql/migrations/001_zone_pricing.sql`,
  `sql/migrations/002_special_slug.sql`)
- [x] Deelbare/betrouwbare URL per special: `/specials/{slug}` (bijv.
  `https://aniet.nl/specials/kalender2027`) i.p.v. alleen `?s={id}`. Slug is
  admin-instelbaar in het bewerkformulier (auto-voorstel op basis van de
  titel, uniek per special), gerouteerd via `specials/.htaccess`
  (`mod_rewrite` naar `index.php?slug=...`). `?s={id}` blijft ook werken
  (fallback voor specials zonder slug en bestaande links).
- [x] Publieke overzichtspagina (lopend + verlopen)
- [x] Publiek besteltraject per special (formulier → order + Mollie-betaling
  → webhook → bedankpagina — zelfde flow als `/advent`), met live
  prijsberekening op basis van het gekozen land (net als de oude
  advent-pagina)
- [x] Backend CRUD voor specials incl. banner-upload, verzendgebied-toggles
  (EU/wereld) en repeatable prijsvariant-rijen met 3 prijzen per zone (JS
  `<template>`-clone, server-side opnieuw gevalideerd)
- [x] Status aan/uit-toggle (`active`) + optionele `starts_at`/`ends_at` voor
  automatisch "lopend"/"verlopen"
- [x] Directe link vanuit het bewerkformulier naar de publieke special-pagina

## Statusbepaling (lopend/verlopen/concept)
- **Concept** (nooit publiek zichtbaar): `active = 0`.
- **Lopend**: `active = 1 AND (starts_at IS NULL OR starts_at <= NOW()) AND
  (ends_at IS NULL OR ends_at >= NOW())`.
- **Verlopen**: `active = 1 AND ends_at IS NOT NULL AND ends_at < NOW()`.
- **Gepland** (alleen zichtbaar in het backend-overzicht): `active = 1` met
  een `starts_at` in de toekomst.

## Beslissingen & rationale
- **Vervangen beslissing (was: vaste totaalprijs per variant, geen
  zone-toeslag).** Alsnog land-gebaseerde prijszones ingevoerd: elke
  prijsvariant heeft een prijs voor NL, EU (buiten NL) en wereld (buiten EU) -
  net als het oude advent-systeem (`Pricing::priceForZone`), maar dan per
  prijsvariant in plaats van één vaste prijs per product. Welke zones
  daadwerkelijk aangeboden worden op de bestelpagina is instelbaar per
  special via `ship_eu`/`ship_world` (specials kunnen dus ook NL-only of
  NL+EU zijn).
  **Waarom:** gebruiker wil per land een andere prijs kunnen instellen
  (bijv. NL €25 / EU €30 / wereld €35), en niet elke special wil wereldwijd
  verzenden.
  **Datum:** 2026-08-06
- **Beslissing:** prijsvarianten worden bij elke edit volledig verwijderd en
  opnieuw ingevoegd (geen losse update-per-rij).
  **Waarom:** simpelste implementatie voor een repeatable form; orders
  hebben geen foreign key naar `price_variant_id` en bewaren een eigen
  snapshot (`variant_label`, `unit_price_cents`), dus bestaande orders
  blijven correct ook als de onderliggende variant-rij verdwijnt.
  **Datum:** 2026-08-06

## Beslissingen & rationale
- **Beslissing:** `/specials/` vervangt het huidige losse `/advent/`-patroon
  door specials generiek te maken, vergelijkbaar met hoe
  `adventskaarten-bestellen` al meerdere producten (advent, kalender2027)
  met een `product_type`-kolom in één systeem ondersteunt.
  **Waarom:** voorkomt dat voor elke nieuwe special een los kopie-project
  nodig is. De bestaande advent-map en -admin blijven intact en operationeel
  totdat de gebruiker expliciet akkoord geeft om ze te verwijderen, pas
  nadat dit nieuwe systeem het overneemt.
  **Datum:** 2026-08-06
- **Beslissing:** `Countries.php` uitgebreid met een derde zone ("wereld" -
  landen buiten de EU, geen volledige ISO-lijst maar een ruime praktische
  set). Welke zones een special aanbiedt op de bestelpagina is per special
  instelbaar (`ship_eu`/`ship_world`), onafhankelijk van elkaar.
  **Waarom:** nodig voor de wereld-prijszone; niet elke special verzendt
  wereldwijd of zelfs buiten NL.
  **Datum:** 2026-08-06
- **Beslissing:** styling van `specials/` en `backend/` overgenomen van de
  bestaande merkstijl op `aniet.nl/advent/` (publieke pagina) en
  `aniet.nl/backoffice` (admin) - kleuren, fonts (Baloo 2 + Quicksand) en
  pil-vormige knoppen, met behoud van de eigen paginastructuur (backend
  gebruikt een uitklapbare sidebar i.p.v. de topbar-navigatie van
  `backoffice`).
  **Waarom:** consistente merkbeleving met de andere Aniet Illustration-sites
  op `aniet.nl`.
  **Datum:** 2026-08-06
- **Beslissing:** losse `PUBLIC_URL`-configwaarde (naast `APP_URL`) voor de
  "Bekijk live"/"Bekijk special-pagina"-links in de backend.
  **Waarom:** `APP_URL` moet de daadwerkelijke omgeving matchen waar de code
  draait (lokaal `:8002`, later de live submap) omdat Mollie-redirects/
  webhooks daar op terugkomen; als een admin lokaal werkt zou een link op
  basis van `APP_URL` dus naar `localhost` wijzen, onbruikbaar om te delen of
  live te bekijken. `PUBLIC_URL` staat daar los van en wijst altijd naar de
  echte klant-URL.
  **Datum:** 2026-08-06
- **Advent-special overgenomen** als concept (`active = 0`) in
  `sql/seed_advent_special.sql`, met de productieprijzen/introtekst uit
  `adventskaarten-bestellen` (NL €34,95 / EU €40,00 / wereld €50,00,
  `ship_world` staat uit - alleen NL+EU, zoals de huidige advent-pagina).
  **Let op:** dit SQL-bestand moet nog handmatig uitgevoerd worden op de
  database - er was geen databasetoegang beschikbaar om dit automatisch te
  doen.
  **Datum:** 2026-08-06
