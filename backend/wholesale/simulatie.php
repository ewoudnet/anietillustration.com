<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\WholesaleStockSimulationService;

Auth::requireSection('wholesale');

$onlyIssues = isset($_GET['only_issues']);
$rows = WholesaleStockSimulationService::run();

$pageTitle = 'Wholesale - Voorraadsimulatie';
$activeSection = 'wholesale';
$activePage = 'simulatie';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1>Voorraadsimulatie</h1>
</div>

<div class="card" style="margin-bottom: 20px;">
    <p class="hint">
        Observatieperiode na het voorraadcorruptie-incident van 18 augustus 2026
        (zie <code>docs/wholesale.md</code>): Faire-voorraad lezen, orders laten
        binnenkomen en de lokale voorraad herberekenen gebeurt gewoon door - maar
        er wordt <strong>niets teruggeschreven</strong> naar Faire of Orderchamp,
        ongeacht wat hieronder staat. Deze pagina laat alleen zien wat de sync
        ZOU doen ("simulated on-hand") naast wat elk platform er zelf al over
        zegt, zodat er vertrouwen kan opbouwen vóórdat synchronisatie (Wholesale
        &gt; Instellingen) weer wordt aangezet.
    </p>
    <p class="hint">
        Bij Faire is alleen het "beschikbaar"-aantal betrouwbaar uit te lezen
        (geen bevestigde on-hand-leesroute) - vergelijk "Simulated on-hand" bij
        Faire dus handmatig met "Huidige voorraad" in Faire's eigen dashboard.
        Bij Orderchamp zijn zowel on-hand als beschikbaar rechtstreeks uit te
        lezen.
    </p>
    <a href="?<?= $onlyIssues ? '' : 'only_issues=1' ?>" class="btn btn-secondary" style="width: auto; display: inline-block;">
        <?= $onlyIssues ? 'Toon alles' : 'Toon alleen items met een afwijking' ?>
    </a>
</div>

<div class="card">
    <?php if (count($rows) === 0): ?>
        <p>Er zijn nog geen producten of kaarten aangemaakt.</p>
    <?php else: ?>
        <div class="table-wrapper">
            <table>
                <thead>
                <tr>
                    <th></th>
                    <th>SKU</th>
                    <th>Titel</th>
                    <th>Lokaal</th>
                    <th>Faire beschikbaar (echt)</th>
                    <th>Faire toegewezen (onze telling)</th>
                    <th>Faire simulated on-hand</th>
                    <th>Orderchamp on-hand (echt)</th>
                    <th>Orderchamp beschikbaar (echt)</th>
                    <th>Orderchamp toegewezen (onze telling)</th>
                    <th>Orderchamp simulated on-hand</th>
                </tr>
                </thead>
                <tbody>
                <?php
                $shown = 0;
                foreach ($rows as $row):
                    $hasIssue = $row['faire']['matches'] === false || $row['orderchamp']['matches'] === false;
                    if ($onlyIssues && !$hasIssue) {
                        continue;
                    }
                    $shown++;
                    ?>
                    <tr>
                        <td>
                            <?php if (!empty($row['image_path'])): ?>
                                <img class="table-thumb table-thumb-card" src="<?= h(BO_ASSETS_URL) ?>/<?= h($row['image_path']) ?>" alt="">
                            <?php endif; ?>
                        </td>
                        <td><?= h($row['sku']) ?></td>
                        <td><?= h($row['title']) ?></td>
                        <td><?= (int) $row['current_stock'] ?></td>
                        <td>
                            <?php if ($row['faire']['live_available'] === null): ?>
                                <span class="hint">—</span>
                            <?php else: ?>
                                <?= (int) $row['faire']['live_available'] ?>
                                <?php if ($row['faire']['matches'] === false): ?>
                                    <span class="badge badge-failed" title="Wijkt af van lokale voorraad">≠</span>
                                <?php else: ?>
                                    <span class="badge badge-on">OK</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $row['faire']['committed'] ?></td>
                        <td><?= (int) $row['faire']['simulated_on_hand'] ?></td>
                        <td><?= $row['orderchamp']['live_on_hand'] === null ? '—' : (int) $row['orderchamp']['live_on_hand'] ?></td>
                        <td>
                            <?php if ($row['orderchamp']['live_available'] === null): ?>
                                <span class="hint">—</span>
                            <?php else: ?>
                                <?= (int) $row['orderchamp']['live_available'] ?>
                                <?php if ($row['orderchamp']['matches'] === false): ?>
                                    <span class="badge badge-failed" title="Wijkt af van lokale voorraad">≠</span>
                                <?php else: ?>
                                    <span class="badge badge-on">OK</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $row['orderchamp']['committed'] ?></td>
                        <td><?= (int) $row['orderchamp']['simulated_on_hand'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($shown === 0): ?>
                    <tr><td colspan="11">Geen items met een afwijking gevonden.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
