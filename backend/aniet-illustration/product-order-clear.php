<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\ProductRepository;
use App\ProductTypeRepository;
use App\Csrf;

Auth::requireSection('aniet-illustration');

$typeId = (int) ($_POST['type_id'] ?? 0);
$productType = $typeId > 0 ? ProductTypeRepository::find($typeId) : null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $productType === null) {
    header('Location: product-order.php?type_id=' . $typeId);
    exit;
}

if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
    header('Location: product-order.php?type_id=' . $typeId);
    exit;
}

ProductRepository::clearAllToOrder($typeId);

header('Location: product-order.php?type_id=' . $typeId . '&cleared=1');
exit;
