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
    session_start();
}

date_default_timezone_set('Europe/Amsterdam');

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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
