-- Voegt een "uitverkocht"-vlag toe aan specials: de special blijft publiek
-- zichtbaar (banner/tekst), maar het bestelformulier wordt vervangen door een
-- melding en nieuwe bestellingen worden ook server-side geblokkeerd.
ALTER TABLE specials
    ADD COLUMN sold_out TINYINT(1) NOT NULL DEFAULT 0 AFTER active;
