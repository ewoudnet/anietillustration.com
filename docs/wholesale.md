# Module: wholesale

## Doel
Twee te onderscheiden delen onder één sectienaam:

1. **Faire + Orderchamp-synchronisatie (in opbouw, fase A afgerond)** — de
   backend-sectie "Wholesale" die orders van Faire en Orderchamp samenbrengt
   in één overzicht, de eigen database leidend maakt voor de voorraad op
   beide platformen (i.p.v. de huidige eenrichting-Faire-sync, zie
   [[products]]), en inzicht geeft in synchronisatie, SKU-dekking per kanaal
   en winkellocaties op een kaart.
2. **B2B-webshop (nog niet gestart)** — het oorspronkelijke, in CLAUDE.md
   beschreven idee van een eigen wholesale-webshop als alternatief voor
   Faire: een nieuwe klant meldt zich aan via de site, na goedkeuring
   ontvangt de klant een inlog-link, en heeft daarna een klantportaal met
   bestellingen/status. Deze klant bestelt dan rechtstreeks bij Aniet
   Illustration (Mollie-betaling), niet via Faire/Orderchamp.

**Let op, mogelijke naamsverwarring:** beide delen heten "wholesale", maar
zijn functioneel losstaand. Deel 1 (dit document, verder) synchroniseert met
bestaande marktplaatsen; deel 2 is een eigen verkoopkanaal ERNAAST. Ze delen
wel dezelfde productdata (`products`-tabel). `orders.order_type = 'wholesale'`
(zie [[orders]]) is gereserveerd voor deel 2 (Mollie-checkout, zelfde
patroon als specials) — deel 1 gebruikt bewust een eigen tabelset
(`wholesale_orders`/`wholesale_order_items`), zie rationale hieronder.

## Belangrijkste bestanden/locaties
- `sql/migrations/005_wholesale_tables.sql` (+ zelfde tabellen in
  `sql/schema.sql`) — `wholesale_platforms`, `product_platform_listings`,
  `shops`, `wholesale_orders`, `wholesale_order_items`, `stock_sync_log`.
- `sql/backoffice-section-wholesale.sql` — registreert de backend-sectie.
- `src/WholesalePlatformRepository.php`, `src/ShopRepository.php`,
  `src/WholesaleOrderRepository.php`,
  `src/ProductPlatformListingRepository.php`,
  `src/StockSyncLogRepository.php` — data-toegang, mysqli-stijl (consistent
  met `ProductRepository`).
- `src/OrderchampService.php` — nog alleen `isConfigured()`; de echte
  GraphQL-koppeling volgt in een latere fase (zie [[products]] en
  onderstaande openstaande punten).
- `backend/wholesale/` — `index.php` (dashboard), `orders.php` +
  `order-form.php` (overzicht + detail, zoeken op shopnaam/SKU/titel,
  filter op platform/status/periode), `orders-export.php` (Excel, zelfde
  `XlsxWriter` als [[orders]]), `shops.php` (kaart met Leaflet + OpenStreetMap
  -tiles), `sku-comparison.php` (matrix producten × platformen),
  `sync-log.php` (auditlog-viewer), `settings.php` (per-platform
  sync-aan/uit-schakelaar, alleen voor admins).
- `backend/partials/nav-topbar.php` / `backend/index.php` — sectie "Wholesale"
  toegevoegd aan de bestaande topbar/subnav-structuur (géén linker menu, zie
  [[backend]] voor die afwijking t.o.v. de oorspronkelijke docs-beschrijving).

## Status / openstaande punten
- [x] **Fase A (skelet):** datamodel, backend-sectie + navigatie, alle
  pagina's draaien tegen de echte (nog lege) tabellen.
- [ ] **Fase B:** bestaande Faire- én Orderchamp-orders historisch inladen in
  `wholesale_orders`/`wholesale_order_items`/`shops` — mag `products.
  current_stock` niet aanraken.
- [ ] **Fase C:** voorraad lezen — Faire-inventory-call ontsluiten in het
  vergelijkingsoverzicht; Orderchamp-lezen nieuw bouwen zodra de
  GraphQL-koppeling er is.
- [ ] **Fase D:** voorraad schrijven, eerst dry-run (loggen zonder echt te
  posten) achter de `sync_enabled`-schakelaar per platform.
- [ ] **Fase E:** nieuwe-order-webhooks (Faire + Orderchamp) → automatische
  import + voorraadaftrek.
- [ ] **Fase F:** annuleringen → status omzetten + voorraad corrigeren.
- [ ] **Fase G:** live zetten per platform zodra alle producten er correct op
  staan (harde voorwaarde van de gebruiker, zie CLAUDE.md-conventie hierover
  niet apart vastgelegd maar wel leidend voor de bouwvolgorde).
- [ ] Orderchamp: echte GraphQL-client, orders/inventory-methoden,
  webhookverificatie (`X-Orderchamp-Signature`) — wacht op de al beschikbare
  toegangstoken (zie hieronder) en op de nog te ontvangen webhook-signing
  secret.
- [ ] Faire: orders-API en voorraad-terugschrijven zijn niet geverifieerd
  (devdocs zijn client-side gerenderd, niet automatisch uitleesbaar; Faire-
  shop stond tijdens het bouwen op vakantiestop) — verifiëren zodra er
  weer toegang is, vóór er iets op aangesloten wordt.
- [ ] Geocoding van shopadressen (OpenStreetMap Nominatim) → `shops.lat/lng`.
- [ ] Periodieke reconciliatie-job (vangnet tegen gemiste webhooks) — hosting
  heeft hiervoor een cron-optie bevestigd.
- [ ] Deel 2 (B2B-webshop, zie "Doel" hierboven) — nog niet gestart, wacht op
  een go-beslissing en een niet-verwarrende naam naast deze sectie.

## Getest (lokaal, 2026-08-12)
Fase A getest tegen een losstaande, wegwerpbare MariaDB-container (niet de
live database) via `php -S`: login, alle nieuwe `backend/wholesale/`-pagina's
(200, geen PHP-fouten), zoeken/filteren op orders.php (shopnaam/SKU/titel,
platform, status, periode), de sync_enabled-schakelaar op settings.php, en
de kaartpagina (Leaflet + OpenStreetMap laden zonder consolefouten) met
seed-data (1 shop, 2 orders waarvan 1 geannuleerd, 2 producten, 3
platform-listings, 2 sync-logregels). Eén bug gevonden en gefixt tijdens het
testen: `ssl` is een reserved word in MariaDB en kon niet als tabel-alias in
`StockSyncLogRepository` gebruikt worden (hernoemd naar `l`).

## Beslissingen & rationale
- **Beslissing:** Faire/Orderchamp-marktplaatsorders krijgen een eigen
  tabelset (`wholesale_orders`/`wholesale_order_items`), los van de
  bestaande `orders`-tabel (die al een `order_type = 'wholesale'`-waarde
  klaar had staan).
  **Waarom:** die bestaande `orders`-tabel is gebouwd voor de eigen
  Mollie-checkout van specials (1 klant, 1 prijsvariant, Mollie-statussen) —
  dat is exact het model voor de toekomstige B2B-webshop (deel 2 hierboven),
  niet voor multi-regel marktplaatsorders zonder eigen Mollie-betaling.
  Hergebruik zou de twee concepten door elkaar halen.
  **Datum:** 2026-08-12
- **Beslissing:** een `sync_enabled`-vlag per platform (`wholesale_platforms
  .sync_enabled`, standaard 0) is de enige plek die uitmaakt of er ooit
  daadwerkelijk naar Faire/Orderchamp wordt teruggeschreven; lezen/loggen
  gebeurt altijd, ongeacht deze vlag.
  **Waarom:** expliciete eis dat er niets "live" mag totdat alle producten
  op beide platformen correct staan — één centrale schakelaar per platform
  is eenvoudiger te controleren dan verspreide checks door de code.
  **Datum:** 2026-08-12
- **Beslissing:** shoplocaties tonen via Leaflet + OpenStreetMap-tiles
  (CDN, geen API-key) i.p.v. Google Maps.
  **Waarom:** geen facturatie/Cloud-account nodig, past bij de rest van de
  stack (geen zware afhankelijkheden); geocoding van adressen (nog te
  bouwen) volgt om diezelfde reden via Nominatim i.p.v. Google Geocoding.
  **Datum:** 2026-08-12

## Zie ook
[[products]], [[orders]], [[backend]]
