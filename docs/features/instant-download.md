# Feature: instant download bij specials

## Doel
Naast fysieke items kan een special ook "instant download"-varianten hebben. Klant
doorloopt de normale bestelling (naam, e-mail, Mollie-betaling) en krijgt na betaling
een mail met een persoonlijke downloadlink. Bestanden worden in de backend bij de
special geüpload en zijn beveiligd.

## Gekoppelde modules
specials, orders

## Werking
- `special_price_variants.is_digital`: digitale variant = één prijs (NL-veld), geen
  verzendadres/land/aantal (altijd 1). Een special kan fysiek en digitaal mixen; een
  order bevat één variant.
- `special_downloads`: bestanden per special (alle bestanden worden bij elke
  download-order geleverd). Opslag: `storage/downloads/{willekeurig}.dat`, buiten de
  webroot + eigen `.htaccess`. Originele bestandsnaam staat in de database.
- `orders.is_digital` + `orders.download_token` (64 hex, uniek): snapshot en toegang.
- `specials/download.php?t={token}` toont de bestanden (alleen bij `status = paid`),
  `&f={id}` levert uit als attachment. Link blijft geldig (geen vervaldatum/limiet).
- Downloadteller: `orders.download_count`/`last_downloaded_at` (migratie 013) wordt bij elke bestandsdownload opgehoogd en staat in de orderlijst (kolom "Downloads", rood met ⚠️ vanaf 10; tooltip = laatste download).
- Mail (`Mailer`): bij digitale orders downloadknop i.p.v. verzendadres; bedankpagina
  toont ook de downloadknop. Mail gaat via bestaande `PaymentStatusSync` (webhook of
  bedankpagina).
- Backend: variant-type per rij, uploadveld + verwijder-vinkjes in het special-formulier;
  een digitale variant opslaan zonder bestand wordt geweigerd; bestellingenlijst toont
  "Download"-badge.

## Status
- [x] Code + lint + test van validator en mail-rendering (zonder database).
- [ ] Migratie draaien op de live database en end-to-end testen met een Mollie-testbetaling.
- [ ] Niet gebouwd: opnieuw versturen van de downloadmail vanuit de backend,
  vervaldatum/maximaal aantal downloads, en grote bestanden hangen af van
  `upload_max_filesize`/`post_max_size` van de hosting.
