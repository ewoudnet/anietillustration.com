-- Zet de bestaande adventskaarten-actie (nu op aniet.nl/advent) over naar het nieuwe
-- specials-systeem, als concept (active = 0) zodat eerst gecontroleerd kan worden
-- voordat 'ie publiek zichtbaar wordt. Voer uit op de database waar `specials` en
-- `special_price_variants` al bestaan (na schema.sql / migratie 001_zone_pricing.sql).
--
-- Prijzen zijn overgenomen uit de huidige productie-.env van adventskaarten-bestellen
-- (NL €34,95 / EU €40,00 / wereld €50,00). ship_world staat uit omdat de huidige
-- advent-pagina alleen naar NL + EU verzendt - zet 'm aan in de backend zodra je ook
-- wereldwijd wilt verzenden. Banner-afbeelding is gekopieerd naar
-- specials/assets/uploads/banners/advent-2026.jpg.

INSERT INTO specials (title, banner_path, description, active, ship_eu, ship_world, starts_at, ends_at)
VALUES (
    'Adventskalender',
    'uploads/banners/advent-2026.jpg',
    '✨ Nieuw! Een adventkalender voor kaartenliefhebbers! ✨

Dit jaar heb ik iets héél bijzonders voor jullie! 🤍 Een adventkalender met 24 gloednieuwe ansichtkaarten, allemaal geïllustreerd door mij, Aniet Illustration. Super leuk voor jezelf maar ook een geweldig kado! 🎉

Elke dag in december pak je een nieuw cadeautje uit: een prachtige ansichtkaart, verpakt in een genummerd geschenkzakje. Perfect voor iedereen die dol is op post versturen, postcrossing, mijn illustraties verzamelt of gewoon wil genieten van een dagelijkse verrassing in aanloop naar Kerst. ✉️🎄

Normaal zijn mijn kaarten alleen verkrijgbaar via wholesale, maar speciaal voor de feestdagen is deze adventkalender ook als pre-order voor particulieren verkrijgbaar! 🤩

🎁 Voor slechts €34,95 incl. verzendkosten (NL) ligt hij begin november bij jou op de deurmat.
Waarde van de inhoud is €49,50!

Wees er snel bij want er is een beperkt aantal beschikbaar!',
    0,
    1,
    0,
    NULL,
    NULL
);

INSERT INTO special_price_variants (special_id, label, price_nl_cents, price_eu_cents, price_world_cents, sort_order, active)
VALUES (
    LAST_INSERT_ID(),
    'Adventskalender (24 kaarten)',
    3495,
    4000,
    5000,
    0,
    1
);
