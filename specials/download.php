<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\OrderRepository;
use App\SpecialDownloadRepository;

/**
 * Persoonlijke downloadpagina: ?t={token} toont de bestanden, ?t={token}&f={id} levert één
 * bestand uit. Alleen voor betaalde, digitale orders - de bestanden zelf staan buiten de
 * webroot en zijn nergens anders bereikbaar.
 */
$token = trim((string) ($_GET['t'] ?? ''));
$fileId = isset($_GET['f']) ? (int) $_GET['f'] : 0;

$order = $token !== '' ? (new OrderRepository())->findByDownloadToken($token) : null;
$files = [];

if ($order !== null && $order['status'] === 'paid' && $order['special_id'] !== null) {
    $files = (new SpecialDownloadRepository())->findBySpecial((int) $order['special_id']);
} else {
    $order = $order !== null && $order['status'] !== 'paid' ? $order : null;
}

if ($fileId > 0) {
    foreach ($files as $file) {
        if ((int) $file['id'] !== $fileId) {
            continue;
        }

        $path = SpecialDownloadRepository::pathFor($file);
        if (!is_file($path)) {
            break;
        }

        $safeName = str_replace(['"', "\r", "\n", '\\'], '_', (string) $file['original_name']);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode((string) $file['original_name']));
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        readfile($path);
        exit;
    }

    http_response_code(404);
}

header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');

function formatSize(int $bytes): string
{
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.') . ' MB' : number_format(max(1, $bytes / 1024), 0, ',', '.') . ' KB';
}
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Jouw download - Aniet Illustration</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body>
<div class="page">
    <div class="header">
        <h1>🎁 Aniet Illustration</h1>
    </div>

    <div class="card">
        <?php if ($order === null): ?>
            <div class="alert alert-error">Deze downloadlink is ongeldig. Controleer de link in je bevestigingsmail of neem contact met ons op.</div>
        <?php elseif ($order['status'] !== 'paid'): ?>
            <div class="alert alert-error">Je downloads zijn beschikbaar zodra de betaling is voltooid. Ververs deze pagina na het betalen.</div>
        <?php elseif (empty($files)): ?>
            <div class="alert alert-error">Er zijn momenteel geen bestanden beschikbaar. Neem contact met ons op.</div>
        <?php else: ?>
            <h2><?= h($order['special_title'] ?? 'Jouw download') ?></h2>
            <p>Bedankt voor je bestelling <strong><?= h($order['order_reference']) ?></strong>! Download hieronder je bestand(en):</p>
            <ul class="download-list">
                <?php foreach ($files as $file): ?>
                    <li>
                        <a class="btn" style="display: inline-block; width: auto;" href="download.php?t=<?= h(urlencode($token)) ?>&amp;f=<?= (int) $file['id'] ?>">
                            ⬇ <?= h($file['original_name']) ?>
                        </a>
                        <small>(<?= h(formatSize((int) $file['size_bytes'])) ?>)</small>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
