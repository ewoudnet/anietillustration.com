<?php

declare(strict_types=1);

/**
 * Fase E: ontvangt Orderchamp's order-webhook (ORDER_CONFIRMED/ORDER_UPDATED/
 * ORDER_CANCELLED - zie docs/wholesale.md). De payload zelf is bewust minimaal
 * ({"data":{"order":{"id",...}}}), dus deze haalt de volledige, actuele order
 * opnieuw op via de API i.p.v. op de payload-inhoud te vertrouwen.
 *
 * LET OP: deze webhook is nog NIET geregistreerd bij Orderchamp (dat is een
 * wijziging bij een externe partij, bewust aan de gebruiker gelaten - zie
 * OrderchampService-docblock). ORDERCHAMP_WEBHOOK_SECRET is dus nog niet
 * geverifieerd tegen een echte aanroep.
 *
 * Publiek endpoint (signature-gate i.p.v. Auth::requireSection), zelfde
 * bootstrap-hergebruik als cron-faire.php.
 */

require __DIR__ . '/../bootstrap.php';

use App\Config;
use App\WholesaleOrderImporter;
use App\WholesaleStockSyncService;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Alleen POST toegestaan.']);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';
$secret = Config::get('ORDERCHAMP_WEBHOOK_SECRET', '');
$signature = (string) ($_SERVER['HTTP_X_ORDERCHAMP_SIGNATURE'] ?? '');

if ($secret === '' || $signature === '' || !hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature)) {
    error_log('webhook-orderchamp.php: ongeldige of ontbrekende signature.');
    http_response_code(401);
    echo json_encode(['error' => 'Ongeldige signature.']);
    exit;
}

$payload = json_decode($rawBody, true);
$orderId = $payload['data']['order']['id'] ?? null;

if (!is_string($orderId) || $orderId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Geen order-id in payload.']);
    exit;
}

try {
    $result = WholesaleOrderImporter::importOrderchampOrderById($orderId);

    // Fase D: de eigen (net afgeschreven) voorraad terugschrijven naar
    // Faire/Orderchamp - alleen als deze order daadwerkelijk voorraad heeft
    // aangepast.
    if ($result['stockChanged']) {
        WholesaleStockSyncService::run();
    }

    echo json_encode(['status' => 'ok', 'unmatchedSkus' => $result['unmatchedSkus']]);
} catch (\RuntimeException $e) {
    error_log('webhook-orderchamp.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
