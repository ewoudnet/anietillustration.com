<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\WholesaleOrderRepository;

Auth::requireSection('wholesale');

$order = WholesaleOrderRepository::find((int) ($_GET['id'] ?? 0));

if ($order === null) {
    http_response_code(404);
    $pageTitle = 'Wholesale - Bestelling niet gevonden';
    $activeSection = 'wholesale';
    $activePage = 'orders';
    require __DIR__ . '/../partials/layout-start.php';
    echo '<div class="card"><p>Deze bestelling bestaat niet (meer).</p></div>';
    require __DIR__ . '/../partials/layout-end.php';
    exit;
}

$statusLabels = [
    'open' => 'Open',
    'confirmed' => 'Bevestigd',
    'shipped' => 'Onderweg',
    'delivered' => 'Geleverd',
    'canceled' => 'Geannuleerd',
];

function wholesaleOrderBadgeClass(string $status): string
{
    return match ($status) {
        'delivered' => 'badge-paid',
        'canceled' => 'badge-failed',
        'shipped', 'confirmed' => 'badge-on',
        default => 'badge-open',
    };
}

$pageTitle = 'Wholesale - Bestelling ' . $order['external_order_id'];
$activeSection = 'wholesale';
$activePage = 'orders';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1><?= h($order['platform_icon'] ?? '') ?> <?= h($order['external_order_id']) ?></h1>
    <a href="orders.php" class="btn btn-secondary" style="width: auto;">&laquo; Terug naar overzicht</a>
</div>

<div class="card" style="margin-bottom: 20px;">
    <div class="row">
        <div style="flex: 1 1 200px;"><strong>Platform</strong><br><?= h($order['platform_name']) ?></div>
        <div style="flex: 1 1 200px;"><strong>Shop</strong><br><?= h($order['shop_name'] ?? '—') ?></div>
        <div style="flex: 1 1 200px;"><strong>Geplaatst op</strong><br><?= h((new DateTimeImmutable($order['placed_at']))->format('d-m-Y H:i')) ?></div>
        <div style="flex: 1 1 200px;"><strong>Status</strong><br><span class="badge <?= wholesaleOrderBadgeClass($order['status']) ?>"><?= h($statusLabels[$order['status']] ?? $order['status']) ?></span></div>
        <div style="flex: 1 1 200px;"><strong>Totaal</strong><br>€ <?= h(number_format(((int) $order['total_amount_cents']) / 100, 2, ',', '.')) ?></div>
    </div>
    <?php if ($order['canceled_at'] !== null): ?>
        <p class="hint" style="margin-top: 12px;">Geannuleerd op <?= h((new DateTimeImmutable($order['canceled_at']))->format('d-m-Y H:i')) ?>.</p>
    <?php endif; ?>
</div>

<div class="card">
    <h3 style="margin-top: 0;">Regels</h3>
    <?php if (count($order['items']) === 0): ?>
        <p>Geen regels bekend voor deze bestelling.</p>
    <?php else: ?>
        <div class="table-wrapper">
            <table>
                <thead>
                <tr>
                    <th>SKU</th>
                    <th>Titel</th>
                    <th>Aantal</th>
                    <th>Prijs per stuk</th>
                    <th>Gekoppeld product</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($order['items'] as $item): ?>
                    <tr>
                        <td><?= h($item['sku']) ?></td>
                        <td><?= h($item['title_snapshot']) ?></td>
                        <td><?= (int) $item['quantity'] ?></td>
                        <td>€ <?= h(number_format(((int) $item['unit_price_cents']) / 100, 2, ',', '.')) ?></td>
                        <td>
                            <?php if ($item['product_id'] !== null): ?>
                                <span class="badge badge-on">Gekoppeld</span>
                            <?php else: ?>
                                <span class="badge badge-failed">Niet gematcht</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
