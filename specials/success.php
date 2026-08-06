<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\MollieService;
use App\OrderRepository;
use App\PaymentStatusSync;

$reference = trim((string) ($_GET['order'] ?? ''));
$order = null;

if ($reference !== '') {
    $orderRepository = new OrderRepository();
    $order = $orderRepository->findByReference($reference);

    if ($order !== null && $order['status'] === 'open') {
        try {
            $order = PaymentStatusSync::sync($order, $orderRepository, new MollieService());
        } catch (\Throwable $e) {
            error_log('Statuscheck op bedankpagina mislukt voor ' . $reference . ': ' . $e->getMessage());
        }
    }
}

$status = $order['status'] ?? null;
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bestelling <?= $order ? h($order['order_reference']) : '' ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="page">
    <div class="header">
        <h1>🎁 Aniet Illustration</h1>
    </div>

    <div class="card">
        <?php if ($order === null): ?>
            <div class="alert alert-error">
                We konden deze bestelling niet vinden. Controleer de link of neem contact met ons op.
            </div>
        <?php elseif ($status === 'paid'): ?>
            <div class="alert alert-success">
                Bedankt! Je betaling is gelukt en je bestelling is bevestigd.
            </div>
            <p>Bestelnummer: <strong><?= h($order['order_reference']) ?></strong></p>
            <p>Totaal: € <?= h(number_format(((int) $order['total_amount_cents']) / 100, 2, ',', '.')) ?></p>
            <p>We hebben een bevestiging gestuurd naar <?= h($order['email']) ?>.</p>
        <?php elseif (in_array($status, ['canceled', 'failed', 'expired'], true)): ?>
            <div class="alert alert-error">
                De betaling is niet gelukt (status: <?= h($status) ?>). Er is niets afgeschreven.
            </div>
            <p>Bestelnummer: <strong><?= h($order['order_reference']) ?></strong></p>
            <p><a href="index.php">Terug naar het overzicht</a></p>
        <?php else: ?>
            <div class="alert alert-success">
                We verwerken je betaling nog. Dit kan enkele ogenblikken duren; ververs deze pagina zo nodig.
            </div>
            <p>Bestelnummer: <strong><?= h($order['order_reference']) ?></strong></p>
        <?php endif; ?>
    </div>

    <p class="footer-note">
        &copy; <?= date('Y') ?> Aniet Illustration &middot;
        <a href="https://www.anietillustration.com" target="_blank" rel="noopener">anietillustration.com</a> &middot;
        <a href="https://www.instagram.com/aniet_illustration" target="_blank" rel="noopener">Instagram</a>
    </p>
</div>
</body>
</html>
