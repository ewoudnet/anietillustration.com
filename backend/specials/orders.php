<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\OrderRepository;
use App\SpecialRepository;

Auth::requireLogin();

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'special_id' => trim((string) ($_GET['special_id'] ?? '')),
];
$hasFilters = (bool) array_filter($filters);

$orderRepository = new OrderRepository();
$orders = $hasFilters ? $orderRepository->search($filters) : $orderRepository->findAll();
$specials = (new SpecialRepository())->findAll();

$totalOrders = count($orders);
$paidOrders = array_filter($orders, static fn (array $o) => $o['status'] === 'paid');
$paidCount = count($paidOrders);
$paidRevenueCents = array_sum(array_map(static fn (array $o) => (int) $o['total_amount_cents'], $paidOrders));

$statusLabels = [
    'open' => 'Open',
    'paid' => 'Betaald',
    'failed' => 'Mislukt',
    'expired' => 'Verlopen',
    'canceled' => 'Geannuleerd',
];

function orderBadgeClass(string $status): string
{
    return match ($status) {
        'paid' => 'badge-paid',
        'failed', 'expired', 'canceled' => 'badge-failed',
        default => 'badge-open',
    };
}

$exportQuery = http_build_query($filters);

$pageTitle = 'Bestellingen';
$activeSection = 'specials';
$activePage = 'orders';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1>Bestellingen</h1>
    <a href="orders-export.php?<?= h($exportQuery) ?>" class="btn" style="width: auto;">Exporteren naar Excel</a>
</div>

<div class="card" style="padding: 18px 22px;">
    <form method="get" action="orders.php">
        <div class="row" style="align-items: end;">
            <div class="field" style="flex: 2 1 220px;">
                <label for="q">Zoeken</label>
                <input type="text" id="q" name="q" placeholder="Naam, e-mail, referentie, plaats..." value="<?= h($filters['q']) ?>">
            </div>
            <div class="field" style="flex: 1 1 160px;">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">Alle</option>
                    <?php foreach ($statusLabels as $value => $label): ?>
                        <option value="<?= h($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="flex: 1 1 220px;">
                <label for="special_id">Special</label>
                <select id="special_id" name="special_id">
                    <option value="">Alle</option>
                    <?php foreach ($specials as $special): ?>
                        <option value="<?= (int) $special['id'] ?>" <?= $filters['special_id'] === (string) $special['id'] ? 'selected' : '' ?>><?= h($special['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="flex: 0 0 auto;">
                <button type="submit" class="btn" style="width: auto; margin-top: 0;">Filteren</button>
            </div>
            <?php if ($hasFilters): ?>
                <div class="field" style="flex: 0 0 auto;">
                    <a href="orders.php" class="btn btn-secondary" style="width: auto; margin-top: 0; display: inline-block;">Wis filters</a>
                </div>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="row" style="margin: 18px 0;">
    <div class="card" style="flex: 1 1 160px; text-align: center;">
        <div style="font-size: 1.5rem; font-weight: 700;"><?= $totalOrders ?></div>
        <div>Getoond (na filters)</div>
    </div>
    <div class="card" style="flex: 1 1 160px; text-align: center;">
        <div style="font-size: 1.5rem; font-weight: 700;"><?= $paidCount ?></div>
        <div>Betaald</div>
    </div>
    <div class="card" style="flex: 1 1 160px; text-align: center;">
        <div style="font-size: 1.5rem; font-weight: 700;">€ <?= h(number_format($paidRevenueCents / 100, 2, ',', '.')) ?></div>
        <div>Omzet (betaald, getoond)</div>
    </div>
</div>

<div class="card">
    <?php if ($totalOrders === 0): ?>
        <p><?= $hasFilters ? 'Geen bestellingen gevonden voor deze filters.' : 'Er zijn nog geen bestellingen.' ?></p>
    <?php else: ?>
        <div class="table-wrapper">
            <table>
                <thead>
                <tr>
                    <th>Referentie</th>
                    <th>Special</th>
                    <th>Variant</th>
                    <th>Datum</th>
                    <th>Naam</th>
                    <th>E-mail</th>
                    <th>Adres</th>
                    <th>Aantal</th>
                    <th>Totaal</th>
                    <th>Status</th>
                    <th>Mail verzonden</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><?= h($order['order_reference']) ?></td>
                        <td><?= h($order['special_title'] ?? '—') ?></td>
                        <td><?= h($order['variant_label'] ?? '—') ?></td>
                        <td><?= h((new DateTimeImmutable($order['created_at']))->format('d-m-Y H:i')) ?></td>
                        <td><?= h($order['first_name'] . ' ' . $order['last_name']) ?></td>
                        <td><?= h($order['email']) ?></td>
                        <td><?= h($order['street'] . ' ' . $order['house_number'] . ', ' . $order['postal_code'] . ' ' . $order['city']) ?></td>
                        <td><?= (int) $order['quantity'] ?></td>
                        <td>€ <?= h(number_format(((int) $order['total_amount_cents']) / 100, 2, ',', '.')) ?></td>
                        <td><span class="badge <?= orderBadgeClass($order['status']) ?>"><?= h($statusLabels[$order['status']] ?? $order['status']) ?></span></td>
                        <td><?= $order['confirmation_email_sent_at'] !== null ? '✅' : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
