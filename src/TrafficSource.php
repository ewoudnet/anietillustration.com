<?php

declare(strict_types=1);

namespace App;

/**
 * Bronherkenning voor de publieke specials-pagina's, overgenomen van
 * adventskaarten-bestellen/src/TrafficSource.php (cookienamen aangepast om niet te
 * botsen met het advent-systeem, dat op dezelfde hosting/domeinstructuur kan draaien).
 */
final class TrafficSource
{
    private const SOURCE_COOKIE = 'specials_src';
    private const SESSION_COOKIE = 'specials_sid';
    private const COOKIE_LIFETIME_DAYS = 90;

    /** @var array<string,string> hostname-fragment => vriendelijke naam */
    private const REFERRER_DOMAINS = [
        'instagram.com' => 'Instagram',
        'l.instagram.com' => 'Instagram',
        'facebook.com' => 'Facebook',
        'l.facebook.com' => 'Facebook',
        'lm.facebook.com' => 'Facebook',
        'm.facebook.com' => 'Facebook',
        'tiktok.com' => 'TikTok',
        'pinterest.com' => 'Pinterest',
        'pinterest.nl' => 'Pinterest',
        't.co' => 'Twitter/X',
        'twitter.com' => 'Twitter/X',
        'x.com' => 'Twitter/X',
        'google.' => 'Google',
        'bing.com' => 'Bing',
        'duckduckgo.com' => 'DuckDuckGo',
        'mail.google.com' => 'E-mail',
        'outlook.' => 'E-mail',
        'whatsapp.com' => 'WhatsApp',
        'web.whatsapp.com' => 'WhatsApp',
    ];

    /**
     * Bepaalt de bron van dit bezoek (first-touch: eenmaal vastgesteld, blijft het staan
     * voor de rest van het bezoek/aankoopproces via een cookie) en zorgt dat de
     * bijbehorende cookies gezet zijn. Retourneert [source, sessionId, utmSource, utmMedium, utmCampaign, referrer].
     *
     * @return array{source:string, sessionId:string, utmSource:?string, utmMedium:?string, utmCampaign:?string, referrer:?string}
     */
    public static function resolve(): array
    {
        $utmSource = self::cleanParam($_GET['utm_source'] ?? null);
        $utmMedium = self::cleanParam($_GET['utm_medium'] ?? null);
        $utmCampaign = self::cleanParam($_GET['utm_campaign'] ?? null);
        $referrer = self::cleanParam($_SERVER['HTTP_REFERER'] ?? null);

        $existingSource = $_COOKIE[self::SOURCE_COOKIE] ?? null;

        if ($existingSource !== null && $existingSource !== '') {
            $source = $existingSource;
        } elseif ($utmSource !== null) {
            $source = ucfirst($utmSource);
        } else {
            $source = self::labelForReferrer($referrer);
        }

        if ($existingSource === null || $existingSource === '') {
            self::setCookie(self::SOURCE_COOKIE, $source);
        }

        $sessionId = $_COOKIE[self::SESSION_COOKIE] ?? null;
        if ($sessionId === null || $sessionId === '') {
            $sessionId = bin2hex(random_bytes(16));
            self::setCookie(self::SESSION_COOKIE, $sessionId);
        }

        return [
            'source' => $source,
            'sessionId' => $sessionId,
            'utmSource' => $utmSource,
            'utmMedium' => $utmMedium,
            'utmCampaign' => $utmCampaign,
            'referrer' => $referrer,
        ];
    }

    /**
     * Leest de al vastgestelde bron voor deze bezoeker (zonder cookies te zetten) -
     * te gebruiken bij het opslaan van een order, ruim na het eerste paginabezoek.
     */
    public static function currentSource(): ?string
    {
        $source = $_COOKIE[self::SOURCE_COOKIE] ?? null;

        return $source !== null && $source !== '' ? $source : null;
    }

    public static function isLikelyBot(): bool
    {
        $userAgent = mb_strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($userAgent === '') {
            return true;
        }

        $needles = ['bot', 'spider', 'crawl', 'slurp', 'facebookexternalhit', 'bingpreview', 'monitor', 'headless'];
        foreach ($needles as $needle) {
            if (str_contains($userAgent, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function labelForReferrer(?string $referrer): string
    {
        if ($referrer === null || $referrer === '') {
            return 'Direct';
        }

        $host = mb_strtolower((string) parse_url($referrer, PHP_URL_HOST));

        foreach (self::REFERRER_DOMAINS as $fragment => $label) {
            if (str_contains($host, $fragment)) {
                return $label;
            }
        }

        return $host !== '' ? $host : 'Direct';
    }

    private static function cleanParam(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 100);
    }

    private static function setCookie(string $name, string $value): void
    {
        if (headers_sent()) {
            return;
        }

        $isHttps = (($_SERVER['HTTPS'] ?? '') !== '') && $_SERVER['HTTPS'] !== 'off';

        setcookie($name, $value, [
            'expires' => time() + self::COOKIE_LIFETIME_DAYS * 86400,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[$name] = $value;
    }
}
