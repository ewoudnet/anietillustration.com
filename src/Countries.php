<?php

declare(strict_types=1);

namespace App;

final class Countries
{
    /** @var array<string,string> ISO 3166-1 alpha-2 code => Nederlandse naam */
    public const ALL = [
        'NL' => 'Nederland',
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
        'GB' => 'Verenigd Koninkrijk',
    ];

    /** @var string[] EU-lidstaten (exclusief Nederland, die krijgt zijn eigen zone) */
    public const EU_EXCLUDING_NL = [
        'BE', 'DE', 'FR', 'AT', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'GR',
        'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'PL', 'PT', 'RO', 'SK', 'SI',
        'ES', 'SE',
    ];

    public static function isValid(string $code): bool
    {
        return array_key_exists(strtoupper($code), self::ALL);
    }

    /**
     * Landen (NL + EU) waar naar verzonden wordt. Landen buiten de EU worden niet aangeboden
     * op de bestelpagina.
     *
     * @return array<string,string>
     */
    public static function shippableForStorefront(): array
    {
        $codes = array_merge(['NL'], self::EU_EXCLUDING_NL);

        return array_intersect_key(self::ALL, array_flip($codes));
    }

    public static function isShippableForStorefront(string $code): bool
    {
        return array_key_exists(strtoupper($code), self::shippableForStorefront());
    }
}
