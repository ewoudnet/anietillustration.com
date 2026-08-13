<?php

declare(strict_types=1);

namespace App;

/**
 * Zet shopadressen om naar coördinaten via OpenStreetMap Nominatim, zodat ze op
 * de kaart (backend/wholesale/shops.php) getoond kunnen worden. Faire/Orderchamp
 * leveren wel adressen maar geen lat/lng.
 *
 * Nominatim i.p.v. Google Geocoding om dezelfde reden als Leaflet i.p.v. Google
 * Maps: geen API-sleutel, geen facturatie-account (zie docs/wholesale.md).
 *
 * Nominatim's gebruiksvoorwaarden schrijven twee dingen dwingend voor, en beide
 * zitten hieronder verwerkt:
 *  1. Maximaal 1 verzoek per seconde - centraal afgedwongen in request(), niet
 *     bij de aanroepers, omdat één adres meerdere pogingen kan kosten.
 *  2. Een herkenbare User-Agent met contactmogelijkheid; een generieke of
 *     ontbrekende User-Agent wordt geblokkeerd.
 * Daarom werkt dit in kleine batches: 104 shops x minstens een seconde past niet
 * binnen de max_execution_time van shared hosting.
 */
final class GeocodingService
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';
    private const USER_AGENT = 'AnietIllustration-Wholesale/1.0 (+https://aniet.nl; info@anietillustration.com)';

    /**
     * Faire levert ISO alpha-3 ("NLD"), Orderchamp alpha-2 ("NL") - bewust
     * ongenormaliseerd opgeslagen in shops.country_code (zie docs/wholesale.md).
     * Nominatim's countrycodes-filter wil alpha-2, dus alleen hier omzetten.
     * Beperkt tot de landen die in de praktijk in de orderdata voorkomen; een
     * onbekende code levert simpelweg geen landfilter op i.p.v. een foute.
     */
    private const ALPHA3_TO_ALPHA2 = [
        'NLD' => 'nl', 'BEL' => 'be', 'DEU' => 'de', 'FRA' => 'fr', 'GBR' => 'gb',
        'IRL' => 'ie', 'ESP' => 'es', 'ITA' => 'it', 'PRT' => 'pt', 'AUT' => 'at',
        'CHE' => 'ch', 'DNK' => 'dk', 'SWE' => 'se', 'NOR' => 'no', 'FIN' => 'fi',
        'POL' => 'pl', 'CZE' => 'cz', 'LUX' => 'lu', 'USA' => 'us', 'CAN' => 'ca',
        'AUS' => 'au', 'NZL' => 'nz', 'JPN' => 'jp',
        // Toegevoegd op 2026-08-13: kwamen echt voor in de orderdata en vielen
        // daardoor zonder landfilter terug. De lijst hieronder dekt de rest van
        // de EER plus de grotere Faire-markten, zodat dit niet elk kwartaal
        // opnieuw hoeft.
        'EST' => 'ee', 'MLT' => 'mt', 'LVA' => 'lv', 'LTU' => 'lt', 'SVK' => 'sk',
        'SVN' => 'si', 'HRV' => 'hr', 'HUN' => 'hu', 'ROU' => 'ro', 'BGR' => 'bg',
        'GRC' => 'gr', 'CYP' => 'cy', 'ISL' => 'is', 'LIE' => 'li', 'MCO' => 'mc',
        'AND' => 'ad', 'SMR' => 'sm', 'MEX' => 'mx', 'BRA' => 'br', 'ZAF' => 'za',
        'KOR' => 'kr', 'SGP' => 'sg', 'HKG' => 'hk', 'ARE' => 'ae', 'ISR' => 'il',
        'TUR' => 'tr', 'UKR' => 'ua', 'SRB' => 'rs',
    ];

    /**
     * Hoeveel seconden één batch maximaal mag duren. Elk adres kost 1 tot 3
     * verzoeken (zie lookup()) van elk minimaal een seconde, dus zonder deze
     * grens zou een batch met veel terugvallen de max_execution_time van shared
     * hosting overschrijden. De batch stopt gewoon eerder; wat overblijft komt
     * bij de volgende klik.
     */
    private const MAX_SECONDS_PER_BATCH = 20;

    /** Tijdstip van het laatste Nominatim-verzoek, voor de 1/seconde-limiet. */
    private static ?float $lastRequestAt = null;

    /**
     * Werkt maximaal $limit shops zonder coördinaten af. Zet geocoded_at ook bij
     * een mislukte poging, zodat een onvindbaar adres niet elke run opnieuw een
     * verzoek kost - opnieuw proberen kan door geocoded_at leeg te maken.
     *
     * @return array{attempted: int, located: int, failed: array<int, string>, remaining: int}
     */
    public static function geocodePending(int $limit = 10): array
    {
        $shops = ShopRepository::findNeedingGeocoding($limit);

        $located = 0;
        $failed = [];
        $startedAt = time();
        $processed = 0;

        foreach ($shops as $shop) {
            // Tijdgrens: met terugvallen kost één adres tot 3 seconden, dus een
            // volle batch zou anders de PHP-tijdslimiet kunnen raken.
            if ($processed > 0 && (time() - $startedAt) >= self::MAX_SECONDS_PER_BATCH) {
                break;
            }
            $processed++;

            try {
                $point = self::lookup($shop);
            } catch (\RuntimeException $e) {
                // Netwerk-/API-fout: geocoded_at NIET zetten, zodat deze shop bij
                // een volgende poging gewoon weer meegenomen wordt (in
                // tegenstelling tot een adres dat echt niet gevonden wordt).
                $failed[] = $shop['name'] . ' - ' . $e->getMessage();
                continue;
            }

            if ($point === null) {
                ShopRepository::markGeocodeAttempted((int) $shop['id']);
                $failed[] = $shop['name'] . ' - adres niet gevonden';
                continue;
            }

            ShopRepository::updateCoordinates((int) $shop['id'], $point['lat'], $point['lng']);
            $located++;
        }

        return [
            'attempted' => $processed,
            'located' => $located,
            'failed' => $failed,
            'remaining' => ShopRepository::countNeedingGeocoding(),
        ];
    }

    /**
     * Probeert een adres in maximaal vier stappen en stopt bij de eerste
     * treffer. Nodig omdat het `street`-veld in de praktijk vervuild is: de
     * import plakt Faire's address1+address2 aan elkaar, en address2 bevat vaak
     * een bedrijfsnaam, unitnummer of verdieping ("24 Tartu maantee ROSES.EE").
     * Daar struikelt Nominatim's gestructureerde zoekopdracht over, terwijl
     * postcode en plaats wél betrouwbaar gevuld zijn. Met deze terugval kwamen
     * alle 20 eerder mislukte adressen alsnog binnen (getest 2026-08-13).
     *
     * @param array<string, mixed> $shop
     * @return array{lat: float, lng: float}|null null = adres niet gevonden (geen fout)
     */
    private static function lookup(array $shop): ?array
    {
        $street = trim((string) ($shop['street'] ?? ''));
        $city = trim((string) ($shop['city'] ?? ''));
        $postcode = trim((string) ($shop['postal_code'] ?? ''));
        $country = self::normalizeCountry((string) ($shop['country_code'] ?? ''));

        // Zonder plaats én postcode is er te weinig om op te zoeken; dan levert
        // Nominatim hooguit een willekeurig punt in het land op.
        if ($city === '' && $postcode === '') {
            return null;
        }

        $base = ['format' => 'jsonv2', 'limit' => '1'];
        if ($country !== null) {
            $base['countrycodes'] = $country;
        }

        $attempts = [];

        // 1. Precies: gestructureerd met straat.
        if ($street !== '') {
            $attempts[] = $base + array_filter([
                'street' => $street,
                'city' => $city,
                'postalcode' => $postcode,
            ], static fn (string $v): bool => $v !== '');

            // 2. Vrije tekst: Nominatim negeert ruis in één tekstregel veel beter
            //    dan in het strikte street-veld.
            $attempts[] = $base + [
                'q' => trim(implode(', ', array_filter([$street, trim($postcode . ' ' . $city)]))),
            ];
        }

        // 3/4. Zonder straat. Twee varianten, waarvan de volgorde uitmaakt voor
        //      de JUISTHEID, niet alleen voor de slaagkans: een postcode met
        //      letters erin (NL/BE/GB) wijst een klein gebied aan en is dan
        //      betrouwbaarder dan de plaatsnaam. Echt voorbeeld uit de data:
        //      "Afferden (GLD)" - er zijn twee dorpen Afferden, en zoeken op de
        //      plaatsnaam levert het verkeerde op (Limburg i.p.v. Gelderland,
        //      ~40 km ernaast), terwijl de postcode 6654KE het juiste dorp geeft.
        //      Bij een cijferpostcode (FR/US) is het omgekeerd en voegt de plaats
        //      juist wél iets toe.
        $cityForFallback = trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', $city));

        $byPostcodeOnly = $postcode !== '' ? $base + ['postalcode' => $postcode] : null;
        $byPostcodeCity = $base + array_filter([
            'city' => $cityForFallback,
            'postalcode' => $postcode,
        ], static fn (string $v): bool => $v !== '');

        $postcodeIsPrecise = $postcode !== '' && preg_match('/[A-Za-z]/', $postcode) === 1;

        foreach ($postcodeIsPrecise ? [$byPostcodeOnly, $byPostcodeCity] : [$byPostcodeCity, $byPostcodeOnly] as $fallback) {
            if ($fallback !== null) {
                $attempts[] = $fallback;
            }
        }

        foreach ($attempts as $query) {
            $response = self::request($query);
            if ($response !== [] && isset($response[0]['lat'], $response[0]['lon'])) {
                return [
                    'lat' => (float) $response[0]['lat'],
                    'lng' => (float) $response[0]['lon'],
                ];
            }
        }

        return null;
    }

    private static function normalizeCountry(string $code): ?string
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }
        if (strlen($code) === 2) {
            return strtolower($code);
        }

        return self::ALPHA3_TO_ALPHA2[$code] ?? null;
    }

    /**
     * @param array<string, string> $query
     * @return array<int, array<string, mixed>>
     */
    private static function request(array $query): array
    {
        // Nominatim's limiet van 1 verzoek/seconde hier centraal afdwingen, niet
        // bij de aanroepers: één adres kan meerdere pogingen kosten, dus tellen
        // per shop volstaat niet. Zo kan geen enkel pad de limiet omzeilen.
        if (self::$lastRequestAt !== null) {
            $elapsed = microtime(true) - self::$lastRequestAt;
            if ($elapsed < 1.0) {
                usleep((int) ((1.0 - $elapsed) * 1_000_000));
            }
        }
        self::$lastRequestAt = microtime(true);

        $curl = curl_init(self::ENDPOINT . '?' . http_build_query($query));
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . self::USER_AGENT,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            throw new \RuntimeException('Nominatim-aanroep mislukt: ' . $error);
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Nominatim gaf status ' . $status . ' terug.');
        }

        $decoded = json_decode($response, true);

        return is_array($decoded) ? $decoded : [];
    }
}
