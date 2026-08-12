<?php

declare(strict_types=1);

/**
 * Zoekt vendor/autoload.php op twee mogelijke plekken:
 *  1. Standaard: als sibling van backend/ (projectroot/vendor) - lokaal draaien of een eigen
 *     (sub)domein waar backend/ zelf de documentroot is.
 *  2. Submap-vriendelijk: in een aparte, met .htaccess afgeschermde map naast backend/ en
 *     specials/ (public_html/anietillustration-core/vendor) - nodig op aniet.nl-submappen
 *     waar de documentroot niet te verleggen is. Zie README.md.
 */
$vendorCandidates = [
    dirname(__DIR__) . '/vendor/autoload.php',
    dirname(__DIR__) . '/anietillustration-core/vendor/autoload.php',
];

$autoloadPath = null;
foreach ($vendorCandidates as $candidate) {
    if (file_exists($candidate)) {
        $autoloadPath = $candidate;
        break;
    }
}

if ($autoloadPath === null) {
    throw new RuntimeException(
        'vendor/autoload.php niet gevonden. Heb je "composer install" gedraaid en staat de ' .
        'vendor-map op de juiste plek naast backend/ (of in anietillustration-core/)? Zie README.md.'
    );
}

require $autoloadPath;

use App\Config;

Config::load();

if (session_status() === PHP_SESSION_NONE) {
    // Expliciete SameSite/Secure-cookie-instellingen - zonder deze bleek het sessie-cookie
    // op sommige mobiele browsers niet betrouwbaar terug te komen tussen het laden van
    // login.php (CSRF-token) en het versturen van het formulier, met "sessie verlopen" als
    // gevolg. secure alleen aanzetten op HTTPS, anders werkt lokaal (plain http) niet meer.
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

date_default_timezone_set('Europe/Amsterdam');

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Formatteert een DATE-string (Y-m-d) als Nederlandse datum (dd-mm-jjjj) voor weergave.
 */
function nlDate(?string $isoDate): string
{
    if ($isoDate === null || $isoDate === '') {
        return '—';
    }

    $date = DateTime::createFromFormat('Y-m-d', $isoDate);

    return $date === false ? '—' : $date->format('d-m-Y');
}

/**
 * Zet een Nederlandse datum-invoer (dd-mm-jjjj) om naar Y-m-d voor opslag, of null bij
 * een leeg/ongeldig veld.
 */
function parseNlDate(string $input): ?string
{
    $input = trim($input);

    if ($input === '') {
        return null;
    }

    $date = DateTime::createFromFormat('d-m-Y', $input);
    if ($date === false || $date->format('d-m-Y') !== $input) {
        return null;
    }

    return $date->format('Y-m-d');
}

/**
 * Afgeleide Greetz-status van een kaart, gebaseerd op rejected_date/psd_filename/
 * submission_date. Alleen zinvol voor kaarten die Greetz als verkoopkanaal hebben.
 *
 * @param array<string, mixed> $card
 */
function greetzStatusLabel(array $card): string
{
    if (!empty($card['rejected_date'])) {
        return 'Afgewezen';
    }

    if (!empty($card['psd_filename'])) {
        return 'Actief';
    }

    if (!empty($card['submission_date'])) {
        return 'Ingediend';
    }

    return 'Nog in te sturen';
}

/**
 * @param array<string, mixed> $card
 */
function greetzStatusBadgeClass(array $card): string
{
    return match (greetzStatusLabel($card)) {
        'Afgewezen' => 'badge-failed',
        'Actief' => 'badge-paid',
        'Ingediend' => 'badge-open',
        default => 'badge-muted',
    };
}

/**
 * Rendert "Vorige/Volgende"-paginering die de huidige queryparameters (zoekterm,
 * filters) behoudt. $queryParams moet geen 'page' key bevatten.
 *
 * @param array<string, mixed> $queryParams
 */
function renderPagination(int $page, int $totalPages, array $queryParams, string $baseUrl): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $prevParams = $queryParams;
    $prevParams['page'] = max(1, $page - 1);
    $nextParams = $queryParams;
    $nextParams['page'] = min($totalPages, $page + 1);

    $html = '<div class="pagination">';
    $html .= $page > 1
        ? '<a href="' . h($baseUrl . '?' . http_build_query($prevParams)) . '">← Vorige</a>'
        : '<span class="pagination-disabled">← Vorige</span>';
    $html .= '<span class="pagination-status">Pagina ' . $page . ' van ' . $totalPages . '</span>';
    $html .= $page < $totalPages
        ? '<a href="' . h($baseUrl . '?' . http_build_query($nextParams)) . '">Volgende →</a>'
        : '<span class="pagination-disabled">Volgende →</span>';
    $html .= '</div>';

    return $html;
}

/**
 * Sorteerbare kolomkop: link die de sorteerkolom/-richting toggelt, met een pijltje
 * als deze kolom actief is. $queryParams moet geen 'sort'/'dir' keys bevatten.
 *
 * @param array<string, mixed> $queryParams
 */
function sortHeader(string $column, string $label, string $currentSort, string $currentDir, array $queryParams, string $baseUrl): string
{
    $isActive = $currentSort === $column;
    $nextDir = ($isActive && $currentDir === 'asc') ? 'desc' : 'asc';

    $params = $queryParams;
    $params['sort'] = $column;
    $params['dir'] = $nextDir;

    $arrow = $isActive ? ($currentDir === 'asc' ? ' ▲' : ' ▼') : '';

    return '<a class="sort-link" href="' . h($baseUrl . '?' . http_build_query($params)) . '">'
        . h($label) . $arrow . '</a>';
}

/**
 * Bedrag met valuta-aanduiding, voor Wholesale (Faire/Orderchamp-orders zijn niet
 * altijd EUR, in tegenstelling tot specials - dus geen hardcoded "€" zoals daar).
 */
function money(int $cents, string $currency): string
{
    $symbols = ['EUR' => '€', 'USD' => '$', 'GBP' => '£'];
    $symbol = $symbols[$currency] ?? ($currency . ' ');

    return $symbol . number_format($cents / 100, 2, ',', '.');
}

/**
 * Live, publieke URL van een special (t.b.v. "bekijk live"-links in de backend), via de
 * deelbare slug als die er is, anders het ?s={id}-fallbackformaat.
 *
 * @param array<string, mixed> $special
 */
function specialPublicUrl(array $special): string
{
    return !empty($special['slug'])
        ? Config::publicUrl() . '/' . $special['slug']
        : Config::publicUrl() . '/?s=' . (int) $special['id'];
}

/**
 * Root-relative URL-pad naar backend/, onafhankelijk van submap-diepte (nodig omdat
 * secties elkaar cross-directory linken, bijv. vanuit backend/specials/ naar een
 * toekomstige backend/products/). Werkt zowel lokaal (php -S -t backend, geeft '')
 * als op een aniet.nl-submap (geeft bijv. '/backend').
 */
$documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
$backendDir = realpath(__DIR__);
$backendUrlPath = '';
if ($documentRoot !== false && $backendDir !== false && str_starts_with($backendDir, $documentRoot)) {
    $backendUrlPath = str_replace('\\', '/', substr($backendDir, strlen($documentRoot)));
}
define('BACKEND_BASE', rtrim($backendUrlPath, '/'));

/**
 * Banner-uploads horen fysiek in specials/assets/ (de publieke webroot die de banners toont),
 * niet in backend/assets/ - backend en specials/ zijn twee losse webroot-submappen. Het
 * admin-voorbeeld verwijst er daarom via een absolute URL naar (Config::appUrl(), dezelfde
 * config-waarde als de publieke specials-site), zoals kalender2027/ ook assets van advent/ via
 * APP_URL laadt in plaats van een relatief pad.
 */
define('SPECIALS_ASSETS_PATH', dirname(__DIR__) . '/specials/assets');

/**
 * Kaart-/product-/kanaal-logo-afbeeldingen (Aniet Illustration + Settings) blijven
 * fysiek staan waar de losstaande aniet.nl/backoffice-tool ze al beheert - beide tools
 * werken op dezelfde aniet_backoffice-database, dus moeten ook naar dezelfde bestanden
 * wijzen (zie Config/.env voor de env-keys, geen dubbele upload-map hier). Env leeg =
 * val terug op de live sibling-locatie naast backend/ (klopt alleen op de server, waar
 * backend/ en backoffice/ siblings zijn onder public_html, zie deploy.yml).
 */
define('BO_ASSETS_PATH', Config::get('BO_ASSETS_PATH') ?? dirname(__DIR__) . '/backoffice/assets');
define('BO_ASSETS_URL', Config::get('BO_ASSETS_URL') ?? 'https://aniet.nl/backoffice/assets');
