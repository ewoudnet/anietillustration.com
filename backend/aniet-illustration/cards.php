<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\CardRepository;
use App\Csrf;
use App\SalesChannelRepository;

Auth::requireSection('aniet-illustration');

$activeSection = 'aniet-illustration';
$activePage = 'cards';
$activeProductType = 'cards';
$csrfToken = Csrf::token();

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'sales_channel_id' => (int) ($_GET['sales_channel_id'] ?? 0),
    'greetz_status' => trim((string) ($_GET['greetz_status'] ?? '')),
    'wholesale_status' => trim((string) ($_GET['wholesale_status'] ?? '')),
];
$hasFilters = $filters['q'] !== '' || $filters['sales_channel_id'] > 0
    || $filters['greetz_status'] !== '' || $filters['wholesale_status'] !== '';

$greetzStatusLabels = [
    'actief' => 'Actief',
    'ingediend' => 'Ingediend',
    'afgewezen' => 'Afgewezen',
    'nog_in_te_sturen' => 'Nog in te sturen',
];

$wholesaleStatusLabels = [
    'draft' => 'Draft',
    'actief' => 'Actief',
];

const CARDS_PER_PAGE = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$totalCards = CardRepository::countSearch($filters);
$totalPages = max(1, (int) ceil($totalCards / CARDS_PER_PAGE));
$page = min($page, $totalPages);

$cards = CardRepository::search($filters, CARDS_PER_PAGE, ($page - 1) * CARDS_PER_PAGE);
$channels = SalesChannelRepository::findAll();

$greetzChannelId = null;
$wholesaleChannelId = null;
foreach ($channels as $channel) {
    if ($channel['name'] === 'Greetz') {
        $greetzChannelId = (int) $channel['id'];
    }
    if ($channel['name'] === 'Wholesale') {
        $wholesaleChannelId = (int) $channel['id'];
    }
}
$showGreetzStatus = $greetzChannelId !== null && $filters['sales_channel_id'] === $greetzChannelId;
$showWholesaleStatus = $wholesaleChannelId !== null && $filters['sales_channel_id'] === $wholesaleChannelId;

$pageTitle = 'Kaartoverzicht';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1>🎴 Kaartoverzicht</h1>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Kaart is aangemaakt.</div>
<?php elseif (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Kaart is bijgewerkt.</div>
<?php elseif (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">Kaart is verwijderd.</div>
<?php endif; ?>
<?php if (isset($_GET['synced'])): ?>
    <div class="alert alert-success">Voorraad gesynchroniseerd naar Faire/Orderchamp - bekijk het resultaat in de <a href="../wholesale/sync-log.php">synchronisatielog</a>.</div>
<?php endif; ?>

<div class="card" style="padding: 18px 22px; margin-bottom: 20px;">
    <form method="get" action="cards.php">
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
            <div class="field conditional-field <?= $showGreetzStatus ? 'visible' : '' ?>" id="greetz-status-field" style="flex: 1 1 160px;">
                <label for="greetz_status">Greetz-status</label>
                <select id="greetz_status" name="greetz_status">
                    <option value="">Alle</option>
                    <?php foreach ($greetzStatusLabels as $value => $label): ?>
                        <option value="<?= h($value) ?>" <?= $filters['greetz_status'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field conditional-field <?= $showWholesaleStatus ? 'visible' : '' ?>" id="wholesale-status-field" style="flex: 1 1 160px;">
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
                    <a href="cards.php" class="btn btn-secondary" style="width: auto; margin-top: 0;">Wis filters</a>
                </div>
            <?php endif; ?>
        </div>
    </form>
    <script>
    (function () {
        var channelSelect = document.getElementById('sales_channel_id');
        var statusField = document.getElementById('greetz-status-field');
        var statusSelect = document.getElementById('greetz_status');
        var greetzId = <?= $greetzChannelId !== null ? (int) $greetzChannelId : 'null' ?>;

        var wholesaleStatusField = document.getElementById('wholesale-status-field');
        var wholesaleStatusSelect = document.getElementById('wholesale_status');
        var wholesaleId = <?= $wholesaleChannelId !== null ? (int) $wholesaleChannelId : 'null' ?>;

        function update() {
            var selected = parseInt(channelSelect.value, 10);

            var isGreetz = greetzId !== null && selected === greetzId;
            statusField.classList.toggle('visible', isGreetz);
            if (!isGreetz) {
                statusSelect.value = '';
            }

            var isWholesale = wholesaleId !== null && selected === wholesaleId;
            wholesaleStatusField.classList.toggle('visible', isWholesale);
            if (!isWholesale) {
                wholesaleStatusSelect.value = '';
            }
        }

        channelSelect.addEventListener('change', update);
        update();
    })();
    </script>
</div>

<div class="card">
    <?php if ($cards === []): ?>
        <p><?= $hasFilters ? 'Geen kaarten gevonden voor deze filters.' : 'Er zijn nog geen kaarten toegevoegd.' ?></p>
    <?php else: ?>
        <p class="result-count">
            <?= $hasFilters ? 'Gevonden' : 'Totaal' ?>: <strong><?= (int) $totalCards ?></strong>
            <?= $totalCards === 1 ? 'kaart' : 'kaarten' ?>
        </p>
        <div class="table-wrapper">
            <table class="orders">
                <thead>
                <tr>
                    <th style="width: 76px;"></th>
                    <th style="width: 110px;">SKU</th>
                    <th>Titel</th>
                    <th style="width: 140px;">Verkoopkanalen</th>
                    <?php if ($showGreetzStatus): ?>
                        <th style="width: 200px;">Greetz-status</th>
                    <?php endif; ?>
                    <th style="width: 76px;">Acties</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($cards as $cardRow): ?>
                    <tr>
                        <td>
                            <?php if (!empty($cardRow['image_path'])): ?>
                                <img class="table-thumb table-thumb-card" src="<?= h(BO_ASSETS_URL) ?>/<?= h($cardRow['image_path']) ?>" alt="">
                            <?php else: ?>
                                <div class="table-thumb table-thumb-card"></div>
                            <?php endif; ?>
                        </td>
                        <td class="reference"><?= h($cardRow['sku']) ?></td>
                        <td><?= h($cardRow['title']) ?></td>
                        <td>
                            <?php foreach ($cardRow['sales_channels'] as $channel): ?>
                                <span class="badge badge-channel" style="background-color: <?= h($channel['color']) ?>;"><?= h($channel['abbreviation']) ?></span>
                            <?php endforeach; ?>
                        </td>
                        <?php if ($showGreetzStatus): ?>
                            <td>
                                <span class="badge <?= greetzStatusBadgeClass($cardRow) ?>"><?= h(greetzStatusLabel($cardRow)) ?></span>
                                <?php if (!empty($cardRow['submission_date'])): ?>
                                    <div class="hint">Submitted: <?= h(nlDate($cardRow['submission_date'])) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($cardRow['rejected_date'])): ?>
                                    <div class="hint">Rejected: <?= h(nlDate($cardRow['rejected_date'])) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($cardRow['psd_filename'])): ?>
                                    <div class="hint">PSD: <?= h($cardRow['psd_filename']) ?></div>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td>
                            <div class="actions-dropdown">
                                <button type="button" class="icon-btn actions-trigger" title="Acties" aria-label="Acties">⋮</button>
                                <div class="actions-menu">
                                    <a href="card-form.php?id=<?= (int) $cardRow['id'] ?>">✏️ Bewerken</a>
                                    <form method="post" action="card-duplicate.php">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $cardRow['id'] ?>">
                                        <button type="submit">📋 Dupliceren</button>
                                    </form>
                                    <form method="post" action="card-delete.php"
                                          onsubmit="return confirm('Weet je zeker dat je kaart <?= h($cardRow['sku']) ?> wilt verwijderen? Dit kan niet ongedaan worden gemaakt.');">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $cardRow['id'] ?>">
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
        <?= renderPagination($page, $totalPages, [
            'q' => $filters['q'],
            'sales_channel_id' => $filters['sales_channel_id'] ?: '',
            'greetz_status' => $filters['greetz_status'],
            'wholesale_status' => $filters['wholesale_status'],
        ], 'cards.php') ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
