<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\CardRepository;
use App\Csrf;
use App\ProductPlatformListingRepository;
use App\ProductRepository;
use App\WholesalePlatformRepository;
use App\WholesaleStockChecker;

Auth::requireSection('wholesale');

$csrfToken = Csrf::token();
$checkErrors = [];
$checkResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_stock') {
    if (!Auth::isAdmin()) {
        http_response_code(403);
        echo '403 - Alleen beheerders kunnen de voorraad controleren.';
        exit;
    }

    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $checkErrors[] = 'Je sessie is verlopen. Probeer het opnieuw.';
    } else {
        $checkResult = WholesaleStockChecker::run();
    }
}

$platforms = WholesalePlatformRepository::findAll();
$listingsByProduct = ProductPlatformListingRepository::allGroupedByProduct();
$listingsByCard = ProductPlatformListingRepository::allGroupedByCard();

// Producten en kaarten samen tonen - Wholesale verkoopt allebei (zelfde SKU-
// ruimte als de bestaande Faire-voorraadsync), zie docs/wholesale.md.
$items = [];
foreach (ProductRepository::findAllWithTypeName() as $product) {
    $items[] = [
        'sku' => $product['sku'],
        'title' => $product['title'],
        'type_name' => $product['product_type_name'],
        'current_stock' => $product['current_stock'],
        'listings' => $listingsByProduct[(int) $product['id']] ?? [],
    ];
}
foreach (CardRepository::search() as $card) {
    $items[] = [
        'sku' => $card['sku'],
        'title' => $card['title'],
        'type_name' => 'Kaarten',
        'current_stock' => $card['current_stock'],
        'listings' => $listingsByCard[(int) $card['id']] ?? [],
    ];
}
usort($items, static fn (array $a, array $b): int => strcmp($a['sku'], $b['sku']));

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
        Toont per product/kaart of het op Faire en Orderchamp geplaatst is, en
        of de laatst geziene voorraad daar overeenkomt met de eigen voorraad.
        Dit past nooit de eigen voorraad aan - dat volgt in een latere
        bouwfase; hier wordt alleen gelezen en vergeleken.
    </p>

    <?php if (!empty($checkErrors)): ?>
        <div class="alert alert-error">
            <ul>
                <?php foreach ($checkErrors as $error): ?>
                    <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($checkResult !== null): ?>
        <?php foreach ($checkResult as $code => $platformResult): ?>
            <?php if ($platformResult['error'] !== null): ?>
                <div class="alert alert-error"><?= h(ucfirst($code)) ?>: <?= h($platformResult['error']) ?></div>
            <?php else: ?>
                <p class="hint"><?= h(ucfirst($code)) ?>: <?= (int) $platformResult['checked'] ?> SKU's gecontroleerd,
                    <?= (int) $platformResult['listed'] ?> geplaatst gevonden.</p>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="row" style="align-items: center; gap: 12px;">
        <?php if (Auth::isAdmin()): ?>
            <form method="post" action="sku-comparison.php" style="display: inline-block;">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="check_stock">
                <button type="submit" class="btn" style="width: auto;">🔄 Vernieuw voorraadvergelijking</button>
            </form>
        <?php endif; ?>
        <a href="?<?= $onlyIssues ? '' : 'only_issues=1' ?>" class="btn btn-secondary" style="width: auto; display: inline-block;">
            <?= $onlyIssues ? 'Toon alles' : 'Toon alleen items met een afwijking' ?>
        </a>
    </div>
</div>

<div class="card">
    <?php if (count($items) === 0): ?>
        <p>Er zijn nog geen producten of kaarten aangemaakt.</p>
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
                foreach ($items as $item):
                    $listings = $item['listings'];
                    $hasIssue = false;
                    foreach ($platforms as $platform) {
                        $listing = $listings[$platform['code']] ?? null;
                        if ($listing === null || (int) $listing['is_listed'] !== 1) {
                            $hasIssue = true;
                            continue;
                        }
                        if ($listing['last_seen_stock'] !== null && (int) $listing['last_seen_stock'] !== (int) $item['current_stock']) {
                            $hasIssue = true;
                        }
                    }
                    if ($onlyIssues && !$hasIssue) {
                        continue;
                    }
                    $shown++;
                    ?>
                    <tr>
                        <td><?= h($item['sku']) ?></td>
                        <td><?= h($item['title']) ?></td>
                        <td><?= h($item['type_name']) ?></td>
                        <td><?= $item['current_stock'] !== null ? (int) $item['current_stock'] : '—' ?></td>
                        <?php foreach ($platforms as $platform): ?>
                            <?php $listing = $listings[$platform['code']] ?? null; ?>
                            <td>
                                <?php if ($listing === null || (int) $listing['is_listed'] !== 1): ?>
                                    <span class="badge badge-off">Niet geplaatst</span>
                                <?php elseif ($listing['last_seen_stock'] !== null && (int) $listing['last_seen_stock'] !== (int) $item['current_stock']): ?>
                                    <span class="badge badge-failed">Voorraad wijkt af (<?= (int) $listing['last_seen_stock'] ?>)</span>
                                <?php else: ?>
                                    <span class="badge badge-on">Geplaatst</span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ($shown === 0): ?>
                    <tr><td colspan="<?= 4 + count($platforms) ?>">Geen items met een afwijking gevonden.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
