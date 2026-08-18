<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\ProductRepository;
use App\ProductTypeRepository;

Auth::requireSection('aniet-illustration');

$typeId = (int) ($_GET['type_id'] ?? 0);
$productType = $typeId > 0 ? ProductTypeRepository::find($typeId) : null;

if ($productType === null) {
    header('Location: cards.php');
    exit;
}
if ($productType['name'] === 'Kaarten') {
    header('Location: order.php');
    exit;
}

$activeSection = 'aniet-illustration';
$activeProductType = $typeId;
$activePage = 'product-order';
$csrfToken = Csrf::token();

$needsOrdering = ProductRepository::needsOrdering($typeId);

const GENERIC_PRODUCTS_PER_PAGE = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$sort = (string) ($_GET['sort'] ?? 'title');
$dir = ((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$draftOnly = ($_GET['draft'] ?? '') === '1' ? true : null;
$totalProducts = ProductRepository::countAll($typeId, $draftOnly);
$totalPages = max(1, (int) ceil($totalProducts / GENERIC_PRODUCTS_PER_PAGE));
$page = min($page, $totalPages);

$allProducts = ProductRepository::findAllForOrderPage($typeId, GENERIC_PRODUCTS_PER_PAGE, ($page - 1) * GENERIC_PRODUCTS_PER_PAGE, $sort, $dir, $draftOnly);
$orderQueryParams = array_merge(['type_id' => $typeId], $draftOnly === true ? ['draft' => '1'] : []);

$totalToOrder = array_sum(array_map(static fn (array $p): int => (int) $p['to_order'], $needsOrdering));
$designCount = count($needsOrdering);

$pageTitle = 'Bestelpagina ' . $productType['name'];
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1>📦 Bestelpagina - <?= h($productType['name']) ?></h1>
</div>

<div id="needs-ordering-content" style="<?= $needsOrdering === [] ? 'display:none;' : '' ?>">
    <div class="stat-grid">
        <div class="stat-tile">
            <div class="value" id="total-to-order"><?= (int) $totalToOrder ?></div>
            <div class="label">Totaal te bestellen</div>
        </div>
        <div class="stat-tile">
            <div class="value" id="design-count"><?= (int) $designCount ?></div>
            <div class="label">Verschillende producten</div>
        </div>
    </div>

    <div class="card" style="margin-bottom: 20px;">
        <div class="admin-topbar" style="margin-bottom: 12px;">
            <strong>Moet besteld worden</strong>
            <a href="product-order-print.php?type_id=<?= (int) $typeId ?>" target="_blank" class="btn" style="width: auto; margin-top: 0;">🖨️ Print bestellijst</a>
        </div>
        <div class="table-wrapper">
            <table class="orders" id="needs-ordering-table">
                <thead>
                <tr>
                    <th style="width: 76px;"></th>
                    <th style="width: 110px;">SKU</th>
                    <th>Titel</th>
                    <th style="width: 110px;">Min. voorraad</th>
                    <th style="width: 110px;">Huidige voorraad</th>
                    <th style="width: 130px;">Te bestellen</th>
                </tr>
                </thead>
                <tbody id="needs-ordering-body">
                <?php foreach ($needsOrdering as $product): ?>
                    <tr data-card-row="<?= (int) $product['id'] ?>">
                        <td>
                            <?php if (!empty($product['image_path'])): ?>
                                <img class="table-thumb table-thumb-card" src="<?= h(BO_ASSETS_URL) ?>/<?= h($product['image_path']) ?>" alt="">
                            <?php else: ?>
                                <div class="table-thumb table-thumb-card"></div>
                            <?php endif; ?>
                        </td>
                        <td class="reference"><?= h($product['sku']) ?></td>
                        <td><a class="title-link" href="product-form.php?type_id=<?= (int) $typeId ?>&id=<?= (int) $product['id'] ?>"><?= h($product['title']) ?></a></td>
                        <td data-min-stock-display><?= (int) $product['min_stock'] ?></td>
                        <td data-current-stock-display><?= $product['current_stock'] !== null ? (int) $product['current_stock'] : '—' ?></td>
                        <td>
                            <input type="number" min="0" class="order-input" data-card-id="<?= (int) $product['id'] ?>"
                                   value="<?= (int) $product['to_order'] ?>">
                            <span class="save-indicator" data-indicator-for="<?= (int) $product['id'] ?>"></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <form method="get" action="product-order.php" style="margin-bottom: 14px;">
        <input type="hidden" name="type_id" value="<?= (int) $typeId ?>">
        <input type="hidden" name="sort" value="<?= h($sort) ?>">
        <input type="hidden" name="dir" value="<?= h($dir) ?>">
        <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.9rem; font-weight: 600;">
            <input type="checkbox" name="draft" value="1" <?= $draftOnly === true ? 'checked' : '' ?> onchange="this.form.submit()">
            Alleen Wholesale Draft-producten tonen
        </label>
    </form>
    <?php if ($allProducts === []): ?>
        <p><?= $draftOnly === true ? 'Geen Wholesale Draft-producten gevonden.' : 'Er zijn nog geen producten toegevoegd.' ?></p>
    <?php else: ?>
        <p class="result-count">Totaal: <strong><?= (int) $totalProducts ?></strong> <?= $totalProducts === 1 ? 'product' : 'producten' ?></p>
        <div class="table-wrapper">
            <table class="orders">
                <thead>
                <tr>
                    <th style="width: 76px;"></th>
                    <th style="width: 110px;">SKU</th>
                    <th>Titel</th>
                    <th style="width: 120px;"><?= sortHeader('min_stock', 'Min. voorraad', $sort, $dir, $orderQueryParams, 'product-order.php') ?></th>
                    <th style="width: 120px;"><?= sortHeader('current_stock', 'Huidige voorraad', $sort, $dir, $orderQueryParams, 'product-order.php') ?></th>
                    <th style="width: 130px;"><?= sortHeader('to_order', 'Te bestellen', $sort, $dir, $orderQueryParams, 'product-order.php') ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($allProducts as $product): ?>
                    <tr>
                        <td>
                            <?php if (!empty($product['image_path'])): ?>
                                <img class="table-thumb table-thumb-card" src="<?= h(BO_ASSETS_URL) ?>/<?= h($product['image_path']) ?>" alt="">
                            <?php else: ?>
                                <div class="table-thumb table-thumb-card"></div>
                            <?php endif; ?>
                        </td>
                        <td class="reference"><?= h($product['sku']) ?></td>
                        <td>
                            <a class="title-link" href="product-form.php?type_id=<?= (int) $typeId ?>&id=<?= (int) $product['id'] ?>"><?= h($product['title']) ?></a>
                            <?php if ((int) $product['wholesale_draft'] === 1): ?>
                                <span class="badge badge-muted">Draft</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $product['min_stock'] ?></td>
                        <td><?= $product['current_stock'] !== null ? (int) $product['current_stock'] : '—' ?></td>
                        <td>
                            <input type="number" min="0" class="order-input" data-card-id="<?= (int) $product['id'] ?>"
                                   value="<?= (int) $product['to_order'] ?>">
                            <span class="save-indicator" data-indicator-for="<?= (int) $product['id'] ?>"></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= renderPagination($page, $totalPages, array_merge(['type_id' => $typeId, 'sort' => $sort, 'dir' => $dir], $draftOnly === true ? ['draft' => '1'] : []), 'product-order.php') ?>
    <?php endif; ?>
</div>
<script>
    window.ORDER_SAVE_URL = 'product-order-save.php?type_id=<?= (int) $typeId ?>';
    window.ORDER_CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
    window.BO_ASSETS_URL = <?= json_encode(BO_ASSETS_URL) ?>;
</script>
<script src="<?= h(BACKEND_BASE) ?>/assets/js/order-autosave.js"></script>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
