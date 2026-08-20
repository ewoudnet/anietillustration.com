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
$q = trim((string) ($_GET['q'] ?? ''));
$totalCards = CardRepository::countAll($draftOnly, $q);
$totalPages = max(1, (int) ceil($totalCards / ORDER_CARDS_PER_PAGE));
$page = min($page, $totalPages);

$allCards = CardRepository::findAllForOrderPage(ORDER_CARDS_PER_PAGE, ($page - 1) * ORDER_CARDS_PER_PAGE, $sort, $dir, $draftOnly, $q);
$orderQueryParams = array_merge($draftOnly === true ? ['draft' => '1'] : [], $q !== '' ? ['q' => $q] : []);

$totalToOrder = array_sum(array_map(static fn (array $c): int => (int) $c['to_order'], $needsOrdering));
$designCount = count($needsOrdering);

$pageTitle = 'Bestelpagina - Kaarten';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1>📦 Bestelpagina - Kaarten</h1>
</div>

<?php if (isset($_GET['cleared'])): ?>
    <div class="alert alert-success">Bestellijst is geleegd.</div>
<?php endif; ?>

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
            <div style="display: flex; gap: 8px;">
                <a href="order-print.php" target="_blank" class="btn" style="width: auto; margin-top: 0;">🖨️ Print bestellijst</a>
                <form method="post" action="order-clear.php" onsubmit="return confirm('Weet je zeker dat je de hele bestellijst wilt legen? Alle aantallen gaan terug naar 0. Dit kan niet ongedaan worden gemaakt.');">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <button type="submit" class="btn btn-secondary" style="width: auto; margin-top: 0;">🗑️ Bestellijst legen</button>
                </form>
            </div>
        </div>
        <div class="table-wrapper">
            <table class="orders" id="needs-ordering-table">
                <thead>
                <tr>
                    <th style="width: 76px;"></th>
                    <th style="width: 110px;">SKU</th>
                    <th>Titel</th>
                    <th style="width: 110px;">Min. voorraad</th>
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
                        <td><a class="title-link" href="card-form.php?id=<?= (int) $cardRow['id'] ?>"><?= h($cardRow['title']) ?></a></td>
                        <td data-min-stock-display><?= (int) $cardRow['min_stock'] ?></td>
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
        <div class="row" style="align-items: end;">
            <div class="field" style="flex: 1 1 220px;">
                <label for="q">Zoeken op SKU</label>
                <input type="text" id="q" name="q" placeholder="SKU..." value="<?= h($q) ?>" autocomplete="off">
            </div>
            <div class="field" style="flex: 0 0 auto;">
                <button type="submit" class="btn" style="width: auto; margin-top: 0;">Zoeken</button>
            </div>
            <?php if ($q !== ''): ?>
                <div class="field" style="flex: 0 0 auto;">
                    <a href="order.php<?= $draftOnly === true ? '?draft=1' : '' ?>" class="btn btn-secondary" style="width: auto; margin-top: 0;">Wis zoekopdracht</a>
                </div>
            <?php endif; ?>
            <div class="field" style="flex: 0 0 auto;">
                <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.9rem; font-weight: 600; margin-top: 8px;">
                    <input type="checkbox" name="draft" value="1" <?= $draftOnly === true ? 'checked' : '' ?> onchange="this.form.submit()">
                    Alleen Wholesale Draft-kaarten tonen
                </label>
            </div>
        </div>
    </form>
    <?php if ($allCards === []): ?>
        <p><?= $draftOnly === true || $q !== '' ? 'Geen kaarten gevonden voor deze filters.' : 'Er zijn nog geen kaarten toegevoegd.' ?></p>
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
                            <a class="title-link" href="card-form.php?id=<?= (int) $cardRow['id'] ?>"><?= h($cardRow['title']) ?></a>
                            <?php if ((int) $cardRow['wholesale_draft'] === 1): ?>
                                <span class="badge badge-muted">Draft</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $cardRow['min_stock'] ?></td>
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
