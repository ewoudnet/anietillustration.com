# Module: products (latere fase)

## Doel
Productbeheer voor de wholesale-shop (mogelijk ook relevant voor specials),
inclusief koppeling met Faire: producten worden als kopie uit Faire
ingeladen, met wederzijdse voorraadsynchronisatie (nieuwe voorraad op Faire
moet doorkomen naar de eigen site, en bestellingen op Faire of de eigen site
moeten de voorraad bij de ander bijwerken).

## Belangrijkste bestanden/locaties
Productbeheer-UI nog niet aangemaakt. Bestaande, verwante code (eenrichtings-
sync, alleen voorraad, Faire → eigen systeem): `src/FaireService.php` (hier
in `anietillustration.com`, sinds commit `081d6cd` gemigreerd uit en
functioneel vervangend voor de oorspronkelijke, nu verouderde
`C:\projecten\aniet.nl\backoffice-core\src\FaireService.php` — die laatste
blijft ongewijzigd/operationeel als losse tool, zie CLAUDE.md, maar krijgt
geen nieuwe fixes meer). Zie ook [[wholesale]] voor de bredere
Faire+Orderchamp-synchronisatie die hier bovenop gebouwd wordt.

## Status / openstaande punten
- [ ] Onderzoek Faire API voor volledige product-import (huidige
  `FaireService.php` haalt alleen voorraad op via SKU, geen productcatalogus)
- [ ] Tweerichtings-synchronisatie voorraad (huidige sync is éénrichting
  Faire → eigen systeem) — wordt opgepakt in [[wholesale]] (fase C/D:
  database wordt leidend voor zowel Faire als Orderchamp)
- [ ] Productbeheer-UI in backend (thumb, titel, sku, prijs, varianten,
  producttype — zelfde types/producten als op Faire)
- [ ] Mogelijke koppeling met geïmporteerde Faire-data, zodat producten niet
  dubbel onderhouden worden
- [x] Catalogus-PDF (2026-08-19): `backend/aniet-illustration/catalog.php`
  (kies producttypes + optioneel drafts) en `catalog-pdf.php` (download,
  geen e-mailoptie — gebruiker verstuurt de PDF zelf), opbouw in
  `src/CatalogPdfBuilder.php`. Thumb+sku+titel+leeg aantal-veld per
  product, gegroepeerd per producttype. Draft-producten (Wholesale
  Draft-vlag) staan er standaard niet in. Bij Kaarten komen alleen kaarten
  gekoppeld aan de Wholesale-sales-channel mee (`CardRepository::
  findWholesaleForCatalog()`) - kaarten die uitsluitend bij Greetz/
  Kaartje2Go/Thortful/Redbubble verkocht worden horen niet in een
  B2B-catalogus. Generieke producten (niet-Kaarten) hebben geen
  verkoopkanaal-koppeling en zijn sowieso altijd Wholesale-only, dus die
  hoeven niet gefilterd te worden. Thumbnails worden serverside
  verkleind/gecomprimeerd (JPEG, "as is" aspect ratio i.p.v. vierkante
  crop) - zonder dat werd een catalogus met veel producten tientallen MB's
  groot.
  **Let op:** dompdf's SVG-ondersteuning trekt via `sabberworm/php-css-parser`
  boven v9.3 het package `thecodingmachine/safe` mee (duizenden losse
  bestanden), wat de FTP-deploy naar aniet.nl liet vastlopen/mislukken op
  2026-08-19. `composer.json` pint daarom expliciet
  `sabberworm/php-css-parser` op `8.9.0` - niet zomaar loslaten bij een
  toekomstige `composer update`.

## Beslissingen & rationale
- **Beslissing:** nog te onderzoeken of/hoe volledige Faire-productdata (niet
  alleen voorraad) via de API op te halen is, en hoe tweerichtingssync
  eruit moet zien.
  **Waarom:** de bestaande `FaireService.php` doet alleen een eenrichtings-
  voorraadsync (Faire → hier); er wordt nooit iets terug naar Faire
  geschreven. Wholesale vraagt om meer.
  **Datum:** 2026-08-06
- **Beslissing:** de tweerichtingssync (dit document) en de bredere
  Faire+Orderchamp-ordersynchronisatie worden samen als één traject gebouwd
  onder [[wholesale]], niet als los `products`-traject.
  **Waarom:** allebei draaien op dezelfde `StockSyncService`/`stock_sync_log`
  -laag (fase C+); apart bouwen zou dubbel werk en twee bronnen van waarheid
  voor voorraad opleveren.
  **Datum:** 2026-08-12
