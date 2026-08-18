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
use App\WholesaleStockSyncService;

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

// Alles hier expliciet in UTC. bootstrap.php zet de standaardtijdzone op
// Europe/Amsterdam, dus een kale `new DateTimeImmutable()` levert lokale tijd
// op; die vervolgens als "...Z" naar Faire sturen maakt het hoogwatermerk in
// de zomer 2 uur te hoog, waardoor elke volgende run orders zou overslaan.
$utc = new DateTimeZone('UTC');
$now = new DateTimeImmutable('now', $utc);

// Vastleggen VOOR het ophalen begint, zodat orders die tijdens deze run
// binnenkomen niet worden overgeslagen bij de volgende run.
$runStartedAt = $now;

$stored = $platform['last_synced_at'] !== null
    ? new DateTimeImmutable($platform['last_synced_at'], $utc)
    : null;

// Geen eerder merk: een dag terugkijken i.p.v. de volledige historie (die
// hoort via de handmatige import binnen te komen) - voorkomt een onnodig
// zware eerste cron-run. Een merk in de TOEKOMST kan alleen fout zijn (zoals
// de tijdzonefout hierboven aanrichtte); dan ook liever een dag terugkijken
// dan stilzwijgend elke order missen, zodat een scheve waarde zichzelf
// herstelt zonder handmatig ingrijpen in de database.
$createdAtMin = ($stored === null || $stored > $now)
    ? $now->sub(new DateInterval('P1D'))
    : $stored;

$imported = 0;
$unmatchedSkus = [];
$stockChanged = false;
$cursor = null;

try {
    do {
        $page = WholesaleOrderImporter::importFairePage($cursor, $createdAtMin->format('Y-m-d\TH:i:s\Z'), true);
        $imported += $page['imported'];
        $unmatchedSkus = array_merge($unmatchedSkus, $page['unmatchedSkus']);
        $stockChanged = $stockChanged || $page['stockChanged'];
        $cursor = $page['nextCursor'];
    } while (!$page['done']);

    // Opslaan in MySQL-DATETIME-formaat (zonder T/Z) en altijd in UTC - zie de
    // toelichting bovenaan; het teruglezen hierboven gaat van diezelfde
    // aanname uit.
    WholesalePlatformRepository::updateLastSyncedAt(
        (int) $platform['id'],
        $runStartedAt->format('Y-m-d H:i:s')
    );

    // Fase D: pas nu de eigen (net afgeschreven) voorraad terugschrijven naar
    // Faire/Orderchamp - alleen als deze run daadwerkelijk voorraad heeft
    // aangepast, anders levert WholesaleStockSyncService::run() sowieso geen
    // discrepanties op maar is de aanroep nodeloos.
    if ($stockChanged) {
        WholesaleStockSyncService::run();
    }

    echo json_encode([
        'status' => 'ok',
        'imported' => $imported,
        'unmatchedSkus' => array_values(array_unique($unmatchedSkus)),
        'syncedFrom' => $createdAtMin->format('Y-m-d\TH:i:s\Z'),
        'watermarkSetTo' => $runStartedAt->format('Y-m-d\TH:i:s\Z'),
    ]);
} catch (\RuntimeException $e) {
    error_log('cron-faire.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
