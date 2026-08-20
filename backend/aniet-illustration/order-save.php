<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\CardRepository;
use App\Csrf;

Auth::requireSection('aniet-illustration');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Methode niet toegestaan.']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$cardId = (int) ($payload['card_id'] ?? 0);
$toOrderRaw = $payload['to_order'] ?? null;
$submittedToken = (string) ($payload['csrf_token'] ?? '');

if (!Csrf::verify($submittedToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sessie verlopen, herlaad de pagina.']);
    exit;
}

if ($cardId <= 0 || !is_numeric($toOrderRaw) || (int) $toOrderRaw < 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Ongeldige waarde.']);
    exit;
}

$card = CardRepository::find($cardId);
if ($card === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Kaart niet gevonden.']);
    exit;
}

$toOrder = (int) $toOrderRaw;
CardRepository::updateToOrder($cardId, $toOrder);

// Zelfde regel als CardRepository::needsOrdering(): alleen handmatig op "te bestellen"
// gezette kaarten verschijnen in de bestellijst, geen automatische voorraad-selectie.
$needsOrdering = $toOrder > 0;

echo json_encode([
    'ok' => true,
    'card_id' => $cardId,
    'to_order' => $toOrder,
    'needs_ordering' => $needsOrdering,
    'sku' => $card['sku'],
    'title' => $card['title'],
    'image_path' => $card['image_path'],
    'min_stock' => (int) $card['min_stock'],
    'current_stock' => $card['current_stock'] !== null ? (int) $card['current_stock'] : null,
]);
