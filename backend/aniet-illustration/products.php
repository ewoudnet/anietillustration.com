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
    header('Location: cards.php');
    exit;
}

$activeSection = 'aniet-illustration';
$activeProductType = $typeId;
$activePage = 'products';
$csrfToken = Csrf::token();

$filters = ['q' => trim((string) ($_GET['q'] ?? ''))];
$hasFilters = $filters['q'] !== '';

const PRODUCTS_PER_PAGE = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$totalProducts = ProductRepository::countSearch($typeId, $filters);
$totalPages = max(1, (int) ceil($totalProducts / PRODUCTS_PER_PAGE));
$page = min($page, $totalPages);

$products = ProductRepository::search($typeId, $filters, PRODUCTS_PER_PAGE, ($page - 1) * PRODUCTS_PER_PAGE);

$pageTitle = $productType['name'];
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1>📦 <?= h($productType['name']) ?></h1>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Product is aangemaakt.</div>
<?php elseif (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Product is bijgewerkt.</div>
<?php elseif (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">Product is verwijderd.</div>
<?php endif; ?>

<div class="card" style="padding: 18px 22px; margin-bottom: 20px;">
    <form method="get" action="products.php">
        <input type="hidden" name="type_id" value="<?= (int) $typeId ?>">
        <div class="row" style="align-items: end;">
            <div class="field" style="flex: 2 1 220px;">
                <label for="q">Zoeken</label>
                <input type="text" id="q" name="q" placeholder="SKU of titel..." value="<?= h($filters['q']) ?>">
            </div>
            <div class="field" style="flex: 0 0 auto;">
                <button type="submit" class="btn" style="width: auto; margin-top: 0;">Filteren</button>
            </div>
            <?php if ($hasFilters): ?>
                <div class="field" style="flex: 0 0 auto;">
                    <a href="products.php?type_id=<?= (int) $typeId ?>" class="btn btn-secondary" style="width: auto; margin-top: 0;">Wis filters</a>
                </div>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <?php if ($products === []): ?>
        <p><?= $hasFilters ? 'Geen producten gevonden voor deze filters.' : 'Er zijn nog geen producten toegevoegd.' ?></p>
    <?php else: ?>
        <p class="result-count">
            <?= $hasFilters ? 'Gevonden' : 'Totaal' ?>: <strong><?= (int) $totalProducts ?></strong>
            <?= $totalProducts === 1 ? 'product' : 'producten' ?>
        </p>
        <div class="table-wrapper">
            <table class="orders">
                <thead>
                <tr>
                    <th style="width: 76px;"></th>
                    <th style="width: 110px;">SKU</th>
                    <th>Titel</th>
                    <th style="width: 76px;">Acties</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($products as $product): ?>
                    <tr>
                        <td>
                            <?php if (!empty($product['image_path'])): ?>
                                <img class="table-thumb table-thumb-card" src="<?= h(BO_ASSETS_URL) ?>/<?= h($product['image_path']) ?>" alt="">
                            <?php else: ?>
                                <div class="table-thumb table-thumb-card"></div>
                            <?php endif; ?>
                        </td>
                        <td class="reference"><?= h($product['sku']) ?></td>
                        <td><?= h($product['title']) ?></td>
                        <td>
                            <div class="actions-dropdown">
                                <button type="button" class="icon-btn actions-trigger" title="Acties" aria-label="Acties">⋮</button>
                                <div class="actions-menu">
                                    <a href="product-form.php?type_id=<?= (int) $typeId ?>&id=<?= (int) $product['id'] ?>">✏️ Bewerken</a>
                                    <form method="post" action="product-duplicate.php">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                                        <button type="submit">📋 Dupliceren</button>
                                    </form>
                                    <form method="post" action="product-delete.php"
                                          onsubmit="return confirm('Weet je zeker dat je product <?= h($product['sku']) ?> wilt verwijderen? Dit kan niet ongedaan worden gemaakt.');">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
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
        <?= renderPagination($page, $totalPages, ['q' => $filters['q'], 'type_id' => $typeId], 'products.php') ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
