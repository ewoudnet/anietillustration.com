<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\ImageUpload;
use App\SpecialRepository;

Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_POST['csrf_token'] ?? null)) {
    header('Location: index.php');
    exit;
}

$id = (int) ($_POST['id'] ?? 0);

if ($id > 0) {
    $repository = new SpecialRepository();
    $special = $repository->find($id);

    if ($special !== null) {
        ImageUpload::delete($special['banner_path'], SPECIALS_ASSETS_PATH);
        $repository->delete($id);
    }
}

header('Location: index.php?deleted=1');
