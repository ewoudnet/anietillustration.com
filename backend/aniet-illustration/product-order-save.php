<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\ProductRepository;
use App\Csrf;

Auth::requireSection('aniet-illustration');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Methode niet toegestaan.']);
    exit;
}

$typeId = (int) ($_GET['type_id'] ?? 0);

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$productId = (int) ($payload['card_id'] ?? 0);
$toOrderRaw = $payload['to_order'] ?? null;
$submittedToken = (string) ($payload['csrf_token'] ?? '');

if (!Csrf::verify($submittedToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sessie verlopen, herlaad de pagina.']);
    exit;
}

if ($productId <= 0 || !is_numeric($toOrderRaw) || (int) $toOrderRaw < 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Ongeldige waarde.']);
    exit;
}

$product = ProductRepository::find($productId);
if ($product === null || (int) $product['product_type_id'] !== $typeId) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Product niet gevonden.']);
    exit;
}

$toOrder = (int) $toOrderRaw;
ProductRepository::updateToOrder($productId, $toOrder);

// Zelfde regel als ProductRepository::needsOrdering(): Wholesale Draft-producten
// verschijnen niet automatisch op basis van lage voorraad, alleen via een expliciete
// te-bestellen.
$needsOrdering = $toOrder > 0
    || ($product['current_stock'] !== null
        && (int) $product['current_stock'] < (int) $product['min_stock']
        && (int) $product['wholesale_draft'] === 0);

echo json_encode([
    'ok' => true,
    'card_id' => $productId,
    'to_order' => $toOrder,
    'needs_ordering' => $needsOrdering,
    'sku' => $product['sku'],
    'title' => $product['title'],
    'image_path' => $product['image_path'],
    'min_stock' => (int) $product['min_stock'],
    'current_stock' => $product['current_stock'] !== null ? (int) $product['current_stock'] : null,
]);
