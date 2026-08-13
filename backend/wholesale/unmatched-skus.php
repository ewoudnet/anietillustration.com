<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\WholesaleOrderRepository;

Auth::requireSection('wholesale');

$rows = WholesaleOrderRepository::unmatchedSkuSummary();

$pageTitle = 'Wholesale - Niet-gematchte SKU\'s';
$activeSection = 'wholesale';
$activePage = 'index';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1>Niet-gematchte SKU's</h1>
    <a href="index.php" class="btn btn-secondary" style="width: auto;">&laquo; Terug naar overzicht</a>
</div>

<div class="card" style="margin-bottom: 20px;">
    <p class="hint">
        Deze SKU's komen voor op wholesale-orders, maar horen bij geen enkel product
        of kaart in dit systeem. De orders zelf zijn gewoon bewaard - alleen de
        koppeling ontbreekt.
    </p>
    <p class="hint">
        <strong>Waarom dit uitmaakt:</strong> bij een nieuwe order wordt voor zo'n
        regel geen voorraad afgeschreven. Maak je het product of de kaart later
        alsnog aan, dan herstelt de koppeling zich bij een volgende import, maar de
        gemiste voorraadaftrek wordt <em>niet</em> met terugwerkende kracht ingehaald -
        die moet je dan handmatig corrigeren. Bij historische orders speelt dit niet,
        want daar wordt sowieso nooit voorraad afgeschreven.
    </p>
</div>

<div class="card">
    <?php if (count($rows) === 0): ?>
        <p>Alle SKU's op de ingeladen orders zijn gekoppeld aan een product of kaart.
            Er is niets op te lossen.</p>
    <?php else: ?>
        <div class="table-wrapper">
            <table>
                <thead>
                <tr>
                    <th>SKU</th>
                    <th>Titel op de order</th>
                    <th>Platform</th>
                    <th>Orderregels</th>
                    <th>Totaal besteld</th>
                    <th>Laatst besteld</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $canceled = (int) $row['canceled_quantity']; ?>
                    <tr>
                        <td><strong><?= h($row['sku']) ?></strong></td>
                        <td><?= h($row['title_snapshot']) ?></td>
                        <td><?= h($row['platforms']) ?></td>
                        <td><?= (int) $row['line_count'] ?></td>
                        <td>
                            <?= (int) $row['total_quantity'] ?>
                            <?php if ($canceled > 0): ?>
                                <span class="hint">(waarvan <?= $canceled ?> geannuleerd)</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h((new DateTimeImmutable($row['last_ordered_at']))->format('d-m-Y')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="hint" style="margin-top: 16px;">
            Meest bestelde bovenaan. Los je er een op, maak dan het product aan onder
            <a href="<?= h(BACKEND_BASE) ?>/aniet-illustration/products.php">Producten</a>
            of de kaart onder
            <a href="<?= h(BACKEND_BASE) ?>/aniet-illustration/cards.php">Kaarten</a>
            met exact deze SKU, en draai daarna de
            <a href="import.php">historische import</a> opnieuw om de koppeling te leggen.
        </p>
        <p class="hint">
            Staat een SKU hier terwijl het product wél bestaat? Dan is de SKU op het
            platform waarschijnlijk hernoemd. Faire waarschuwt daar zelf voor: een
            order bewaart de SKU zoals die was op het moment van bestellen.
        </p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
