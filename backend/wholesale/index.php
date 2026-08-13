<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\FaireService;
use App\OrderchampService;
use App\ProductPlatformListingRepository;
use App\ShopRepository;
use App\WholesaleOrderRepository;
use App\WholesalePlatformRepository;

Auth::requireSection('wholesale');

$platforms = WholesalePlatformRepository::findAll();

$configuredCheck = [
    'faire' => FaireService::isConfigured(),
    'orderchamp' => OrderchampService::isConfigured(),
];

$listedCounts = [];
foreach ($platforms as $platform) {
    $listedCounts[$platform['code']] = ProductPlatformListingRepository::countListed((int) $platform['id']);
}

$totalOrders = count(WholesaleOrderRepository::search());
$unmatchedSkus = WholesaleOrderRepository::countUnmatchedSkus();
$shopCount = ShopRepository::countAll();

$pageTitle = 'Wholesale - Overzicht';
$activeSection = 'wholesale';
$activePage = 'index';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1>Wholesale</h1>
</div>

<div class="row" style="margin-bottom: 20px;">
    <?php foreach ($platforms as $platform): ?>
        <div class="card" style="flex: 1 1 260px;">
            <h3 style="margin-top: 0;"><?= h($platform['icon'] ?? '') ?> <?= h($platform['name']) ?></h3>
            <p>
                Verbinding:
                <?php if ($configuredCheck[$platform['code']] ?? false): ?>
                    <span class="badge badge-on">Ingesteld</span>
                <?php else: ?>
                    <span class="badge badge-off">Niet ingesteld</span>
                <?php endif; ?>
            </p>
            <p>
                Synchronisatie naar dit platform:
                <?php if ((int) $platform['sync_enabled'] === 1): ?>
                    <span class="badge badge-on">Live</span>
                <?php else: ?>
                    <span class="badge badge-off">Uit (alleen testen/loggen)</span>
                <?php endif; ?>
            </p>
            <p><?= (int) ($listedCounts[$platform['code']] ?? 0) ?> producten geplaatst op dit platform.</p>
        </div>
    <?php endforeach; ?>
</div>

<div class="stat-grid">
    <div class="stat-tile">
        <div class="value"><?= $totalOrders ?></div>
        <div class="label">Wholesale-orders totaal</div>
    </div>
    <?php if ($unmatchedSkus > 0): ?>
        <a class="stat-tile stat-tile-link" href="unmatched-skus.php"
           title="Bekijk welke SKU's niet gekoppeld konden worden">
            <div class="value"><?= $unmatchedSkus ?></div>
            <div class="label">Niet-gematchte SKU's in orders &rsaquo;</div>
        </a>
    <?php else: ?>
        <div class="stat-tile">
            <div class="value"><?= $unmatchedSkus ?></div>
            <div class="label">Niet-gematchte SKU's in orders</div>
        </div>
    <?php endif; ?>
    <div class="stat-tile">
        <div class="value"><?= $shopCount ?></div>
        <div class="label">Shops</div>
    </div>
</div>

<div class="card">
    <p class="hint">
        Dit is de basisstructuur van de Wholesale-sectie. Historische orders,
        voorraadsynchronisatie en shoplocaties worden in latere bouwfases gevuld
        (zie docs/wholesale.md) - zolang een platform hierboven op "Uit" staat,
        wordt er nooit iets teruggeschreven naar Faire/Orderchamp.
    </p>
</div>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
