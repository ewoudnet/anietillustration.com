<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\OrderRepository;
use App\PageViewRepository;
use App\SpecialRepository;

Auth::requireSection('specials');

$periodLabels = [
    'today' => 'Vandaag',
    '7d' => 'Laatste 7 dagen',
    '30d' => 'Laatste 30 dagen',
    'all' => 'Altijd',
];

$period = (string) ($_GET['period'] ?? '30d');
if (!isset($periodLabels[$period])) {
    $period = '30d';
}

$specials = (new SpecialRepository())->findAll();
$specialFilter = trim((string) ($_GET['special_id'] ?? ''));
$specialId = $specialFilter !== '' ? (int) $specialFilter : null;
if ($specialId !== null && !in_array($specialId, array_map(static fn (array $s) => (int) $s['id'], $specials), true)) {
    $specialId = null;
    $specialFilter = '';
}

$since = match ($period) {
    'today' => date('Y-m-d 00:00:00'),
    '7d' => date('Y-m-d H:i:s', strtotime('-7 days')),
    '30d' => date('Y-m-d H:i:s', strtotime('-30 days')),
    default => null,
};

$pageViewRepository = new PageViewRepository();
$orderRepository = new OrderRepository();

$viewsBySource = $pageViewRepository->statsBySource($since, $specialId);
$ordersBySource = $orderRepository->statsBySource($since, $specialId);

$totalViews = $pageViewRepository->totalViews($since, $specialId);
$totalVisitors = $pageViewRepository->totalVisitors($since, $specialId);

$ordersByKey = [];
foreach ($ordersBySource as $row) {
    $ordersByKey[$row['traffic_source']] = $row;
}

$combined = [];
foreach ($viewsBySource as $row) {
    $combined[$row['source']] = [
        'source' => $row['source'],
        'views' => (int) $row['views'],
        'visitors' => (int) $row['visitors'],
        'orders' => 0,
        'paid_orders' => 0,
        'paid_revenue_cents' => 0,
    ];
}
foreach ($ordersByKey as $key => $row) {
    if (!isset($combined[$key])) {
        $combined[$key] = ['source' => $key, 'views' => 0, 'visitors' => 0, 'orders' => 0, 'paid_orders' => 0, 'paid_revenue_cents' => 0];
    }
    $combined[$key]['orders'] = (int) $row['orders'];
    $combined[$key]['paid_orders'] = (int) $row['paid_orders'];
    $combined[$key]['paid_revenue_cents'] = (int) $row['paid_revenue_cents'];
}

usort($combined, static fn (array $a, array $b) => $b['views'] <=> $a['views']);

$totalPaidOrders = array_sum(array_column($combined, 'paid_orders'));
$totalRevenueCents = array_sum(array_column($combined, 'paid_revenue_cents'));
$overallConversion = $totalVisitors > 0 ? ($totalPaidOrders / $totalVisitors) * 100 : 0;

function euro(int $cents): string
{
    return number_format($cents / 100, 2, ',', '.');
}

$pageTitle = 'Statistieken';
$activeSection = 'specials';
$activePage = 'stats';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1>Statistieken</h1>
</div>

<div class="card" style="padding: 18px 22px;">
    <form method="get" action="stats.php">
        <div class="row" style="align-items: end;">
            <div class="field" style="flex: 1 1 200px;">
                <label for="period">Periode</label>
                <select id="period" name="period">
                    <?php foreach ($periodLabels as $value => $label): ?>
                        <option value="<?= h($value) ?>" <?= $period === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="flex: 1 1 220px;">
                <label for="special_id">Special</label>
                <select id="special_id" name="special_id">
                    <option value="">Alle</option>
                    <?php foreach ($specials as $special): ?>
                        <option value="<?= (int) $special['id'] ?>" <?= $specialFilter === (string) $special['id'] ? 'selected' : '' ?>><?= h($special['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="flex: 0 0 auto;">
                <button type="submit" class="btn" style="width: auto; margin-top: 0;">Filteren</button>
            </div>
        </div>
    </form>
</div>

<div class="stat-grid" style="margin: 18px 0;">
    <div class="stat-tile">
        <div class="value"><?= number_format($totalViews, 0, ',', '.') ?></div>
        <div class="label">Paginaweergaves</div>
    </div>
    <div class="stat-tile">
        <div class="value"><?= number_format($totalVisitors, 0, ',', '.') ?></div>
        <div class="label">Unieke bezoekers</div>
    </div>
    <div class="stat-tile">
        <div class="value"><?= number_format($totalPaidOrders, 0, ',', '.') ?></div>
        <div class="label">Betaalde bestellingen</div>
    </div>
    <div class="stat-tile">
        <div class="value">€ <?= euro($totalRevenueCents) ?></div>
        <div class="label">Omzet (betaald)</div>
    </div>
    <div class="stat-tile">
        <div class="value"><?= number_format($overallConversion, 1, ',', '.') ?>%</div>
        <div class="label">Conversie (betaald / bezoekers)</div>
    </div>
</div>

<div class="card">
    <?php if (empty($combined)): ?>
        <p>Nog geen gegevens voor deze periode.</p>
    <?php else: ?>
        <div class="table-wrapper">
            <table>
                <thead>
                <tr>
                    <th>Bron</th>
                    <th>Bezoeken</th>
                    <th>Unieke bezoekers</th>
                    <th>Bestellingen</th>
                    <th>Betaald</th>
                    <th>Omzet</th>
                    <th>Conversie</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($combined as $row): ?>
                    <?php $conversion = $row['visitors'] > 0 ? ($row['paid_orders'] / $row['visitors']) * 100 : 0; ?>
                    <tr>
                        <td><?= h($row['source']) ?></td>
                        <td><?= number_format($row['views'], 0, ',', '.') ?></td>
                        <td><?= number_format($row['visitors'], 0, ',', '.') ?></td>
                        <td><?= number_format($row['orders'], 0, ',', '.') ?></td>
                        <td><?= number_format($row['paid_orders'], 0, ',', '.') ?></td>
                        <td>€ <?= euro($row['paid_revenue_cents']) ?></td>
                        <td><?= number_format($conversion, 1, ',', '.') ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<p class="hint" style="margin-top: 16px;">
    "Bron" wordt bepaald via het <code>utm_source</code>-linkparameter (bijv. <code>?utm_source=instagram</code>
    in je bio-link) of anders via de doorverwijzende website. Zonder herkenbare bron/link staat een bezoek
    onder "Direct". De bron van een bestelling wordt vastgelegd bij het eerste paginabezoek van die bezoeker.
</p>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
