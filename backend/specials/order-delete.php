<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\OrderRepository;

Auth::requireSection('specials');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_POST['csrf_token'] ?? null)) {
    header('Location: orders.php');
    exit;
}

$id = (int) ($_POST['id'] ?? 0);

if ($id > 0) {
    (new OrderRepository())->delete($id);
}

header('Location: orders.php?deleted=' . $id);
