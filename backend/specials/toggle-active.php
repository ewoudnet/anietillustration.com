<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\SpecialRepository;

Auth::requireSection('specials');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_POST['csrf_token'] ?? null)) {
    header('Location: index.php');
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$active = (bool) ($_POST['active'] ?? false);

if ($id > 0) {
    (new SpecialRepository())->setActive($id, $active);
}

header('Location: index.php');
