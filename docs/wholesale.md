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
- `src/OrderchampService.php` — GraphQL-client (`graphql()` + `isConfigured()`
  + `fetchOrdersPage()`), schema geverifieerd via developers.orderchamp.com
  maar **nog niet live getest** (geen `ORDERCHAMP_ACCESS_TOKEN` in .env, zie
  onderstaande openstaande punten). Voorraad lezen/schrijven volgt in fase C/D.
- `src/FaireService.php` — uitgebreid met `fetchOrdersPage()` en
  `fetchRetailer()`, beide live geverifieerd tegen de echte Faire-API.
- `src/SkuResolver.php` — matcht een externe SKU tegen `cards` óf `products`
  (zelfde twee-tabellen-aanpak als de bestaande Faire-voorraadsync).
- `src/WholesaleOrderImporter.php` — normaliseert Faire/Orderchamp-orders naar
  het eigen model en schrijft ze weg (shop + order + regels); wordt ook
  hergebruikt voor de nieuwe-order-webhooks in fase E. Roept nooit
  voorraadcode aan.
- `src/OrderchampService.php` — `fetchInventoryBySkus()` toegevoegd, zelfde
  signatuur/semantiek als `FaireService::fetchInventoryBySkus()`, live
  geverifieerd.
- `src/WholesaleStockChecker.php` — fase C: leest voorraad bij Faire +
  Orderchamp voor alle lokale SKU's (producten + kaarten) en schrijft de
  vergelijking naar `product_platform_listings`. Roept nooit
  `*Repository::updateCurrentStock()` aan.
- `backend/wholesale/` — `index.php` (dashboard), `orders.php` +
  `order-form.php` (overzicht + detail, zoeken op shopnaam/SKU/titel,
  filter op platform/status/periode), `orders-export.php` (Excel, zelfde
  `XlsxWriter` als [[orders]]), `import.php` (historische import, per pagina
  van max. 50 orders, alleen voor admins), `shops.php` (kaart met Leaflet +
  OpenStreetMap-tiles), `sku-comparison.php` (matrix producten+kaarten ×
  platformen, met een "Vernieuw voorraadvergelijking"-knop die
  `WholesaleStockChecker` aanroept, alleen voor admins), `sync-log.php`
  (auditlog-viewer), `settings.php` (per-platform sync-aan/uit-schakelaar,
  alleen voor admins).
- `backend/bootstrap.php` — `money(int $cents, string $currency): string`
  helper (Faire/Orderchamp-orders zijn niet altijd EUR, in tegenstelling tot
  specials, dus geen hardcoded "€" in de wholesale-pagina's).
- `backend/partials/nav-topbar.php` / `backend/index.php` — sectie "Wholesale"
  toegevoegd aan de bestaande topbar/subnav-structuur (géén linker menu, zie
  [[backend]] voor die afwijking t.o.v. de oorspronkelijke docs-beschrijving).

## Status / openstaande punten
- [x] **Fase A (skelet):** datamodel, backend-sectie + navigatie, alle
  pagina's draaien tegen de echte (nog lege) tabellen.
- [x] **Fase B (historische import):** `backend/wholesale/import.php` haalt
  Faire- én Orderchamp-orders op (cursor-gepagineerd, max. 50 per klik) en
  schrijft shop/order/regels weg, idempotent (herhaald importeren overschrijft
  dezelfde rijen i.p.v. te dupliceren). Beide platformen live geverifieerd
  (zie "Getest" hieronder). Raakt nooit `products.current_stock`/
  `cards.current_stock` (expliciet getest).
- [x] **Fase C (voorraad lezen):** `sku-comparison.php` heeft nu een
  "Vernieuw voorraadvergelijking"-knop (`WholesaleStockChecker`, alleen
  admins) die alle lokale SKU's (producten + kaarten) bij Faire én
  Orderchamp opzoekt en `product_platform_listings` bijwerkt
  (is_listed/last_seen_stock/last_verified_at). Live getest, zie "Getest"
  hieronder. Raakt nooit `products.current_stock`/`cards.current_stock`.
- [ ] **Fase D:** voorraad schrijven, eerst dry-run (loggen zonder echt te
  posten) achter de `sync_enabled`-schakelaar per platform.
- [ ] **Fase E:** nieuwe-order-webhooks (Faire + Orderchamp) → automatische
  import + voorraadaftrek. Hergebruikt `WholesaleOrderImporter`.
- [ ] **Fase F:** annuleringen → status omzetten + voorraad corrigeren.
- [ ] **Fase G:** live zetten per platform zodra alle producten er correct op
  staan (harde voorwaarde van de gebruiker, zie CLAUDE.md-conventie hierover
  niet apart vastgelegd maar wel leidend voor de bouwvolgorde).
- [x] Orderchamp: `ORDERCHAMP_ACCESS_TOKEN` staat in .env en
  `OrderchampService::fetchOrdersPage()` is live geverifieerd (86 echte
  orders correct geïmporteerd). Er is nog geen webhook-signing secret
  opgehaald - volgt bij het registreren van de webhook in fase E. Er ligt
  overigens al een oude, vermoedelijk dode webhook ("aniet_orders" →
  `aniet.nl/beta-5/orderchamp/order...`) uit een eerdere, gestopte koppeling
  in het Orderchamp-dashboard - die laten we met rust tot fase E.
- [x] Faire: orders-API (`GET /orders`) en retailer-profiel (`GET /retailers/
  public/{id}`) zijn alsnog geverifieerd — de OpenAPI-spec bleek uitleesbaar
  via developers.faire.com (zelfde `apidescriptiondocument`-DOM-attribuut-
  truc als bij de bestaande inventory-endpoint) én live getest met het
  bestaande FAIRE_ACCESS_TOKEN (200 OK, 87 echte orders succesvol
  geïmporteerd tijdens testen). Voorraad-terugschrijven (`PATCH
  /product-inventory/by-skus` bestaat, zie de spec) is nog niet gebruikt/
  getest - volgt in fase D.
- [ ] Geocoding van shopadressen (OpenStreetMap Nominatim) → `shops.lat/lng`.
  Let op: Faire levert ISO alpha-3 landcodes, Orderchamp alpha-2 - bewust
  ongenormaliseerd opgeslagen in `shops.country_code` (VARCHAR(3)).
- [ ] Periodieke reconciliatie-job (vangnet tegen gemiste webhooks) — hosting
  heeft hiervoor een cron-optie bevestigd.
- [ ] Deel 2 (B2B-webshop, zie "Doel" hierboven) — nog niet gestart, wacht op
  een go-beslissing en een niet-verwarrende naam naast deze sectie.

## Getest
**Fase A (2026-08-12):** losstaande, wegwerpbare MariaDB-container (niet de
live database) via `php -S`: login, alle nieuwe `backend/wholesale/`-pagina's
(200, geen PHP-fouten), zoeken/filteren op orders.php (shopnaam/SKU/titel,
platform, status, periode), de sync_enabled-schakelaar op settings.php, en
de kaartpagina (Leaflet + OpenStreetMap laden zonder consolefouten) met
seed-data (1 shop, 2 orders waarvan 1 geannuleerd, 2 producten, 3
platform-listings, 2 sync-logregels). Eén bug gevonden en gefixt: `ssl` is een
reserved word in MariaDB en kon niet als tabel-alias in
`StockSyncLogRepository` gebruikt worden (hernoemd naar `l`).

**Fase B (2026-08-12):** opnieuw een losstaande MariaDB-container, dit keer
met een **echte, read-only** import tegen de live Faire-API (bestaand
FAIRE_ACCESS_TOKEN), wegschrijvend naar de wegwerp-database - nooit naar de
live database. Resultaat: 87 echte orders + 57 echte shops correct
geïmporteerd over 2 paginabatches; herhaald importeren van dezelfde batch gaf
exact dezelfde aantallen (idempotent, geen duplicaten); een testkaart met een
echte, uit de import bekende SKU toegevoegd en opnieuw geïmporteerd liet
`card_id` correct invullen op alle regels met die SKU, terwijl
`cards.current_stock` ongewijzigd bleef (expliciet gecontroleerd vóór/na).
UI-check: orders.php/shops.php/sku-comparison.php renderen zonder PHP-fouten
met deze echte data, inclusief de multi-currency-fix (één order stond in USD;
de rest in EUR). Orderchamp-kant kon niet live getest worden (nog geen
token in .env) - alleen tegen de publieke schema-documentatie gebouwd.

**Orderchamp live verificatie (2026-08-12, zodra `ORDERCHAMP_ACCESS_TOKEN`
was ingesteld):** zelfde aanpak als bij Faire - eerst een losse `account`
query, toen de echte `fetchOrdersPage()`. Dit legde twee echte API-eigenaardigheden
bloot die niet uit de schema-docs te halen waren (zie ook de beslissingen
hieronder):
1. `since: null` als expliciete GraphQL-variabele leverde 0 resultaten op
   i.p.v. "geen filter" (totalCount 0 i.p.v. 86) - opgelost door `since`
   volledig weg te laten uit de query als er geen datum is.
2. De geneste `products`-connectie had zelf ook een `first`-argument nodig
   (Relay-connecties vereisen dat overal), en de combinatie 50 orders x 100
   productregels overschreed Orderchamp's max. query-cost (2000) met 5100 -
   opgelost door naar 30 productregels per order te gaan (kost 1600),
   gedetecteerd door zelf verschillende combinaties te testen tegen de echte
   API.
Na deze fixes: opnieuw een losstaande, wegwerpbare MariaDB-container, een
echte read-only import van 86 orders + 47 shops over 2 batches, dezelfde
idempotentie-/SKU-matching-/voorraad-ongewijzigd-checks als bij Faire, en
dezelfde UI-render-check - allemaal succesvol.

**Fase C (2026-08-12):** eerst `productVariants(skus: [...])` los tegen de
echte Orderchamp-API getest (werkt, kost triviaal - 100 SKU's kost 100 van de
2000-limiet). Daarna, tegen een nieuwe wegwerp-database, 3 testkaarten
aangemaakt met bekende SKU's uit de fase B-testdata (`20230904`, `231005`,
en een verzonnen niet-bestaande SKU) en de "Vernieuw voorraadvergelijking"-
knop echt uitgevoerd tegen zowel Faire als Orderchamp. Resultaat klopte
precies: `20230904` bleek bij Faire een afwijkende voorraad te hebben (105 vs.
onze 30) en bij Orderchamp exact te matchen; `231005` bleek bij Faire
inmiddels **niet meer gevonden** te worden (zie beslissing hieronder) maar
bij Orderchamp nog wel (voorraad 0, wijkt af van onze 999); de verzonnen SKU
correct "niet geplaatst" op beide. Idempotent (herhaald draaien gaf exact
dezelfde 6 rijen, geen duplicaten) en `cards.current_stock` bleef bij alle
runs ongewijzigd (expliciet gecontroleerd). Ook getest: een niet-admin krijgt
403 op de knop en ziet de knop niet eens.

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

- **Beslissing:** `wholesale_order_items`/`product_platform_listings` matchen
  op zowel `products` als `cards` (nieuwe nullable `card_id`-kolom naast
  `product_id`, precies één van beide gezet).
  **Waarom:** de bestaande Faire-voorraadsync matcht ook al op beide tabellen
  - zonder deze uitbreiding zouden alle wholesale-orderregels met een
  kaart-SKU permanent als "niet gematcht" verschijnen. Toegevoegd via
  `sql/migrations/006_wholesale_card_support.sql`.
  **Datum:** 2026-08-12
- **Beslissing:** Faire's order-`display_id` (bv. "3FVB9TN5XE") als
  `external_order_id` gebruikt, niet de opaque `id` (bv. "bo_3fvb9tn5xe").
  **Waarom:** `display_id` is wat de gebruiker ook in Faire's eigen
  brand-portal ziet - herkenbaarder in het backend-orderoverzicht dan het
  interne API-ID. Beide zijn gegarandeerd uniek (display_id is een
  deterministische afleiding van id).
  **Datum:** 2026-08-12
- **Beslissing:** `wholesale_orders.total_amount_cents` voor Faire wordt
  berekend als som van `item.price × quantity` over alle regels, niet
  overgenomen van een kant-en-klaar totaalveld.
  **Waarom:** Faire's ordermodel heeft geen simpel "ordertotaal"-veld (alleen
  `payout_costs` - de nettouitkering aan het merk NA commissie/fees, een
  ander getal). Dit is dus een benadering die verzendkosten/belasting/
  kortingen buiten beschouwing laat; prima voor het huidige overzicht/kaart-
  gebruik, maar niet 1-op-1 gelijk aan wat Faire zelf als "totaal" toont.
  **Datum:** 2026-08-12
- **Beslissing:** geen hardcoded "€" meer in de wholesale-pagina's; een
  nieuwe `money()`-helper toont het bedrag met de echte valuta van de order.
  **Waarom:** tijdens het bouwen van fase B bleek een deel van de echte
  Faire-orders in USD te staan terwijl de fase A-UI overal een hardcoded €
  liet zien (en de omzet-som in orders.php telde EUR en USD-bedragen zomaar
  bij elkaar op) - alsnog gecorrigeerd, ook in de al gecommitte fase A-code.
  **Datum:** 2026-08-12
- **Beslissing:** in `OrderchampService::fetchOrdersPage()` wordt het
  `since`-argument volledig uit de GraphQL-query weggelaten als er geen
  datumfilter is, i.p.v. het als variabele op `null` te zetten; en de
  geneste `products`-connectie is vastgezet op `first: 30` (met
  `hasNextPage` gelogd als een order daar toch doorheen zou gaan).
  **Waarom:** allebei ontdekt door live tegen de echte API te testen, niet
  uit de schema-documentatie af te leiden. `since: null` leverde bij
  Orderchamp een lege resultatenset op i.p.v. "geen filter" (een
  API-eigenaardigheid, geen bug in onze code). En `products` zonder eigen
  `first` gaf een validatiefout ("You must provide one of first or last"),
  terwijl 50 orders x 100 productregels de max. query-cost (2000) met 5100
  overschreed - 30 regels per order blijft ruim onder die limiet (1600) en is
  ruim genoeg voor dit soort kaarten/cadeau-bestellingen.
  **Datum:** 2026-08-12
- **Bevinding (geen bug, wel belangrijk):** een SKU die in een *historische*
  Faire-order voorkwam, kan bij een *actuele* voorraadcheck alsnog "niet
  geplaatst" opleveren.
  **Waarom:** Faire's eigen documentatie zegt het expliciet bij
  `order.items[].sku`: "This may not match the current SKU of the variant" -
  order-items bevatten de SKU op het moment van aankoop, niet de huidige SKU.
  Tijdens het testen van fase C bleek dit ook echt voor te komen (SKU
  `231005`, wel in fase B-orderdata, niet meer terug te vinden via
  `product-inventory/by-skus`). Geen actie nodig, maar goed om te weten bij
  het interpreteren van "niet geplaatst" in `sku-comparison.php`: dat kan ook
  betekenen "SKU is bij Faire hernoemd", niet alleen "nooit geplaatst".
  **Datum:** 2026-08-12

## Zie ook
[[products]], [[orders]], [[backend]]
