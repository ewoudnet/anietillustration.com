# Module: backend (fase 1)

## Doel
Centrale backoffice-schil voor het hele systeem: publieke webroot +
afgeschermde `-core` sibling-map, login/auth, en een uitklapbaar linker menu
waaronder secties per fase worden toegevoegd. Fase 1 heeft alleen de sectie
"Specials" nodig; latere fases voegen Producten, Wholesale-orders en Klanten
toe.

## Belangrijkste bestanden/locaties
- `backend/` — webroot-submap (admin): `bootstrap.php`, `login.php`,
  `logout.php`, `index.php` (redirect naar eerste sectie), `partials/
  layout-start.php`/`layout-end.php` (sidebar + menu-array), `assets/css/
  style.css`
- `specials/` — publieke webroot-submap (zie `docs/specials.md`)
- `src/Config.php`, `src/Database.php` — dotenv + PDO, root-detectie relatief
  aan `src/`'s eigen positie (werkt ongewijzigd in beide deploy-layouts)
- `src/Auth.php`, `src/Csrf.php` — DB-login (`admin_users`), sessie + CSRF
- `sql/schema.sql` — `admin_users`-tabel (+ `specials`, `special_price_
  variants`, `orders`, zie de andere modules)

## Status / openstaande punten
- [x] Basisstructuur: `backend/` + `specials/` als publieke webroot-submappen,
  `src/`/`vendor/`/`sql/`/`.env`/`logs/` als gedeelde root (lokaal) resp.
  `anietillustration-core/` sibling-map (deploy, `.htaccess` via
  `deploy-htaccess-core.txt`)
- [x] Login/auth: DB-tabel `admin_users` (`password_hash`, sessie-gebaseerd,
  CSRF via `Csrf`), geen publieke setup-pagina — eerste admin via
  handmatige SQL-insert (zie hieronder)
- [x] Uitklapbaar linker menu (`<details>`, geen JS nodig), menu-structuur als
  array in `partials/layout-start.php` — nieuwe secties (Producten,
  Wholesale-orders, Klanten) toevoegen door een item aan die array toe te
  voegen
- [x] Fase 1: sectie "Specials" (beheer + orders), zie `docs/specials.md` en
  `docs/orders.md`
- [ ] Sectie-permissies (wie mag wat) — bewust nog niet gebouwd, zie
  beslissing hieronder; later fases kunnen dit toevoegen zonder de
  `admin_users`-tabel te wijzigen

## Eerste admin aanmaken
Geen publieke setup-pagina (zelfde reden als backoffice: zou op een live
server kwetsbaar blijven staan). Lokaal een hash genereren:
```
php -r "echo password_hash('jouw-wachtwoord', PASSWORD_DEFAULT), PHP_EOL;"
```
En dan in de database:
```sql
INSERT INTO admin_users (username, password_hash, active) VALUES ('ewoud', '<hash>', 1);
```

## Beslissingen & rationale
- **Beslissing:** zelfde map-aanpak als de bestaande projecten: publieke map
  + afgeschermde `-core` sibling-map, geblokkeerd via `.htaccess`.
  **Waarom:** hosting biedt alleen FTP-toegang zonder documentroot-controle,
  dus `src/`, `vendor/`, config en logs moeten buiten de webroot staan maar
  wel eenvoudig 1-op-1 te deployen zijn. Bewezen patroon uit
  `aniet.nl/backoffice` en `adventskaarten-bestellen`.
  **Datum:** 2026-08-06
- **Beslissing:** twee publieke webroot-submappen (`backend/` + `specials/`)
  i.p.v. één, die samen `src/`/`vendor/` als gedeelde `anietillustration-
  core/` delen — zelfde constructie als `advent/` + `kalender2027/` +
  `advent-core/`.
  **Waarom:** backend (admin) en specials (publiek besteltraject) hebben
  volledig andere doelgroepen/login-eisen, maar delen dezelfde database en
  Mollie-config.
  **Datum:** 2026-08-06
- **Beslissing:** login via een DB-tabel `admin_users` (zoals backoffice),
  zonder sectie-permissies.
  **Waarom:** gebruiker koos dit boven vaste `.env`-credentials (zoals
  advent) omdat een tweede gebruiker later zonder code-wijziging toe te
  voegen is. Sectie-permissies zijn nog niet nodig omdat er pas 1 sectie is;
  elke actieve gebruiker heeft nu volledige toegang.
  **Datum:** 2026-08-06
- **Beslissing:** banner-uploads (specials) worden fysiek opgeslagen in
  `specials/assets/uploads/banners/` (de publieke webroot die ze toont),
  niet in `backend/assets/`. Backend toont ze via een absolute URL
  (`Config::appUrl()`, dezelfde config-waarde als de Mollie-redirect/
  webhook-URL) omdat `backend/` en `specials/` twee losse webroot-submappen
  zijn.
  **Waarom:** zelfde probleem/oplossing als `kalender2027/` dat assets van
  `advent/` via `APP_URL` laadt in plaats van een relatief pad.
  **Datum:** 2026-08-06
- **Beslissing:** actiekolommen in overzichtstabellen (bijv.
  `backend/specials/index.php`) gebruiken een `⋮`-dropdown met icoon-labels
  (`.actions-dropdown`/`.actions-menu`, `assets/js/actions-menu.js`) i.p.v.
  losse tekstlinks/knoppen naast elkaar.
  **Waarom:** overgenomen van `aniet.nl/backoffice` (bewezen patroon) - een
  vast-breed enkel triggerknopje per rij voorkomt dat rijen met een
  wisselend aantal/lengte acties niet verticaal uitlijnen.
  **Datum:** 2026-08-06
