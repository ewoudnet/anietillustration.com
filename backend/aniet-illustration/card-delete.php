<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\CardRepository;
use App\Csrf;
use App\ImageUpload;

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

$existing = CardRepository::find($id);
if ($existing !== null) {
    ImageUpload::delete($existing['image_path'], BO_ASSETS_PATH);
    CardRepository::delete($id);
}

header('Location: cards.php?deleted=1');
exit;
