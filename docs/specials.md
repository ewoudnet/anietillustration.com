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
- [x] Databaseschema: `specials` (`title`, `banner_path`, `description`,
  `active`, `starts_at`, `ends_at`) + `special_price_variants` (`label`,
  `price_cents`, `sort_order`, `active`), zie `sql/schema.sql`
- [x] Publieke overzichtspagina (lopend + verlopen)
- [x] Publiek besteltraject per special (formulier → order + Mollie-betaling
  → webhook → bedankpagina — zelfde flow als `/advent`)
- [x] Backend CRUD voor specials incl. banner-upload en repeatable
  prijsvariant-rijen (JS `<template>`-clone, server-side opnieuw
  gevalideerd)
- [x] Status aan/uit-toggle (`active`) + optionele `starts_at`/`ends_at` voor
  automatisch "lopend"/"verlopen"

## Statusbepaling (lopend/verlopen/concept)
- **Concept** (nooit publiek zichtbaar): `active = 0`.
- **Lopend**: `active = 1 AND (starts_at IS NULL OR starts_at <= NOW()) AND
  (ends_at IS NULL OR ends_at >= NOW())`.
- **Verlopen**: `active = 1 AND ends_at IS NOT NULL AND ends_at < NOW()`.
- **Gepland** (alleen zichtbaar in het backend-overzicht): `active = 1` met
  een `starts_at` in de toekomst.

## Beslissingen & rationale
- **Beslissing (bevestigd door gebruiker):** prijsvarianten hebben een vaste
  totaalprijs, geen zone-gebaseerde verzendtoeslag (zoals advent).
  **Waarom:** eenvoudiger formulier/schema; kan later alsnog per special met
  meerdere varianten voor verschillende regio's als dat nodig is.
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
