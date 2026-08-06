<?php

declare(strict_types=1);

namespace App;

final class Countries
{
    /** @var array<string,string> NL + EU (exclusief NL) - ISO 3166-1 alpha-2 code => Nederlandse naam */
    public const EU_EXCLUDING_NL_NAMES = [
        'BE' => 'België',
        'DE' => 'Duitsland',
        'FR' => 'Frankrijk',
        'AT' => 'Oostenrijk',
        'BG' => 'Bulgarije',
        'HR' => 'Kroatië',
        'CY' => 'Cyprus',
        'CZ' => 'Tsjechië',
        'DK' => 'Denemarken',
        'EE' => 'Estland',
        'FI' => 'Finland',
        'GR' => 'Griekenland',
        'HU' => 'Hongarije',
        'IE' => 'Ierland',
        'IT' => 'Italië',
        'LV' => 'Letland',
        'LT' => 'Litouwen',
        'LU' => 'Luxemburg',
        'MT' => 'Malta',
        'PL' => 'Polen',
        'PT' => 'Portugal',
        'RO' => 'Roemenië',
        'SK' => 'Slowakije',
        'SI' => 'Slovenië',
        'ES' => 'Spanje',
        'SE' => 'Zweden',
    ];

    /** @var string[] EU-lidstaten (exclusief Nederland, die krijgt zijn eigen zone) */
    public const EU_EXCLUDING_NL = [
        'BE', 'DE', 'FR', 'AT', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'GR',
        'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'PL', 'PT', 'RO', 'SK', 'SI',
        'ES', 'SE',
    ];

    /**
     * Landen buiten de EU ("wereld"-zone). Geen volledige ISO-lijst van alle
     * 190+ landen, maar een ruime set van landen waar daadwerkelijk naar
     * verzonden wordt - eenvoudig uit te breiden als dat nodig is.
     *
     * @var array<string,string>
     */
    public const WORLD_EXCLUDING_EU_NAMES = [
        // Rest van Europa (niet-EU)
        'GB' => 'Verenigd Koninkrijk',
        'CH' => 'Zwitserland',
        'NO' => 'Noorwegen',
        'IS' => 'IJsland',
        'LI' => 'Liechtenstein',
        'AL' => 'Albanië',
        'AD' => 'Andorra',
        'BA' => 'Bosnië en Herzegovina',
        'MK' => 'Noord-Macedonië',
        'MD' => 'Moldavië',
        'MC' => 'Monaco',
        'ME' => 'Montenegro',
        'RS' => 'Servië',
        'SM' => 'San Marino',
        'UA' => 'Ukraïne',
        'VA' => 'Vaticaanstad',
        'XK' => 'Kosovo',
        // Noord-Amerika
        'US' => 'Verenigde Staten',
        'CA' => 'Canada',
        'MX' => 'Mexico',
        // Midden-Amerika & Caribisch gebied
        'CR' => 'Costa Rica',
        'PA' => 'Panama',
        'DO' => 'Dominicaanse Republiek',
        'JM' => 'Jamaica',
        'CW' => 'Curaçao',
        'AW' => 'Aruba',
        'SX' => 'Sint-Maarten',
        // Zuid-Amerika
        'BR' => 'Brazilië',
        'AR' => 'Argentinië',
        'CL' => 'Chili',
        'CO' => 'Colombia',
        'PE' => 'Peru',
        'UY' => 'Uruguay',
        'EC' => 'Ecuador',
        'SR' => 'Suriname',
        'BO' => 'Bolivia',
        'PY' => 'Paraguay',
        'VE' => 'Venezuela',
        // Azië
        'JP' => 'Japan',
        'CN' => 'China',
        'HK' => 'Hongkong',
        'TW' => 'Taiwan',
        'KR' => 'Zuid-Korea',
        'SG' => 'Singapore',
        'MY' => 'Malaysia',
        'TH' => 'Thailand',
        'VN' => 'Vietnam',
        'PH' => 'Filipijnen',
        'ID' => 'Indonesië',
        'IN' => 'India',
        'IL' => 'Israël',
        'AE' => 'Verenigde Arabische Emiraten',
        'SA' => 'Saoedi-Arabië',
        'TR' => 'Turkije',
        'PK' => 'Pakistan',
        'BD' => 'Bangladesh',
        'LK' => 'Sri Lanka',
        'QA' => 'Qatar',
        'KW' => 'Koeweit',
        // Afrika
        'ZA' => 'Zuid-Afrika',
        'EG' => 'Egypte',
        'MA' => 'Marokko',
        'NG' => 'Nigeria',
        'KE' => 'Kenia',
        'GH' => 'Ghana',
        'TN' => 'Tunesië',
        // Oceanië
        'AU' => 'Australië',
        'NZ' => 'Nieuw-Zeeland',
    ];

    /** @var string[] */
    public const WORLD_EXCLUDING_EU = [
        'GB', 'CH', 'NO', 'IS', 'LI', 'AL', 'AD', 'BA', 'MK', 'MD', 'MC', 'ME',
        'RS', 'SM', 'UA', 'VA', 'XK',
        'US', 'CA', 'MX',
        'CR', 'PA', 'DO', 'JM', 'CW', 'AW', 'SX',
        'BR', 'AR', 'CL', 'CO', 'PE', 'UY', 'EC', 'SR', 'BO', 'PY', 'VE',
        'JP', 'CN', 'HK', 'TW', 'KR', 'SG', 'MY', 'TH', 'VN', 'PH', 'ID', 'IN',
        'IL', 'AE', 'SA', 'TR', 'PK', 'BD', 'LK', 'QA', 'KW',
        'ZA', 'EG', 'MA', 'NG', 'KE', 'GH', 'TN',
        'AU', 'NZ',
    ];

    /** @var array<string,string> Alle ondersteunde landen (NL + EU + wereld), ISO code => Nederlandse naam */
    public const ALL = ['NL' => 'Nederland'] + self::EU_EXCLUDING_NL_NAMES + self::WORLD_EXCLUDING_EU_NAMES;

    public static function isValid(string $code): bool
    {
        return array_key_exists(strtoupper($code), self::ALL);
    }

    /**
     * Prijszone voor een land: 'nl', 'eu' (overig Europa) of 'world' (overig).
     */
    public static function zoneFor(string $code): string
    {
        $code = strtoupper($code);

        if ($code === 'NL') {
            return 'nl';
        }

        if (array_key_exists($code, self::EU_EXCLUDING_NL_NAMES)) {
            return 'eu';
        }

        return 'world';
    }

    /**
     * Landen die voor een special aangeboden worden op de bestelpagina, afhankelijk van
     * of die special buiten NL (EU) resp. buiten de EU (wereldwijd) verzendt.
     *
     * @return array<string,string>
     */
    public static function shippableFor(bool $shipEu, bool $shipWorld): array
    {
        $countries = ['NL' => self::ALL['NL']];

        if ($shipEu) {
            $countries += self::EU_EXCLUDING_NL_NAMES;
        }
        if ($shipWorld) {
            $countries += self::WORLD_EXCLUDING_EU_NAMES;
        }

        return $countries;
    }

    public static function isShippableFor(string $code, bool $shipEu, bool $shipWorld): bool
    {
        return array_key_exists(strtoupper($code), self::shippableFor($shipEu, $shipWorld));
    }
}
