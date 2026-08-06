# Module: products (latere fase)

## Doel
Productbeheer voor de wholesale-shop (mogelijk ook relevant voor specials),
inclusief koppeling met Faire: producten worden als kopie uit Faire
ingeladen, met wederzijdse voorraadsynchronisatie (nieuwe voorraad op Faire
moet doorkomen naar de eigen site, en bestellingen op Faire of de eigen site
moeten de voorraad bij de ander bijwerken).

## Belangrijkste bestanden/locaties
Nog niet aangemaakt. Bestaande, verwante code (eenrichtings-sync, alleen
voorraad, Faire → eigen systeem):
`C:\projecten\aniet.nl\backoffice-core\src\FaireService.php`.

## Status / openstaande punten
- [ ] Onderzoek Faire API voor volledige product-import (huidige
  `FaireService.php` haalt alleen voorraad op via SKU, geen productcatalogus)
- [ ] Tweerichtings-synchronisatie voorraad (huidige sync is éénrichting
  Faire → eigen systeem; wholesale heeft dus meer nodig dan wat er al is)
- [ ] Productbeheer-UI in backend (thumb, titel, sku, prijs, varianten,
  producttype — zelfde types/producten als op Faire)
- [ ] Mogelijke koppeling met geïmporteerde Faire-data, zodat producten niet
  dubbel onderhouden worden

## Beslissingen & rationale
- **Beslissing:** nog te onderzoeken of/hoe volledige Faire-productdata (niet
  alleen voorraad) via de API op te halen is, en hoe tweerichtingssync
  eruit moet zien.
  **Waarom:** de bestaande `FaireService.php` in `aniet.nl/backoffice` doet
  alleen een eenrichtings-voorraadsync (Faire → hier); er wordt nooit iets
  terug naar Faire geschreven. Wholesale vraagt om meer.
  **Datum:** 2026-08-06
