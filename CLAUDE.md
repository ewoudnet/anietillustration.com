# anietillustration.com

## Doel
Compleet frontend + backend systeem voor Aniet Illustration, dat de huidige
losse advent-betaalpagina (`C:\projecten\adventskaarten-bestellen`) vervangt
door een herbruikbaar geheel. Bestaat uit:

- **Portfolio-frontend**: home/about, contactformulier met spam-beveiliging,
  Google Analytics.
- **Specials**: publieke bestelpagina's zonder inlog voor tijdelijke
  aanbiedingen (opvolger van het advent-concept, generiek voor meerdere
  specials naast elkaar) — overzicht van lopende specials met daaronder de
  verlopen specials.
- **Wholesale (B2B)**: alternatief voor Faire. Aanmelden via de site, na
  goedkeuring ontvangt de klant een inlog-link + wachtwoord-instelling.
  Klantportaal met bestellingen en status (besteld/onderweg/geleverd, idealiter
  automatisch via tracking). Producten zijn een kopie uit Faire met
  wederzijdse voorraadsynchronisatie.
- **Backend/beheer**: specials aanmaken (banner, tekst, prijsvarianten,
  status), bezoekersstatistieken/omzet per special, productbeheer (incl.
  Faire-koppeling), orderbeheer voor wholesale (orderbevestiging per mail met
  PDF, factuur per mail als PDF na afronding), betalingen via Mollie.

**Belangrijk:** de bestaande `adventskaarten-bestellen`-map en de bijbehorende
admin blijven intact en operationeel totdat de gebruiker expliciet akkoord
geeft om ze te verwijderen — pas nadat het nieuwe systeem het overneemt.

Dit project wordt in fases gebouwd. **Fase 1 (huidige focus):** backend-basis
met uitklapbaar linker menu, de sectie "Specials" (aanmaken/bewerken, banner,
tekst, prijsvarianten, status aan/uit) en het bijbehorende orderbeheer
(overzicht, filter op special, Excel-export), naar het voorbeeld van de
bestaande `/advent`-flow. Publiek: `/specials/index.php` (overzicht lopende +
verlopen specials) en `/specials/?s={id}` (bestelpagina per special). Latere
fases: portfolio-frontend, wholesale B2B, product-/Faire-koppeling en
analytics — zie de module-bestanden in `/docs/` voor status per fase.

Voorlopig gehost als submap(pen) onder het bestaande domein `aniet.nl` (zelfde
FTP-only shared-hosting aanpak als de huidige projecten), totdat het systeem
volledig ontwikkeld is en overgaat naar het eigen domein
`anietillustration.com` (waar de root van `aniet.nl` al naar redirect).

## Tech stack
- PHP ≥8.1, geen framework — zelfde stijl als de bestaande
  `adventskaarten-bestellen` en `aniet.nl/backoffice` projecten.
- MySQL/MariaDB (mysqli/PDO).
- Composer voor dependencies waar nodig: `mollie/mollie-api-php` (betalingen),
  `phpmailer/phpmailer` (mail), `vlucas/phpdotenv` (config). Lokaal
  `composer install`, `vendor/` wordt meegeüpload (geen composer op de
  server).
- Mollie voor alle betalingen (specials + later wholesale).
- Google Analytics voor frontend-statistieken; eigen lichte
  paginaweergave/bronherkenning-logging voor per-special statistieken (naar
  het voorbeeld van de bestaande advent-stats).
- Faire API voor wholesale-productdata en wederzijdse
  voorraadsynchronisatie (uitbreiding op de bestaande, eenrichtings
  Faire→eigen-sync in `aniet.nl/backoffice/src/FaireService.php`).
- PDF-generatie voor orderbevestiging/factuur (wholesale) — library nog te
  kiezen in die fase.
- Hosting: FTP-only shared hosting onder `aniet.nl`, geen SSH/composer op de
  server.

## Architectuur
Monolithische PHP-opzet volgens hetzelfde patroon als de bestaande projecten:
een publieke webroot-map plus een **afgeschermde sibling-map** (`*-core`,
geblokkeerd via `.htaccess`, nooit publiek bereikbaar) voor `src/`, `vendor/`,
`sql/`, config en logs — nodig omdat de hosting alleen FTP-toegang biedt
zonder documentroot-controle.

Belangrijkste onderdelen:
- **Backend** — centrale schil met uitklapbaar linker menu; secties worden
  per fase toegevoegd (Specials → later Producten, Wholesale-orders,
  Klanten).
- **Specials** — generiek systeem voor tijdelijke aanbiedingen (opvolger van
  het losse advent-concept), met een eigen publieke besteltraject per special
  (formulier → order + Mollie-betaling → webhook → bedankpagina, net als
  `/advent`).
- **Orders** — centraal order-/betaalsysteem, gedeeld tussen specials (nu) en
  wholesale (later), met Mollie-integratie, webhook-verwerking en
  Excel-export.
- **Wholesale** (latere fase) — apart klantportaal met eigen login,
  productcatalogus gesynchroniseerd met Faire, orderstatus en
  facturatie-mail.
- **Products** (latere fase) — productbeheer met Faire-koppeling.
- **Frontend** (latere fase) — publieke portfolio-site (about, contact,
  links naar specials/wholesale).
- **Analytics** (latere fase) — statistieken/omzet per special en
  Google Analytics-integratie in de backend.

Voor detail per onderdeel: zie `/docs/`.

---

## Werkwijze & vaste regels

Deze regels gelden voor elke sessie binnen dit project, naast eventuele
projectspecifieke afspraken hierboven.

- **Restart/rebuild-impact expliciet melden.** Bij wijzigingen die een
  restart/rebuild vereisen (bijv. docker-compose down/up, cache clear,
  service restart), dit expliciet en proactief vermelden vóórdat je
  verdergaat.
- **Klaar = melden en stoppen.** Zodra een feature werkt, getest is én
  gecommit — meld dit expliciet en stel voor de sessie te beëindigen om
  onnodig tokengebruik te voorkomen.
- **Alleen relevante docs laden.** Laad alleen de `/docs/` bestanden die
  relevant zijn voor de huidige taak, niet de volledige `/docs/` map, om
  tokengebruik te beperken.
- **Framework-suggesties loggen.** Zie je tijdens een sessie een generieke
  verbetering aan dit framework zelf (niet projectspecifiek)? Log dit direct
  in `framework-feedback.md` in de project-root (maak dit bestand aan als het
  nog niet bestaat), als regel in de vorm:
  `- YYYY-MM-DD — <korte beschrijving>. Context: <reden>`
  Meld dit ook kort in de sessie zelf, onder de kop "Framework-suggestie".
  Log alleen generieke verbeteringen; projectspecifieke wensen horen in dit
  CLAUDE.md of in `/docs/`, niet in `framework-feedback.md`.

## Documentatiestructuur

Dit project gebruikt de volgende documentatiestructuur:

- `/docs/` — één bestand per module (opzet volgens `module-template.md`):
  doel, belangrijkste bestanden/locaties, status/openstaande punten,
  beslissingen & rationale.
- `/docs/features/` — één bestand per feature (opzet volgens
  `feature-template.md`): doel, gekoppelde module(s), status, testresultaten,
  commit-referentie(s).

Lees per taak alleen de bestanden die relevant zijn voor die taak. Lees niet
standaard de volledige `/docs/` map in.
