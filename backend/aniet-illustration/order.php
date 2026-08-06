<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\CardRepository;
use App\Csrf;

Auth::requireSection('aniet-illustration');

$activeSection = 'aniet-illustration';
$activePage = 'order';
$activeProductType = 'cards';
$csrfToken = Csrf::token();

$needsOrdering = CardRepository::needsOrdering();

const ORDER_CARDS_PER_PAGE = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$sort = (string) ($_GET['sort'] ?? 'title');
$dir = ((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$draftOnly = ($_GET['draft'] ?? '') === '1' ? true : null;
$totalCards = CardRepository::countAll($draftOnly);
$totalPages = max(1, (int) ceil($totalCards / ORDER_CARDS_PER_PAGE));
$page = min($page, $totalPages);

$allCards = CardRepository::findAllForOrderPage(ORDER_CARDS_PER_PAGE, ($page - 1) * ORDER_CARDS_PER_PAGE, $sort, $dir, $draftOnly);
$orderQueryParams = $draftOnly === true ? ['draft' => '1'] : [];

$totalToOrder = array_sum(array_map(static fn (array $c): int => (int) $c['to_order'], $needsOrdering));
$designCount = count($needsOrdering);

$pageTitle = 'Bestelpagina - Kaarten';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1>📦 Bestelpagina - Kaarten</h1>
</div>

<div id="needs-ordering-content" style="<?= $needsOrdering === [] ? 'display:none;' : '' ?>">
    <div class="stat-grid">
        <div class="stat-tile">
            <div class="value" id="total-to-order"><?= (int) $totalToOrder ?></div>
            <div class="label">Totaal te bestellen</div>
        </div>
        <div class="stat-tile">
            <div class="value" id="design-count"><?= (int) $designCount ?></div>
            <div class="label">Verschillende ontwerpen</div>
        </div>
    </div>

    <div class="card" style="margin-bottom: 20px;">
        <div class="admin-topbar" style="margin-bottom: 12px;">
            <strong>Moet besteld worden</strong>
            <a href="order-print.php" target="_blank" class="btn" style="width: auto; margin-top: 0;">🖨️ Print bestellijst</a>
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
                <?php foreach ($needsOrdering as $cardRow): ?>
                    <tr data-card-row="<?= (int) $cardRow['id'] ?>">
                        <td>
                            <?php if (!empty($cardRow['image_path'])): ?>
                                <img class="table-thumb table-thumb-card" src="<?= h(BO_ASSETS_URL) ?>/<?= h($cardRow['image_path']) ?>" alt="">
                            <?php else: ?>
                                <div class="table-thumb table-thumb-card"></div>
                            <?php endif; ?>
                        </td>
                        <td class="reference"><?= h($cardRow['sku']) ?></td>
                        <td><?= h($cardRow['title']) ?></td>
                        <td data-min-stock-display><?= (int) $cardRow['min_stock'] ?></td>
                        <td data-current-stock-display><?= $cardRow['current_stock'] !== null ? (int) $cardRow['current_stock'] : '—' ?></td>
                        <td>
                            <input type="number" min="0" class="order-input" data-card-id="<?= (int) $cardRow['id'] ?>"
                                   value="<?= (int) $cardRow['to_order'] ?>">
                            <span class="save-indicator" data-indicator-for="<?= (int) $cardRow['id'] ?>"></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <form method="get" action="order.php" style="margin-bottom: 14px;">
        <input type="hidden" name="sort" value="<?= h($sort) ?>">
        <input type="hidden" name="dir" value="<?= h($dir) ?>">
        <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.9rem; font-weight: 600;">
            <input type="checkbox" name="draft" value="1" <?= $draftOnly === true ? 'checked' : '' ?> onchange="this.form.submit()">
            Alleen Wholesale Draft-kaarten tonen
        </label>
    </form>
    <?php if ($allCards === []): ?>
        <p><?= $draftOnly === true ? 'Geen Wholesale Draft-kaarten gevonden.' : 'Er zijn nog geen kaarten toegevoegd.' ?></p>
    <?php else: ?>
        <p class="result-count">Totaal: <strong><?= (int) $totalCards ?></strong> <?= $totalCards === 1 ? 'kaart' : 'kaarten' ?></p>
        <div class="table-wrapper">
            <table class="orders">
                <thead>
                <tr>
                    <th style="width: 76px;"></th>
                    <th style="width: 110px;">SKU</th>
                    <th>Titel</th>
                    <th style="width: 120px;"><?= sortHeader('min_stock', 'Min. voorraad', $sort, $dir, $orderQueryParams, 'order.php') ?></th>
                    <th style="width: 120px;"><?= sortHeader('current_stock', 'Huidige voorraad', $sort, $dir, $orderQueryParams, 'order.php') ?></th>
                    <th style="width: 130px;"><?= sortHeader('to_order', 'Te bestellen', $sort, $dir, $orderQueryParams, 'order.php') ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($allCards as $cardRow): ?>
                    <tr>
                        <td>
                            <?php if (!empty($cardRow['image_path'])): ?>
                                <img class="table-thumb table-thumb-card" src="<?= h(BO_ASSETS_URL) ?>/<?= h($cardRow['image_path']) ?>" alt="">
                            <?php else: ?>
                                <div class="table-thumb table-thumb-card"></div>
                            <?php endif; ?>
                        </td>
                        <td class="reference"><?= h($cardRow['sku']) ?></td>
                        <td>
                            <?= h($cardRow['title']) ?>
                            <?php if ((int) $cardRow['wholesale_draft'] === 1): ?>
                                <span class="badge badge-muted">Draft</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $cardRow['min_stock'] ?></td>
                        <td><?= $cardRow['current_stock'] !== null ? (int) $cardRow['current_stock'] : '—' ?></td>
                        <td>
                            <input type="number" min="0" class="order-input" data-card-id="<?= (int) $cardRow['id'] ?>"
                                   value="<?= (int) $cardRow['to_order'] ?>">
                            <span class="save-indicator" data-indicator-for="<?= (int) $cardRow['id'] ?>"></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= renderPagination($page, $totalPages, array_merge(['sort' => $sort, 'dir' => $dir], $orderQueryParams), 'order.php') ?>
    <?php endif; ?>
</div>
<script>
    window.ORDER_SAVE_URL = 'order-save.php';
    window.ORDER_CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
    window.BO_ASSETS_URL = <?= json_encode(BO_ASSETS_URL) ?>;
</script>
<script src="<?= h(BACKEND_BASE) ?>/assets/js/order-autosave.js"></script>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
