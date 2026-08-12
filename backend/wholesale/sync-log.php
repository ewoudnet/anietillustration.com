<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\StockSyncLogRepository;
use App\WholesalePlatformRepository;

Auth::requireSection('wholesale');

$platforms = WholesalePlatformRepository::findAll();

$triggerLabels = [
    'manual_edit' => 'Handmatige wijziging',
    'faire_webhook' => 'Faire-webhook',
    'orderchamp_webhook' => 'Orderchamp-webhook',
    'order_placed' => 'Nieuwe order',
    'order_canceled' => 'Order geannuleerd',
    'reconciliation' => 'Periodieke controle',
    'initial_import' => 'Eerste import',
];

$filters = [
    'platform_id' => trim((string) ($_GET['platform_id'] ?? '')),
    'trigger_type' => trim((string) ($_GET['trigger_type'] ?? '')),
];
$hasFilters = (bool) array_filter($filters);

$logEntries = StockSyncLogRepository::search($filters);

$pageTitle = 'Wholesale - Synchronisatielog';
$activeSection = 'wholesale';
$activePage = 'sync-log';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1>Synchronisatielog</h1>
</div>

<div class="card" style="padding: 18px 22px;">
    <form method="get" action="sync-log.php">
        <div class="row" style="align-items: end;">
            <div class="field" style="flex: 1 1 200px;">
                <label for="platform_id">Platform</label>
                <select id="platform_id" name="platform_id">
                    <option value="">Alle (incl. handmatig)</option>
                    <?php foreach ($platforms as $platform): ?>
                        <option value="<?= (int) $platform['id'] ?>" <?= $filters['platform_id'] === (string) $platform['id'] ? 'selected' : '' ?>><?= h($platform['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="flex: 1 1 220px;">
                <label for="trigger_type">Aanleiding</label>
                <select id="trigger_type" name="trigger_type">
                    <option value="">Alle</option>
                    <?php foreach ($triggerLabels as $value => $label): ?>
                        <option value="<?= h($value) ?>" <?= $filters['trigger_type'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="flex: 0 0 auto;">
                <button type="submit" class="btn" style="width: auto; margin-top: 0;">Filteren</button>
            </div>
            <?php if ($hasFilters): ?>
                <div class="field" style="flex: 0 0 auto;">
                    <a href="sync-log.php" class="btn btn-secondary" style="width: auto; margin-top: 0; display: inline-block;">Wis filters</a>
                </div>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="card" style="margin-top: 20px;">
    <?php if (count($logEntries) === 0): ?>
        <p>Er is nog niets gesynchroniseerd. Zodra platformkoppelingen actief zijn
            (fase C+) of iemand handmatig de voorraad aanpast, verschijnen die
            wijzigingen hier - inclusief wanneer, welk product en waardoor.</p>
    <?php else: ?>
        <div class="table-wrapper">
            <table>
                <thead>
                <tr>
                    <th>Datum</th>
                    <th>Product</th>
                    <th>Platform</th>
                    <th>Richting</th>
                    <th>Aanleiding</th>
                    <th>Oud &rarr; nieuw</th>
                    <th>Resultaat</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($logEntries as $entry): ?>
                    <tr>
                        <td><?= h((new DateTimeImmutable($entry['created_at']))->format('d-m-Y H:i:s')) ?></td>
                        <td><?= $entry['sku'] !== null ? h($entry['sku'] . ' - ' . $entry['title']) : '—' ?></td>
                        <td><?= $entry['platform_name'] !== null ? h($entry['platform_name']) : '<span class="hint">Handmatig</span>' ?></td>
                        <td><?= $entry['direction'] === 'inbound' ? '⬇️ Inbound' : '⬆️ Outbound' ?></td>
                        <td><?= h($triggerLabels[$entry['trigger_type']] ?? $entry['trigger_type']) ?></td>
                        <td><?= $entry['old_stock'] !== null ? (int) $entry['old_stock'] : '—' ?> &rarr; <?= $entry['new_stock'] !== null ? (int) $entry['new_stock'] : '—' ?></td>
                        <td>
                            <?php if ((int) ($entry['dry_run'] ?? 0) === 1): ?>
                                <span class="badge badge-off" title="Synchronisatie stond op 'Uit' voor dit platform - er is niets echt verstuurd.">🧪 Proefdraai</span>
                            <?php elseif ((int) $entry['success'] === 1): ?>
                                <span class="badge badge-on">OK</span>
                            <?php else: ?>
                                <span class="badge badge-failed" title="<?= h($entry['error_message'] ?? '') ?>">Mislukt</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="hint">Toont de meest recente 100 regels.</p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
