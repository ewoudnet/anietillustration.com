<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\AdventOrderRepository;
use App\Csrf;
use App\OrderRepository;
use App\SpecialRepository;

Auth::requireSection('specials');

$csrfToken = Csrf::token();

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'special_id' => trim((string) ($_GET['special_id'] ?? '')),
];
$hasFilters = (bool) array_filter($filters);

$orderRepository = new OrderRepository();
$orders = $hasFilters ? $orderRepository->search($filters) : $orderRepository->findAll();
$specials = (new SpecialRepository())->findAll();
$adventOrders = (new AdventOrderRepository())->findAll();

$adventTypeLabels = [
    'advent' => 'Adventkalender',
    'kalender2027' => 'Kalender 2027',
];

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

const ORDERS_PER_PAGE = 25;

/**
 * @param array<int, array<string,mixed>> $items
 * @return array{0: array<int, array<string,mixed>>, 1: int, 2: int}
 */
function paginate(array $items, string $pageParam): array
{
    $totalPages = max(1, (int) ceil(count($items) / ORDERS_PER_PAGE));
    $page = min($totalPages, max(1, (int) ($_GET[$pageParam] ?? 1)));

    return [array_slice($items, ($page - 1) * ORDERS_PER_PAGE, ORDERS_PER_PAGE), $page, $totalPages];
}

function renderOrdersPagination(string $pageParam, int $currentPage, int $totalPages): void
{
    if ($totalPages <= 1) {
        return;
    }

    $params = $_GET;
    $buildUrl = static function (int $page) use ($params, $pageParam): string {
        $params[$pageParam] = $page;

        return 'orders.php?' . http_build_query($params);
    };
    ?>
    <div class="pagination">
        <?php if ($currentPage > 1): ?>
            <a href="<?= h($buildUrl($currentPage - 1)) ?>" class="btn btn-secondary" style="width: auto;">&laquo; Vorige</a>
        <?php endif; ?>
        <span class="pagination-info">Pagina <?= $currentPage ?> van <?= $totalPages ?></span>
        <?php if ($currentPage < $totalPages): ?>
            <a href="<?= h($buildUrl($currentPage + 1)) ?>" class="btn btn-secondary" style="width: auto;">Volgende &raquo;</a>
        <?php endif; ?>
    </div>
    <?php
}

[$pagedOrders, $ordersPage, $ordersTotalPages] = paginate($orders, 'page');
[$pagedAdventOrders, $adventPage, $adventTotalPages] = paginate($adventOrders, 'advent_page');

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

<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Bestelling is bijgewerkt.</div>
<?php elseif (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">Bestelling is verwijderd.</div>
<?php endif; ?>

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
                    <th>Acties</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($pagedOrders as $order): ?>
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
                        <td>
                            <div class="actions-dropdown">
                                <button type="button" class="icon-btn actions-trigger" title="Acties" aria-label="Acties">⋮</button>
                                <div class="actions-menu">
                                    <a href="order-form.php?id=<?= (int) $order['id'] ?>">✏️ Bewerken</a>
                                    <form method="post" action="order-delete.php"
                                          onsubmit="return confirm('Weet je zeker dat je deze bestelling wilt verwijderen? Dit kan niet ongedaan worden gemaakt.');">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                                        <button type="submit" class="danger">🗑️ Verwijderen</button>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php renderOrdersPagination('page', $ordersPage, $ordersTotalPages); ?>
    <?php endif; ?>
</div>

<div class="page-header" style="margin-top: 32px;">
    <h1>Advent bestellingen (bestaand systeem)</h1>
</div>
<p>Bestellingen uit het losse, nog operationele <code>adventskaarten-bestellen</code>-project — alleen ter inzage, beheer hiervan blijft in de eigen admin van dat project.</p>

<div class="card">
    <?php if (count($adventOrders) === 0): ?>
        <p>Er zijn nog geen advent bestellingen.</p>
    <?php else: ?>
        <div class="table-wrapper">
            <table>
                <thead>
                <tr>
                    <th>Referentie</th>
                    <th>Type</th>
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
                <?php foreach ($pagedAdventOrders as $order): ?>
                    <tr>
                        <td><?= h($order['order_reference']) ?></td>
                        <td><?= h($adventTypeLabels[$order['product_type']] ?? ucfirst($order['product_type'])) ?></td>
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
        <?php renderOrdersPagination('advent_page', $adventPage, $adventTotalPages); ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
