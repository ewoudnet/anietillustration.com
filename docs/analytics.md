# Module: analytics

## Doel
Inzicht in bezoekers, orders en omzet: per special in de backend
(vergelijkbaar met de bestaande `adventskaarten-bestellen/admin/stats.php`)
en algemene sitestatistieken via Google Analytics.

## Belangrijkste bestanden/locaties
- `src/TrafficSource.php` — bronherkenning (utm_source/referrer) + first-touch
  cookies (`specials_src`/`specials_sid`), adaptatie van
  `adventskaarten-bestellen/src/TrafficSource.php`
- `src/PageViewRepository.php` — logt paginaweergaves in `page_views` en
  levert bron-statistieken (bezoeken/unieke bezoekers)
- `src/OrderRepository::statsBySource()` — orders/omzet per bron
  (`traffic_source`, alleen `source='online'`, `deleted_at IS NULL`)
- `specials/index.php` — logging-aanroep bij elk publiek paginabezoek
  (overzicht én special-detail)
- `backend/specials/stats.php` — periodefilter (vandaag/7d/30d/altijd) +
  special-filter, combineert paginaweergaves en orderstatistieken per bron
- `sql/migrations/003_add_order_soft_delete_and_traffic_source.sql` /
  `004_add_page_views.sql`

## Status / openstaande punten
- [x] Statistieken per special in backend (paginaweergaves, orders, omzet,
  periodefilter — naar voorbeeld `advent/admin/stats.php`)
- [ ] Google Analytics uitlezen/tonen in backend (GA4 API), aanpak nog te
  onderzoeken

## Getest (lokaal, 2026-08-09)
Lokale MariaDB + `php -S` (backend :8001, specials :8002): publieke
specials-pagina's bezocht (overzicht + special-detail, met en zonder
`utm_source`) → juiste rijen in `page_views` (first-touch bron, correcte
`special_id`) → `backend/specials/stats.php` toont bezoeken/unieke
bezoekers/omzet correct gegroepeerd per bron.

## Beslissingen & rationale
- **Beslissing:** eigen cookienamen (`specials_src`/`specials_sid`) i.p.v. de
  advent-cookies (`advent_src`/`advent_sid`) hergebruiken.
  **Waarom:** beide systemen kunnen op dezelfde hosting/domeinstructuur
  draaien; losse cookies voorkomen dat een bezoeker die via één van de twee
  binnenkomt de bron-attributie van de ander overschrijft.
  **Datum:** 2026-08-09
