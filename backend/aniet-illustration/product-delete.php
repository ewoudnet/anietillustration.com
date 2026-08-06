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
$submittedToken = (string) ($_POST['csrf_token'] ?? '');

if (!Csrf::verify($submittedToken) || $id <= 0) {
    header('Location: cards.php');
    exit;
}

$existing = ProductRepository::find($id);
if ($existing === null) {
    header('Location: cards.php');
    exit;
}

$typeId = (int) $existing['product_type_id'];
ImageUpload::delete($existing['image_path'], BO_ASSETS_PATH);
ProductRepository::delete($id);

header('Location: products.php?type_id=' . $typeId . '&deleted=1');
exit;
