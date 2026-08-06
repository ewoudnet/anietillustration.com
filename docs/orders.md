# Module: orders (fase 1, uitgebreid in wholesale-fase)

## Doel
Centraal order- en betaalsysteem: Mollie-integratie, webhookverwerking,
orderoverzicht met filter op special, en Excel-export. In fase 1 alleen voor
specials-orders; in de wholesale-fase uitgebreid met wholesale-orders
(inclusief PDF-orderbevestiging en factuur).

## Belangrijkste bestanden/locaties
- `src/OrderRepository.php`, `src/OrderValidator.php`, `src/MollieService.php`,
  `src/Mailer.php`, `src/PaymentStatusSync.php` — generiek voor `order_type`
  (nu alleen `special`), adaptatie van de gelijknamige klassen in
  `adventskaarten-bestellen/src`
- `backend/specials/orders.php` — overzicht + filter (zoeken, status,
  special)
- `backend/specials/orders-export.php` — Excel-export van de gefilterde set
  (`src/XlsxWriter.php`, dependency-vrij via `ZipArchive`)
- `specials/process-order.php` / `webhook.php` / `success.php` — publieke
  betaalflow

## Status / openstaande punten
- [x] Orderoverzicht in backend met filter op special (+ status + zoeken)
- [x] Alleen-lezen overzicht van bestaande `advent_orders` (oud
  `adventskaarten-bestellen`-systeem) onderaan hetzelfde orderoverzicht —
  `src/AdventOrderRepository.php`, geen filter/export
- [x] Excel-export van gefilterde orders
- [x] Mollie-betaalflow + webhook (naar voorbeeld `/advent`)
- [x] Bevestigingsmail bij betaling (PHPMailer), idempotent via
  `confirmation_email_sent_at`
- [ ] Later (wholesale-fase): PDF-orderbevestiging (thumb/titel/sku/aantal
  voor order picking) + factuur-PDF na afronding, koppeling met
  trackingstatus

## Getest (lokaal, 2026-08-06)
Volledige flow doorlopen met `php -S` (backend op :8001, specials op :8002)
en een lokale MariaDB: special aanmaken met 2 prijsvarianten → bestelling
plaatsen op de publieke pagina → order verschijnt met status `open` in
`orders` (Mollie-call faalt zoals verwacht zonder een echte test-API-key,
foutafhandeling vangt dit netjes op) → order zichtbaar + filterbaar in
`backend/specials/orders.php` → Excel-export gedownload en geverifieerd
(geldig .xlsx, juiste kolommen/inhoud).

## Beslissingen & rationale
- **Beslissing:** één centraal ordersysteem voor specials én (later)
  wholesale, met een kolom/type om onderscheid te maken.
  **Waarom:** zelfde patroon als `adventskaarten-bestellen`, dat advent en
  kalender2027 al in één orders-tabel met `product_type` combineert —
  voorkomt duplicatie van betaal- en exportlogica.
  **Datum:** 2026-08-06
- **Beslissing:** bestaande `advent_orders` alleen-lezen tonen in hetzelfde
  orderoverzicht, geen aparte DB-connectie.
  **Waarom:** `anietillustration.com` en `adventskaarten-bestellen` draaien
  op dezelfde hosting-database (zelfde `DB_NAME`/`DB_USER` in beide
  `.env`-bestanden), dus `advent_orders` is zonder extra config bereikbaar
  via de bestaande `Database`-connectie. Schrijven blijft voorbehouden aan
  het bestaande advent-systeem (zie CLAUDE.md).
  **Datum:** 2026-08-06
