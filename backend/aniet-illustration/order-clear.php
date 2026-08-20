<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\CardRepository;
use App\Csrf;

Auth::requireSection('aniet-illustration');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: order.php');
    exit;
}

if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
    header('Location: order.php');
    exit;
}

CardRepository::clearAllToOrder();

header('Location: order.php?cleared=1');
exit;
