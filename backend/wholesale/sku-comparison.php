<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\ProductPlatformListingRepository;
use App\ProductRepository;
use App\WholesalePlatformRepository;

Auth::requireSection('wholesale');

$platforms = WholesalePlatformRepository::findAll();
$products = ProductRepository::findAllWithTypeName();
$listingsByProduct = ProductPlatformListingRepository::allGroupedByProduct();

$onlyIssues = isset($_GET['only_issues']);

$pageTitle = 'Wholesale - SKU-vergelijking';
$activeSection = 'wholesale';
$activePage = 'sku-comparison';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1>SKU-vergelijking per platform</h1>
</div>

<div class="card" style="margin-bottom: 20px;">
    <p class="hint">
        Toont per product of het op Faire en Orderchamp geplaatst is, en of de
        laatst geziene voorraad daar overeenkomt met de eigen voorraad. Zolang er
        nog geen platformkoppeling actief is (fase C+), staat hier voor elk
        product "niet geplaatst" - dat is verwacht, geen fout.
    </p>
    <a href="?<?= $onlyIssues ? '' : 'only_issues=1' ?>" class="btn btn-secondary" style="width: auto; display: inline-block;">
        <?= $onlyIssues ? 'Toon alle producten' : 'Toon alleen producten met een afwijking' ?>
    </a>
</div>

<div class="card">
    <?php if (count($products) === 0): ?>
        <p>Er zijn nog geen producten aangemaakt.</p>
    <?php else: ?>
        <div class="table-wrapper">
            <table>
                <thead>
                <tr>
                    <th>SKU</th>
                    <th>Titel</th>
                    <th>Type</th>
                    <th>Eigen voorraad</th>
                    <?php foreach ($platforms as $platform): ?>
                        <th><?= h($platform['icon'] ?? '') ?> <?= h($platform['name']) ?></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php
                $shown = 0;
                foreach ($products as $product):
                    $listings = $listingsByProduct[(int) $product['id']] ?? [];
                    $hasIssue = false;
                    foreach ($platforms as $platform) {
                        $listing = $listings[$platform['code']] ?? null;
                        if ($listing === null || (int) $listing['is_listed'] !== 1) {
                            $hasIssue = true;
                            continue;
                        }
                        if ($listing['last_seen_stock'] !== null && (int) $listing['last_seen_stock'] !== (int) $product['current_stock']) {
                            $hasIssue = true;
                        }
                    }
                    if ($onlyIssues && !$hasIssue) {
                        continue;
                    }
                    $shown++;
                    ?>
                    <tr>
                        <td><?= h($product['sku']) ?></td>
                        <td><?= h($product['title']) ?></td>
                        <td><?= h($product['product_type_name']) ?></td>
                        <td><?= $product['current_stock'] !== null ? (int) $product['current_stock'] : '—' ?></td>
                        <?php foreach ($platforms as $platform): ?>
                            <?php $listing = $listings[$platform['code']] ?? null; ?>
                            <td>
                                <?php if ($listing === null || (int) $listing['is_listed'] !== 1): ?>
                                    <span class="badge badge-off">Niet geplaatst</span>
                                <?php elseif ($listing['last_seen_stock'] !== null && (int) $listing['last_seen_stock'] !== (int) $product['current_stock']): ?>
                                    <span class="badge badge-failed">Voorraad wijkt af (<?= (int) $listing['last_seen_stock'] ?>)</span>
                                <?php else: ?>
                                    <span class="badge badge-on">Geplaatst</span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ($shown === 0): ?>
                    <tr><td colspan="<?= 4 + count($platforms) ?>">Geen producten met een afwijking gevonden.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
