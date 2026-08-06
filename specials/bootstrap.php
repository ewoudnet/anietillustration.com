<?php

declare(strict_types=1);

/**
 * Zoekt vendor/autoload.php op twee mogelijke plekken, zie backend/bootstrap.php voor de
 * uitleg. Eigen kopie omdat specials/ een losse webroot-submap naast backend/ is (zelfde
 * patroon als kalender2027/bootstrap.php naast advent/bootstrap.php).
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
        'vendor-map op de juiste plek naast specials/ (of in anietillustration-core/)? Zie README.md.'
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
