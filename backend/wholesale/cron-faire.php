<?php

declare(strict_types=1);

/**
 * Fase E: periodieke nieuwe-order-detectie voor Faire (dat, in tegenstelling
 * tot Orderchamp, GEEN webhook-API heeft - geverifieerd door de volledige
 * OpenAPI-spec op developers.faire.com door te zoeken, geen "webhook"-pad
 * aanwezig). Bedoeld om via de hosting-cron periodiek als URL aangeroepen te
 * worden (FTP-only shared hosting, geen SSH/CLI-cron) - vandaar de
 * secret-gate i.p.v. Auth::requireSection.
 *
 * Publiek endpoint (secret-gate i.p.v. Auth::requireSection) - gebruikt wel
 * bootstrap.php voor de vendor-detectie die submap-hosting nodig heeft (zie
 * bootstrap.php), de sessie die dat bijstart is voor deze aanroep onschadelijk.
 */

require __DIR__ . '/../bootstrap.php';

use App\Config;
use App\FaireService;
use App\WholesaleOrderImporter;
use App\WholesalePlatformRepository;

header('Content-Type: application/json');

$secret = Config::get('WHOLESALE_CRON_SECRET', '');
$provided = (string) ($_GET['secret'] ?? '');

if ($secret === '' || !hash_equals($secret, $provided)) {
    http_response_code(403);
    echo json_encode(['error' => 'Ongeldig of ontbrekend secret.']);
    exit;
}

if (!FaireService::isConfigured()) {
    http_response_code(500);
    echo json_encode(['error' => 'Faire-credentials zijn nog niet ingesteld in .env.']);
    exit;
}

$platform = WholesalePlatformRepository::findByCode('faire');
if ($platform === null) {
    http_response_code(500);
    echo json_encode(['error' => 'Platform "faire" niet gevonden in wholesale_platforms.']);
    exit;
}

// Vastleggen VOOR het ophalen begint (hoogwatermerk), zodat orders die
// tijdens deze run binnenkomen niet worden overgeslagen bij de volgende run.
$runStartedAt = (new DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');

// Eerste run zonder eerdere last_synced_at: een dag terugkijken i.p.v. de
// volledige historie (die is al binnen via de fase B-import) - voorkomt een
// onnodig zware eerste cron-run.
$createdAtMin = $platform['last_synced_at'] !== null
    ? (new DateTimeImmutable($platform['last_synced_at']))->format('Y-m-d\TH:i:s\Z')
    : (new DateTimeImmutable('-1 day'))->format('Y-m-d\TH:i:s\Z');

$imported = 0;
$unmatchedSkus = [];
$cursor = null;

try {
    do {
        $page = WholesaleOrderImporter::importFairePage($cursor, $createdAtMin, true);
        $imported += $page['imported'];
        $unmatchedSkus = array_merge($unmatchedSkus, $page['unmatchedSkus']);
        $cursor = $page['nextCursor'];
    } while (!$page['done']);

    WholesalePlatformRepository::updateLastSyncedAt((int) $platform['id'], $runStartedAt);

    echo json_encode([
        'status' => 'ok',
        'imported' => $imported,
        'unmatchedSkus' => array_values(array_unique($unmatchedSkus)),
        'syncedFrom' => $createdAtMin,
    ]);
} catch (\RuntimeException $e) {
    error_log('cron-faire.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
