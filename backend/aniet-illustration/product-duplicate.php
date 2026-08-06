<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\ImageUpload;
use App\ProductRepository;

Auth::requireSection('aniet-illustration');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: cards.php');
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
if (!Csrf::verify((string) ($_POST['csrf_token'] ?? '')) || $id <= 0) {
    header('Location: cards.php');
    exit;
}

$original = ProductRepository::find($id);
if ($original === null) {
    header('Location: cards.php');
    exit;
}

$typeId = (int) $original['product_type_id'];
$newSku = ProductRepository::suggestNextSku($original['sku']);

$data = [
    'product_type_id' => $typeId,
    'sku' => $newSku,
    'title' => $original['title'],
    'image_path' => ImageUpload::copy($original['image_path'], BO_ASSETS_PATH),
    'min_stock' => $original['min_stock'],
    'current_stock' => null,
    'to_order' => 0,
    'wholesale_draft' => $original['wholesale_draft'],
    'comments' => $original['comments'],
];

$newId = ProductRepository::create($data);

header('Location: product-form.php?type_id=' . $typeId . '&id=' . $newId . '&duplicated=1');
exit;
