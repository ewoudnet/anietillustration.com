# Module: wholesale

## Doel
Twee te onderscheiden delen onder één sectienaam:

1. **Faire + Orderchamp-synchronisatie (in opbouw, fase A-E/F afgerond)** — de
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
- `src/OrderchampService.php` — GraphQL-client (`request()` + `isConfigured()`
  + `fetchOrdersPage()` + `fetchInventoryBySkus()` + `updateInventoryBySkus()`),
  schema geverifieerd via developers.orderchamp.com, orders/inventory-lezen
  inmiddels ook live getest (zie "Getest" hieronder) - alleen het schrijf-pad
  (`updateInventoryBySkus()`, fase D) is nog niet live getest, bewust (zie
  Beslissingen).
- `src/FaireService.php` — uitgebreid met `fetchOrdersPage()` en
  `fetchRetailer()`, beide live geverifieerd tegen de echte Faire-API.
- `src/SkuResolver.php` — matcht een externe SKU tegen `cards` óf `products`
  (zelfde twee-tabellen-aanpak als de bestaande Faire-voorraadsync).
- `src/WholesaleOrderImporter.php` — normaliseert Faire/Orderchamp-orders naar
  het eigen model en schrijft ze weg (shop + order + regels); hergebruikt
  voor zowel de historische import (fase B) als de live nieuwe-order-detectie
  (fase E). Roept `WholesaleStockDeductionService` alleen aan als de
  aanroeper `$deductStock=true` meegeeft.
- `src/WholesaleStockDeductionService.php` — fase E: schrijft voorraad af bij
  een nieuwe order, boekt terug bij annulering, idempotent via
  `wholesale_orders.stock_deducted_at`. Logt naar `stock_sync_log` met
  `direction='inbound'`.
- `backend/wholesale/cron-faire.php` — publiek, secret-gated endpoint (fase
  E) dat de Faire-cronpoll uitvoert - Faire heeft geen webhook-API.
- `backend/wholesale/webhook-orderchamp.php` — publiek, signature-gated
  endpoint (fase E) dat Orderchamp's order-webhook ontvangt. Nog niet
  geregistreerd bij Orderchamp, zie Beslissingen.
- `src/OrderchampService.php` — `fetchInventoryBySkus()` toegevoegd, zelfde
  signatuur/semantiek als `FaireService::fetchInventoryBySkus()`, live
  geverifieerd.
- `src/WholesaleStockChecker.php` — fase C: leest voorraad bij Faire +
  Orderchamp voor alle lokale SKU's (producten + kaarten) en schrijft de
  vergelijking naar `product_platform_listings`. Roept nooit
  `*Repository::updateCurrentStock()` aan.
- `src/WholesaleStockSyncService.php` — fase D: schrijft voorraad terug naar
  Faire/Orderchamp voor items die daar als "geplaatst" bekend staan
  (`product_platform_listings.is_listed`) met een afwijkende
  `last_seen_stock` t.o.v. de eigen `current_stock`. Blijft een proefdraai
  (alleen loggen) zolang `wholesale_platforms.sync_enabled` op 0 staat voor
  dat platform; roept dan `FaireService`/`OrderchampService::
  updateInventoryBySkus()` nooit aan. Elke run schrijft een regel per item
  naar `stock_sync_log` (via de nieuwe `StockSyncLogRepository::log()`),
  met `dry_run` als onderscheid tussen proefdraai en echt verstuurd. Raakt
  nooit `products.current_stock`/`cards.current_stock` (die blijven altijd
  leidend, dit is uitsluitend uitgaande synchronisatie).
- `sql/migrations/007_wholesale_stock_write.sql` (+ schema.sql) —
  `stock_sync_log.dry_run` (fase D).
- `sql/migrations/008_wholesale_stock_deduction.sql` (+ schema.sql) —
  `wholesale_orders.stock_deducted_at` + `wholesale_platforms.last_synced_at`
  (fase E).
- `backend/wholesale/` — `index.php` (dashboard), `orders.php` +
  `order-form.php` (overzicht + detail, zoeken op shopnaam/SKU/titel,
  filter op platform/status/periode), `orders-export.php` (Excel, zelfde
  `XlsxWriter` als [[orders]]), `import.php` (historische import, per pagina
  van max. 50 orders, alleen voor admins), `shops.php` (kaart met Leaflet +
  OpenStreetMap-tiles), `sku-comparison.php` (matrix producten+kaarten ×
  platformen, met een "Vernieuw voorraadvergelijking"-knop die
  `WholesaleStockChecker` aanroept, en een "Synchroniseer voorraad naar
  platformen"-knop die `WholesaleStockSyncService` aanroept - fase D, alleen
  voor admins), `sync-log.php`
  (auditlog-viewer, toont een 🧪 Proefdraai-badge voor `dry_run`-regels i.p.v.
  de normale OK/Mislukt-badge), `settings.php` (per-platform sync-aan/uit-schakelaar,
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
- [x] **Fase D (dry-run):** `sku-comparison.php` heeft nu ook een
  "Synchroniseer voorraad naar platformen"-knop (`WholesaleStockSyncService`,
  alleen admins) die de eigen voorraad terugschrijft naar Faire/Orderchamp
  voor elk item dat daar als "geplaatst" bekend staat met een afwijkende
  `last_seen_stock`. Zolang `wholesale_platforms.sync_enabled` op 0 staat
  (nu voor beide platformen het geval) is dit een proefdraai: er wordt alleen
  naar `stock_sync_log` gelogd (met `dry_run=1`), nooit echt naar Faire/
  Orderchamp gepost. `FaireService::updateInventoryBySkus()` (PATCH
  `/product-inventory/by-skus`) en `OrderchampService::
  updateInventoryBySkus()` (`inventoryLevelBulkAdjust`-mutatie) zijn wel al
  volledig gebouwd en schema-geverifieerd, maar bewust **nog niet live
  getest** - dat zou een echte voorraadwijziging op de live listings
  betekenen. Zie "Getest" en de beslissing hieronder voor waarom dat een
  bewuste, aparte stap blijft i.p.v. hier al uitgevoerd.
- [x] **Fase E (+ F samengevoegd):** live nieuwe-order-detectie +
  voorraadaftrek, én annulering + voorraad-terugboeking - zie de beslissing
  hieronder over waarom die twee niet los te bouwen waren.
  - **Faire heeft GEEN webhook-API** - geverifieerd door de volledige
    OpenAPI-spec te doorzoeken (geen enkel webhook/notification-pad, alleen
    de 26 REST-endpoints uit "Belangrijkste bestanden" hierboven). In plaats
    daarvan: `backend/wholesale/cron-faire.php`, een publiek, secret-gated
    endpoint (`?secret=` tegen `WHOLESALE_CRON_SECRET`, `hash_equals()`) dat
    periodiek (via de hosting-cron, geen SSH/CLI-cron nodig) `fetchOrdersPage()`
    aanroept met `created_at_min` = het hoogwatermerk uit
    `wholesale_platforms.last_synced_at` (vastgelegd bij de START van de run).
    Eerste run zonder hoogwatermerk kijkt 1 dag terug.
  - **Orderchamp** krijgt wel een echte webhook:
    `backend/wholesale/webhook-orderchamp.php`, geverifieerd via
    `X-Orderchamp-Signature` (HMAC-SHA256 met `ORDERCHAMP_WEBHOOK_SECRET`).
    De payload is bewust minimaal (`{"data":{"order":{"id",...}}}`), dus het
    endpoint haalt de volledige order opnieuw op via de nieuwe
    `OrderchampService::fetchOrderById()` i.p.v. op de payload-inhoud te
    vertrouwen.
  - **`WholesaleStockDeductionService`** (nieuw) schrijft voorraad AF zodra
    een order voor het eerst gezien wordt met een niet-geannuleerde status,
    en BOEKT TERUG zodra een eerder afgeschreven order alsnog geannuleerd
    wordt - idempotent via de nieuwe `wholesale_orders.stock_deducted_at`
    (NULL = nog niet afgeschreven). Gebruikt de nieuwe atomische
    `ProductRepository`/`CardRepository::adjustCurrentStock()` (`current_stock
    = current_stock + ?`, i.p.v. lees-dan-schrijf) zodat een webhook en een
    cron-run elkaar niet kunnen overschrijven. Logt elke aanpassing naar
    `stock_sync_log` met `direction='inbound'` (de omgekeerde richting van
    fase D's `outbound`).
  - `WholesaleOrderImporter::importFairePage()/importOrderchampPage()`
    kregen een `$deductStock`-parameter (default `false`) - de historische
    import (fase B, `import.php`) geeft dit bewust nooit mee, want die orders
    zijn al lang fysiek verwerkt. Nieuw: `importOrderchampOrderById()` voor
    het webhook-pad.
  - **Nog NIET gedaan (bewust, zie Beslissingen):** de Orderchamp-webhook
    daadwerkelijk registreren bij Orderchamp (`webhookCreate`-mutatie) - dat
    is een wijziging bij een externe partij. Zolang dat niet gebeurt, komt er
    ook geen enkele live aanroep op `webhook-orderchamp.php` binnen en blijft
    de Faire-cron ongebruikt totdat de hosting-cron ook echt ingesteld wordt.
- [ ] **Fase G:** live zetten per platform zodra alle producten er correct op
  staan (harde voorwaarde van de gebruiker, zie CLAUDE.md-conventie hierover
  niet apart vastgelegd maar wel leidend voor de bouwvolgorde).
- [x] Orderchamp: `ORDERCHAMP_ACCESS_TOKEN` staat in .env en
  `OrderchampService::fetchOrdersPage()` is live geverifieerd (86 echte
  orders correct geïmporteerd).
- [ ] Orderchamp-order-webhook echt registreren (`webhookCreate`-mutatie,
  events `[ORDER_CONFIRMED, ORDER_UPDATED, ORDER_CANCELLED]` - niet
  `ORDER_CREATED`, zie Orderchamps eigen advies om alleen bevestigde orders
  te verwerken) en het bijbehorende `ORDERCHAMP_WEBHOOK_SECRET` vaststellen.
  Twee dingen zijn daarbij nog onbevestigd: (1) Orderchamps eigen docs zeggen
  "Contact us at support@orderchamp.com so we can create an API token for
  you" voor order-webhooks - onduidelijk of ons bestaande
  ORDERCHAMP_ACCESS_TOKEN (private-app-token) hiervoor al volstaat; (2) de
  signing-secret voor een private-app (geen apart OAuth-`client_secret`) is
  niet expliciet gedocumenteerd. Bewust aan de gebruiker gelaten (wijziging
  bij een externe partij), zie Beslissingen. Er ligt overigens al een oude,
  vermoedelijk dode webhook ("aniet_orders" → `aniet.nl/beta-5/orderchamp/
  order...`) uit een eerdere, gestopte koppeling in het Orderchamp-
  dashboard - die laten we met rust, los van dit punt.
- [ ] Hosting-cron daadwerkelijk instellen voor `backend/wholesale/
  cron-faire.php?secret=...` (bv. elke 15 minuten) - de code is klaar en
  getest, maar er draait nog geen cron-taak.
- [x] Faire: orders-API (`GET /orders`) en retailer-profiel (`GET /retailers/
  public/{id}`) zijn alsnog geverifieerd — de OpenAPI-spec bleek uitleesbaar
  via developers.faire.com (zelfde `apidescriptiondocument`-DOM-attribuut-
  truc als bij de bestaande inventory-endpoint) én live getest met het
  bestaande FAIRE_ACCESS_TOKEN (200 OK, 87 echte orders succesvol
  geïmporteerd tijdens testen). Voorraad-terugschrijven (`PATCH
  /product-inventory/by-skus`, nu gebouwd als `FaireService::
  updateInventoryBySkus()`, fase D) is schema-geverifieerd maar bewust nog
  niet live getest - zie Beslissingen.
- [ ] Geocoding van shopadressen (OpenStreetMap Nominatim) → `shops.lat/lng`.
  Let op: Faire levert ISO alpha-3 landcodes, Orderchamp alpha-2 - bewust
  ongenormaliseerd opgeslagen in `shops.country_code` (VARCHAR(3)).
- [x] ~~Periodieke reconciliatie-job (vangnet tegen gemiste webhooks)~~ —
  ingehaald door fase E: voor Faire is de cron-poller (`cron-faire.php`) de
  hoofdroute (geen webhook-alternatief), dus niet langer alleen een vangnet.
  Voor Orderchamp kan dezelfde poller later als vangnet tegen gemiste
  webhooks hergebruikt worden door `importOrderchampPage(..., true)` er ook
  in op te nemen - nu nog niet gedaan (Orderchamp-kant leunt voorlopig
  volledig op de webhook).
- [ ] Deel 2 (B2B-webshop, zie "Doel" hierboven) — nog niet gestart, wacht op
  een go-beslissing en een niet-verwarrende naam naast deze sectie.

## Getest
**Fase E (2026-08-12):** eerst uitgezocht of Faire een webhook-API heeft
(nee - de volledige OpenAPI-spec doorzocht, geen "webhook" te vinden, alleen
de bekende 26 REST-paden) en hoe Orderchamp's order-webhook precies werkt
(developers.orderchamp.com/manage-orders-fulfilment: minimale payload,
headers `X-Orderchamp-Event`/`X-Orderchamp-Signature`, en het advies om
alleen `ORDER_CONFIRMED`/`ORDER_UPDATED`/`ORDER_CANCELLED` te gebruiken, niet
`ORDER_CREATED`, omdat onbevestigde orders nog kunnen wijzigen).

Daarna, whitebox tegen een nieuwe wegwerp-database (zelfde stub-aanpak als
fase D): via PHP Reflection rechtstreeks `WholesaleOrderImporter::
normalizeFaireOrder()/normalizeOrderchampOrder()/persist()` aangeroepen met
synthetische order-payloads in exact de vorm die de al eerder live-
geverifieerde `fetchOrdersPage()`/`fetchOrderById()` teruggeven - dus zonder
de echte Faire/Orderchamp-API aan te roepen (die orders bestaan niet echt).
Dit toetst de nieuwe fase-E-logica op basis van al bewezen normalisatie:
- Nieuwe Faire-order (3x een testproduct, `deductStock=true`): voorraad
  correct afgeschreven (20 → 17), `stock_deducted_at` gezet,
  `stock_sync_log`-regel met `direction=inbound, trigger_type=order_placed`.
- Dezelfde order nogmaals persisten (dubbele cron-run/webhook-retry):
  voorraad bleef exact 17 - geen dubbele afschrijving.
- Order alsnog geannuleerd (`state=CANCELED`): voorraad terug naar 20,
  `stock_deducted_at` terug naar NULL, logregel met `trigger_type=
  order_canceled`.
- Herhaalde annulering: voorraad bleef 20 - geen dubbele terugboeking.
- Nieuwe Orderchamp-order met een kaart-SKU (2x): voorraad correct
  afgeschreven (30 → 28) - bevestigt dat de kaart-tak (naast producten)
  ook werkt.
- Order met een onbekende SKU: geen crash, correct als "niet gematcht"
  gerapporteerd, geen (onterechte) voorraadaanpassing of logregel voor die
  regel.
- Dezelfde nieuwe Faire-order via `deductStock=false` (het historische-
  import-pad): voorraad bleef ongewijzigd - bevestigt dat fase B's import.php
  ook na deze wijziging nooit voorraad aanpast.

Daarna de twee nieuwe publieke endpoints via een draaiende `php -S` (met de
echte `.env` tijdelijk vervangen en na afloop byte-voor-byte teruggezet):
- `cron-faire.php`: geen/verkeerd secret → 403; correct secret maar Faire niet
  geconfigureerd → nette 500 met duidelijke foutmelding (geen crash).
- `webhook-orderchamp.php`: GET → 405; POST zonder signature → 401; POST met
  een écht berekende HMAC-SHA256-signature (`openssl dgst -sha256 -hmac`)
  tegen een testsecret → signature-check slaagt en de aanroep komt door tot
  de Orderchamp-credential-check (nette 500, want geen token in de
  testomgeving) - bevestigt dat de hele keten (signature → JSON parsen →
  order-id → `importOrderchampOrderById()`) intact is; POST met een foute
  signature → 401.

**Bewust niet getest:** een echte, live Orderchamp-webhookaanroep (die
bestaat nog niet - zie openstaande punten) en een echte cron-aanroep op de
hosting zelf (die moet daar nog ingesteld worden).

**Fase D (2026-08-12):** eerst de exacte requestvorm van beide schrijf-
endpoints opgezocht tegen de echte, publieke bronnen (niet uit losse
blogposts/derde partijen, die bleken deels af te wijken - zie de beslissing
hieronder): Faire's PATCH `/product-inventory/by-skus` via dezelfde
`apidescriptiondocument`-attribuut-truc als de bestaande endpoints (levert
`on_hand_quantity`, niet het elders gesuggereerde `current_quantity`), en
Orderchamp's `inventoryLevelBulkAdjust`-mutatie via de publieke
schema-referentie (developers.orderchamp.com/manage-inventory +
.../mutations/inventoryLevelBulkAdjust) - `action: SET` is verplicht om een
absolute waarde te zetten, anders is `adjustment` relatief.

Daarna, tegen een nieuwe wegwerp-database (ditmaal ook met wegwerp-stubs voor
`products`/`cards`/`product_types`/`sales_channels`/`users`/`sections`, omdat
dit keer ook de backend-UI zelf gedraaid moest worden): 1 testproduct en 1
testkaart met een bewust afwijkende `product_platform_listings.last_seen_stock`
(product bij Faire, kaart bij Orderchamp), plus een niet-afwijkende en een
niet-geplaatste listing als negatieve controle. Drie testruns van
`WholesaleStockSyncService::run()` rechtstreeks (buiten de UI om):
1. Beide platformen op `sync_enabled=0` → beide items correct als proefdraai
   gelogd (`dry_run=1`, `success=1`, `old_stock`/`new_stock` correct), de
   niet-afwijkende en niet-geplaatste listing terecht overgeslagen,
   `products.current_stock`/`cards.current_stock` ongewijzigd.
2. Herhaald zonder tussentijdse wijziging → exact dezelfde uitkomst
   (idempotent; een proefdraai past `product_platform_listings` niet aan, dus
   blijft de afwijking bestaan totdat een echte sync of een nieuwe
   fase-C-check die bijwerkt).
3. Faire op `sync_enabled=1` gezet maar zonder credentials → nette
   `RuntimeException` ("credentials nog niet ingesteld"), gelogd met
   `success=0, dry_run=0` en de foutmelding in `error_message`, geen crash,
   `product_platform_listings` niet aangepast. Orderchamp (nog steeds
   `sync_enabled=0`) draaide in dezelfde run gewoon door als proefdraai -
   bevestigt dat een fout op het ene platform het andere niet blokkeert.

Daarna dezelfde scenario's via de echte backend-UI (`php -S` met een
tijdelijke, teruggezette `.env` naar de wegwerp-database - de originele
`.env` vooraf gehasht en na afloop byte-voor-byte teruggezet): login,
`sku-comparison.php` toont de juiste "Geplaatst"/"Voorraad wijkt
af"/"Niet geplaatst"-badges, de nieuwe "Synchroniseer voorraad naar
platformen"-knop toont zowel de 🧪 proefdraai-melding (Orderchamp) als de
live-foutmelding (Faire, geen credentials) na één druk op de knop, en
`sync-log.php` toont de nieuwe 🧪 Proefdraai-badge naast de bestaande
OK/Mislukt-badges. Geen PHP-fouten op `sku-comparison.php`, `sync-log.php` of
`settings.php`.

**Bewust niet getest:** een echte PATCH/mutatie-aanroep tegen de live Faire-
of Orderchamp-API. In tegenstelling tot de read-only endpoints uit fase
A/B/C zou dat een echte wijziging in een extern systeem betekenen (mogelijk
zichtbaar voor echte retailers) - dat vraagt om een bewuste, aparte test met
een enkele lage-impact-SKU zodra de gebruiker daarvoor kiest, niet iets om
terloops tijdens het bouwen te doen.

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

- **Beslissing:** `stock_sync_log` kreeg een losse `dry_run`-kolom
  (migratie 007) i.p.v. proefdraaien te herkennen aan bijv. een speciale
  `trigger_type` of misbruik van `error_message`.
  **Waarom:** een proefdraai is geen fout (`success` blijft 1) en geen apart
  soort aanleiding (`trigger_type` blijft `reconciliation`, dezelfde
  discrepantie-detectie als een echte sync) - het is een orthogonale
  eigenschap ("is dit echt verstuurd?"). Een aparte kolom houdt de
  log-viewer (`sync-log.php`) en toekomstige rapportages eenvoudig, i.p.v. op
  stringpatronen in `error_message` te moeten matchen.
  **Datum:** 2026-08-12
- **Beslissing:** de daadwerkelijke PATCH/mutatie-aanroepen naar Faire
  (`on_hand_quantity`) en Orderchamp (`inventoryLevelBulkAdjust` met
  `action: SET`) zijn gebouwd en schema-geverifieerd, maar bewust nog niet
  tegen de live API getest.
  **Waarom:** read-only endpoints live testen (fase A/B/C) is veilig omdat er
  niets wijzigt; een schrijf-endpoint testen betekent per definitie een
  echte voorraadwijziging op een marktplaats-listing die retailers kunnen
  zien. Dat hoort een bewuste, geïsoleerde stap te zijn (één lage-impact-SKU,
  vooraf en achteraf gecontroleerd) wanneer de gebruiker daarvoor kiest, niet
  iets wat impliciet meelift in het bouwen van fase D. Zolang
  `sync_enabled=0` blijft (de huidige productiestatus voor beide platformen)
  raakt de code dit pad sowieso nooit aan.
  **Datum:** 2026-08-12
- **Bevinding (geen bug, wel belangrijk):** losse, niet-officiële bronnen
  over de Faire-schrijfAPI (blogposts, derde-partij-tools) noemen het veld
  `current_quantity`; de echte, actuele OpenAPI-spec
  (`apidescriptiondocument`-attribuut, zie de bestaande truc in
  `FaireService`) zegt `on_hand_quantity`.
  **Waarom:** verklaart waarom losse voorbeelden op internet niet blind
  overgenomen zijn voor deze integratie - steeds teruggevallen op de
  ingebedde spec zelf, net als bij de eerder gedocumenteerde SKU-query-
  parameter-eigenaardigheid.
  **Datum:** 2026-08-12

- **Beslissing:** fase E en fase F (annuleringen) zijn samengevoegd i.p.v.
  apart gebouwd.
  **Waarom:** voorraad afschrijven bij een nieuwe order kan niet los van
  voorraad terugboeken bij een annulering - zonder de terugboek-kant zou een
  geannuleerde order de voorraad permanent te laag laten staan. Beide horen
  bij dezelfde `WholesaleStockDeductionService::reconcile()`-beslisboom
  (nieuw/nog niet afgeschreven → afschrijven; geannuleerd/al afgeschreven →
  terugboeken), dus apart bouwen zou kunstmatig zijn geweest.
  **Datum:** 2026-08-13
- **Beslissing:** voorraad wordt afgeschreven zodra een order voor het eerst
  gezien wordt met een niet-geannuleerde status (dus ook bij "open"/nog
  onbevestigd), niet pas zodra deze bevestigd is.
  **Waarom:** dit is het "gereserveerd/committed"-model (vergelijkbaar met
  Faire's eigen `committed_quantity` naast `on_hand_quantity`/
  `available_quantity`, gezien tijdens fase D) - bedoeld om overselling op
  een ANDER kanaal te voorkomen zolang deze order nog loopt. Bekende
  afruil: een order die voor altijd in "open" blijft hangen zonder ooit
  bevestigd óf geannuleerd te worden, houdt de voorraad voorgoed te laag -
  in de praktijk zeldzaam, en zichtbaar te herstellen via de bestaande
  fase D/C-cyclus (opnieuw voorraad vergelijken/synchroniseren) als het toch
  gebeurt.
  **Datum:** 2026-08-13
- **Beslissing:** Faire's nieuwe-orders-detectie loopt via een secret-gated
  cron-URL (`cron-faire.php?secret=...`), niet via `Auth::requireSection()`.
  **Waarom:** dit endpoint wordt aangeroepen door de hosting-cron, niet door
  een ingelogde gebruiker - een sessie/login-eis zou een cron-aanroep
  onmogelijk maken. Hetzelfde patroon (publiek endpoint, eigen verificatie
  i.p.v. sessie) als het bestaande `specials/webhook.php` voor Mollie.
  **Datum:** 2026-08-13
- **Beslissing:** de Orderchamp-webhook haalt bij binnenkomst altijd de
  volledige order opnieuw op via `OrderchampService::fetchOrderById()`,
  i.p.v. te vertrouwen op de payload-inhoud.
  **Waarom:** Orderchamps eigen documentatie zegt expliciet dat de
  webhook-payload minimaal is (alleen id/number/createdAt/updatedAt) en dat
  je de actuele order via de API moet ophalen - een aanname over een rijkere
  payload zou hier dus sowieso fout zijn geweest.
  **Datum:** 2026-08-13
- **Beslissing:** de Orderchamp-webhook daadwerkelijk registreren
  (`webhookCreate`) en het bijbehorende secret vaststellen, is bewust NIET in
  deze sessie gedaan.
  **Waarom:** dat is een wijziging bij een externe partij (een nieuwe
  standing-integratie die begint met live orderdata naar onze server te
  sturen), en de exacte signing-secret voor ons type token (private-app,
  geen OAuth-`client_secret`) staat niet expliciet in de documentatie -
  eerst navragen/uitproberen hoort een bewuste, aparte stap te zijn, niet iets
  wat impliciet meelift in het bouwen van de ontvanger. De ontvanger zelf is
  wel al klaar en getest (zie "Getest") zodra dat moment aanbreekt.
  **Datum:** 2026-08-13
- **Bevinding (geen bug, wel belangrijk):** Faire's External API heeft geen
  webhook/notification-mechanisme.
  **Waarom:** geverifieerd door de volledige, actuele OpenAPI-spec te
  doorzoeken (dezelfde `apidescriptiondocument`-attribuut-truc als bij de
  andere Faire-endpoints) - alleen de bekende REST-paden zijn aanwezig, geen
  enkel pad of schema gerelateerd aan webhooks. Dit was de reden om voor
  Faire een cron-poller te bouwen i.p.v. een webhook-ontvanger, in
  tegenstelling tot wat de oorspronkelijke fase-planning ("nieuwe-order-
  webhooks (Faire + Orderchamp)") veronderstelde.
  **Datum:** 2026-08-13
- **Bekende beperking:** als een order NA de eerste afschrijving nog van
  regels/aantallen verandert (bv. Orderchamp's `ORDER_UPDATED`-event op een
  nog niet bevestigde order), wordt de voorraadafschrijving niet bijgewerkt -
  `WholesaleOrderRepository::replaceItems()` vervangt de regels altijd, maar
  `reconcile()` kijkt alleen naar de status (afgeschreven ja/nee), niet naar
  wijzigingen in de regels zelf.
  **Waarom:** volledige item-niveau-diffing (welke regel is toegevoegd/
  verwijderd/in aantal gewijzigd t.o.v. de vorige afschrijving) is aanzienlijk
  complexer en niet gevraagd voor deze fase; Orderchamps eigen advies om
  alleen bevestigde orders te verwerken (zie hierboven) beperkt hoe vaak dit
  in de praktijk voorkomt.
  **Datum:** 2026-08-13

## Zie ook
[[products]], [[orders]], [[backend]]
