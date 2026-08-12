<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\WholesaleOrderRepository;
use App\WholesalePlatformRepository;

Auth::requireSection('wholesale');

$platforms = WholesalePlatformRepository::findAll();

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'platform_id' => trim((string) ($_GET['platform_id'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
];
$hasFilters = (bool) array_filter($filters);

$orders = WholesaleOrderRepository::search($filters);

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

const WHOLESALE_ORDERS_PER_PAGE = 25;

$totalOrders = count($orders);
$totalPages = max(1, (int) ceil($totalOrders / WHOLESALE_ORDERS_PER_PAGE));
$page = min($totalPages, max(1, (int) ($_GET['page'] ?? 1)));
$pagedOrders = array_slice($orders, ($page - 1) * WHOLESALE_ORDERS_PER_PAGE, WHOLESALE_ORDERS_PER_PAGE);

// Per valuta optellen, niet alles bij elkaar - orders van Faire zijn vaak USD,
// niet EUR zoals bij specials, dus cross-currency samenvoegen zou een zinloos getal geven.
$revenueByCurrency = [];
foreach ($orders as $order) {
    if ($order['status'] === 'canceled') {
        continue;
    }
    $revenueByCurrency[$order['currency']] = ($revenueByCurrency[$order['currency']] ?? 0) + (int) $order['total_amount_cents'];
}

$exportQuery = http_build_query($filters);

$pageTitle = 'Wholesale - Bestellingen';
$activeSection = 'wholesale';
$activePage = 'orders';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1>Wholesale-bestellingen</h1>
    <a href="orders-export.php?<?= h($exportQuery) ?>" class="btn" style="width: auto;">Exporteren naar Excel</a>
</div>

<div class="card" style="padding: 18px 22px;">
    <form method="get" action="orders.php">
        <div class="row" style="align-items: end;">
            <div class="field" style="flex: 2 1 220px;">
                <label for="q">Zoeken</label>
                <input type="text" id="q" name="q" placeholder="Shopnaam, SKU, producttitel, orderreferentie..." value="<?= h($filters['q']) ?>">
            </div>
            <div class="field" style="flex: 1 1 160px;">
                <label for="platform_id">Platform</label>
                <select id="platform_id" name="platform_id">
                    <option value="">Alle</option>
                    <?php foreach ($platforms as $platform): ?>
                        <option value="<?= (int) $platform['id'] ?>" <?= $filters['platform_id'] === (string) $platform['id'] ? 'selected' : '' ?>><?= h($platform['name']) ?></option>
                    <?php endforeach; ?>
                </select>
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
            <div class="field" style="flex: 1 1 140px;">
                <label for="date_from">Vanaf</label>
                <input type="date" id="date_from" name="date_from" value="<?= h($filters['date_from']) ?>">
            </div>
            <div class="field" style="flex: 1 1 140px;">
                <label for="date_to">Tot</label>
                <input type="date" id="date_to" name="date_to" value="<?= h($filters['date_to']) ?>">
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

<div class="stat-grid">
    <div class="stat-tile">
        <div class="value"><?= $totalOrders ?></div>
        <div class="label">Getoond (na filters)</div>
    </div>
    <?php foreach ($revenueByCurrency as $currency => $cents): ?>
        <div class="stat-tile">
            <div class="value"><?= h(money($cents, $currency)) ?></div>
            <div class="label">Omzet <?= h($currency) ?> (excl. geannuleerd, getoond)</div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <?php if ($totalOrders === 0): ?>
        <p><?= $hasFilters ? 'Geen bestellingen gevonden voor deze filters.' : 'Er zijn nog geen wholesale-bestellingen ingeladen.' ?></p>
    <?php else: ?>
        <div class="table-wrapper">
            <table>
                <thead>
                <tr>
                    <th>Platform</th>
                    <th>Referentie</th>
                    <th>Shop</th>
                    <th>Datum</th>
                    <th>Totaal</th>
                    <th>Status</th>
                    <th>Acties</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($pagedOrders as $order): ?>
                    <tr>
                        <td><span class="badge badge-channel" style="background: <?= h($order['platform_color']) ?>;"><?= h($order['platform_icon'] ?? '') ?> <?= h($order['platform_name']) ?></span></td>
                        <td><?= h($order['external_order_id']) ?></td>
                        <td><?= h($order['shop_name'] ?? '—') ?></td>
                        <td><?= h((new DateTimeImmutable($order['placed_at']))->format('d-m-Y H:i')) ?></td>
                        <td><?= h(money((int) $order['total_amount_cents'], $order['currency'])) ?></td>
                        <td><span class="badge <?= wholesaleOrderBadgeClass($order['status']) ?>"><?= h($statusLabels[$order['status']] ?? $order['status']) ?></span></td>
                        <td><a href="order-form.php?id=<?= (int) $order['id'] ?>">Bekijken</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): $params = $filters; $params['page'] = $page - 1; ?>
                    <a href="orders.php?<?= h(http_build_query($params)) ?>" class="btn btn-secondary" style="width: auto;">&laquo; Vorige</a>
                <?php endif; ?>
                <span class="pagination-info">Pagina <?= $page ?> van <?= $totalPages ?></span>
                <?php if ($page < $totalPages): $params = $filters; $params['page'] = $page + 1; ?>
                    <a href="orders.php?<?= h(http_build_query($params)) ?>" class="btn btn-secondary" style="width: auto;">Volgende &raquo;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
