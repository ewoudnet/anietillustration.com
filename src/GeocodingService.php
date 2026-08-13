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
 *  1. Maximaal 1 verzoek per seconde - vandaar de sleep() tussen twee adressen.
 *  2. Een herkenbare User-Agent met contactmogelijkheid; een generieke of
 *     ontbrekende User-Agent wordt geblokkeerd.
 * Daarom werkt dit in kleine batches: 104 shops x 1 seconde past niet binnen de
 * max_execution_time van shared hosting.
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
    ];

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

        foreach ($shops as $index => $shop) {
            // Nominatim staat max. 1 verzoek per seconde toe. Niet wachten vóór
            // het eerste verzoek - dat zou elke batch een seconde langer maken
            // zonder dat het iets oplost.
            if ($index > 0) {
                sleep(1);
            }

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
            'attempted' => count($shops),
            'located' => $located,
            'failed' => $failed,
            'remaining' => ShopRepository::countNeedingGeocoding(),
        ];
    }

    /**
     * @param array<string, mixed> $shop
     * @return array{lat: float, lng: float}|null null = adres niet gevonden (geen fout)
     */
    private static function lookup(array $shop): ?array
    {
        $query = [
            'format' => 'jsonv2',
            'limit' => '1',
            // Gestructureerd zoeken i.p.v. één tekstregel: betrouwbaarder bij
            // buitenlandse adresnotaties dan alles aan elkaar plakken.
            'street' => trim((string) ($shop['street'] ?? '')),
            'city' => trim((string) ($shop['city'] ?? '')),
            'postalcode' => trim((string) ($shop['postal_code'] ?? '')),
        ];

        $countryCode = self::normalizeCountry((string) ($shop['country_code'] ?? ''));
        if ($countryCode !== null) {
            $query['countrycodes'] = $countryCode;
        }

        $query = array_filter($query, static fn (string $v): bool => $v !== '');

        // Zonder plaats én postcode is er te weinig om op te zoeken; dan levert
        // Nominatim hooguit een willekeurig punt in het land op.
        if (($query['city'] ?? '') === '' && ($query['postalcode'] ?? '') === '') {
            return null;
        }

        $response = self::request($query);
        if ($response === [] || !isset($response[0]['lat'], $response[0]['lon'])) {
            return null;
        }

        return [
            'lat' => (float) $response[0]['lat'],
            'lng' => (float) $response[0]['lon'],
        ];
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
