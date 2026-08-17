<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\CardRepository;
use App\SalesChannelRepository;

Auth::requireSection('aniet-illustration');

$activeSection = 'aniet-illustration';
$activePage = 'labels';
$activeProductType = 'cards';

$draftNeedsOrdering = array_values(array_filter(
    CardRepository::needsOrdering(),
    static fn (array $c): bool => (int) $c['wholesale_draft'] === 1
));
$draftNeedsOrderingIds = array_map(static fn (array $c): int => (int) $c['id'], $draftNeedsOrdering);

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'sales_channel_id' => (int) ($_GET['sales_channel_id'] ?? 0),
    'wholesale_status' => trim((string) ($_GET['wholesale_status'] ?? '')),
];
$hasFilters = $filters['q'] !== '' || $filters['sales_channel_id'] > 0 || $filters['wholesale_status'] !== '';

$wholesaleStatusLabels = [
    'draft' => 'Draft',
    'actief' => 'Actief',
];

const LABEL_CARDS_PER_PAGE = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$totalCards = CardRepository::countSearch($filters);
$totalPages = max(1, (int) ceil($totalCards / LABEL_CARDS_PER_PAGE));
$page = min($page, $totalPages);

$cards = CardRepository::search($filters, LABEL_CARDS_PER_PAGE, ($page - 1) * LABEL_CARDS_PER_PAGE);
$channels = SalesChannelRepository::findAll();

$pageTitle = 'Labels - Kaarten';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1>🏷️ Labels maken</h1>
</div>

<div class="card" style="margin-bottom: 20px;">
    <p>Nieuwe (draft) kaarten die nu op de bestelpagina staan als besteld:</p>
    <?php if ($draftNeedsOrdering === []): ?>
        <p class="hint">Er staan momenteel geen nieuwe (draft) kaarten op de bestelpagina.</p>
    <?php else: ?>
        <a href="label-print.php?<?= h(http_build_query(['ids' => $draftNeedsOrderingIds])) ?>" target="_blank" class="btn" style="width: auto;">
            🏷️ Print labels van bestelpagina-items (<?= count($draftNeedsOrdering) ?>)
        </a>
    <?php endif; ?>
</div>

<div class="card" style="padding: 18px 22px; margin-bottom: 20px;">
    <p style="margin-top: 0;"><strong>Of selecteer handmatig kaarten</strong> (bijv. om een bestaand label te vervangen):</p>
    <form method="get" action="labels.php">
        <div class="row" style="align-items: end;">
            <div class="field" style="flex: 2 1 220px;">
                <label for="q">Zoeken</label>
                <input type="text" id="q" name="q" placeholder="SKU of titel..." value="<?= h($filters['q']) ?>">
            </div>
            <div class="field" style="flex: 1 1 200px;">
                <label for="sales_channel_id">Verkoopkanaal</label>
                <select id="sales_channel_id" name="sales_channel_id">
                    <option value="">Alle</option>
                    <?php foreach ($channels as $channel): ?>
                        <option value="<?= (int) $channel['id'] ?>" <?= $filters['sales_channel_id'] === (int) $channel['id'] ? 'selected' : '' ?>><?= h($channel['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="flex: 1 1 160px;">
                <label for="wholesale_status">Wholesale-status</label>
                <select id="wholesale_status" name="wholesale_status">
                    <option value="">Alle</option>
                    <?php foreach ($wholesaleStatusLabels as $value => $label): ?>
                        <option value="<?= h($value) ?>" <?= $filters['wholesale_status'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="flex: 0 0 auto;">
                <button type="submit" class="btn" style="width: auto; margin-top: 0;">Filteren</button>
            </div>
            <?php if ($hasFilters): ?>
                <div class="field" style="flex: 0 0 auto;">
                    <a href="labels.php" class="btn btn-secondary" style="width: auto; margin-top: 0;">Wis filters</a>
                </div>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <?php if ($cards === []): ?>
        <p><?= $hasFilters ? 'Geen kaarten gevonden voor deze filters.' : 'Er zijn nog geen kaarten toegevoegd.' ?></p>
    <?php else: ?>
        <p class="result-count">
            <?= $hasFilters ? 'Gevonden' : 'Totaal' ?>: <strong><?= (int) $totalCards ?></strong>
            <?= $totalCards === 1 ? 'kaart' : 'kaarten' ?>
        </p>
        <div class="admin-topbar" style="margin-bottom: 12px;">
            <span id="label-select-count" class="hint">Geen kaarten geselecteerd</span>
            <button type="button" id="label-select-submit" class="btn" style="width: auto; margin-top: 0;" disabled>🏷️ Print labels van selectie</button>
        </div>
        <div class="table-wrapper">
            <table class="orders">
                <thead>
                <tr>
                    <th style="width: 32px;"><input type="checkbox" id="label-select-all" title="Alles op deze pagina selecteren"></th>
                    <th style="width: 76px;"></th>
                    <th style="width: 110px;">SKU</th>
                    <th>Titel</th>
                    <th style="width: 140px;">Verkoopkanalen</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($cards as $cardRow): ?>
                    <tr>
                        <td><input type="checkbox" value="<?= (int) $cardRow['id'] ?>" class="label-select-checkbox"></td>
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
                        <td>
                            <?php foreach ($cardRow['sales_channels'] as $channel): ?>
                                <span class="badge badge-channel" style="background-color: <?= h($channel['color']) ?>;"><?= h($channel['abbreviation']) ?></span>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= renderPagination($page, $totalPages, [
            'q' => $filters['q'],
            'sales_channel_id' => $filters['sales_channel_id'] ?: '',
            'wholesale_status' => $filters['wholesale_status'],
        ], 'labels.php') ?>
    <?php endif; ?>
</div>
<script>
(function () {
    var selectAll = document.getElementById('label-select-all');
    var checkboxes = document.querySelectorAll('.label-select-checkbox');
    var countLabel = document.getElementById('label-select-count');
    var submitBtn = document.getElementById('label-select-submit');
    if (!submitBtn) {
        return;
    }

    function update() {
        var checked = Array.prototype.filter.call(checkboxes, function (cb) { return cb.checked; });
        countLabel.textContent = checked.length === 0
            ? 'Geen kaarten geselecteerd'
            : checked.length + ' kaart' + (checked.length === 1 ? '' : 'en') + ' geselecteerd';
        submitBtn.disabled = checked.length === 0;
        if (selectAll) {
            selectAll.checked = checked.length > 0 && checked.length === checkboxes.length;
        }
    }

    checkboxes.forEach(function (cb) { cb.addEventListener('change', update); });

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            checkboxes.forEach(function (cb) { cb.checked = selectAll.checked; });
            update();
        });
    }

    submitBtn.addEventListener('click', function () {
        var ids = Array.prototype.filter.call(checkboxes, function (cb) { return cb.checked; })
            .map(function (cb) { return 'ids[]=' + encodeURIComponent(cb.value); });
        if (ids.length === 0) {
            return;
        }
        window.open('label-print.php?' + ids.join('&'), '_blank');
    });

    update();
})();
</script>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
