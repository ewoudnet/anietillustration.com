<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\CardRepository;
use App\Csrf;
use App\FaireService;
use App\ProductRepository;

Auth::requireAdmin();

$activeSection = 'settings';
$activePage = 'faire-sync';
$csrfToken = Csrf::token();

$errors = [];
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync') {
    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Je sessie is verlopen. Probeer het opnieuw.';
    } elseif (!FaireService::isConfigured()) {
        $errors[] = 'Faire-credentials zijn nog niet ingesteld in .env ' .
            '(FAIRE_APPLICATION_ID, FAIRE_APPLICATION_SECRET, FAIRE_ACCESS_TOKEN).';
    } else {
        try {
            $cardSkuMap = CardRepository::allIdsBySku();
            $productSkuMap = ProductRepository::allIdsBySku();
            $allSkus = array_merge(array_keys($cardSkuMap), array_keys($productSkuMap));

            $inventory = FaireService::fetchInventoryBySkus($allSkus);

            $updated = 0;
            $untracked = [];
            foreach ($inventory as $sku => $quantity) {
                if ($quantity === null) {
                    $untracked[] = $sku;
                    continue;
                }

                if (isset($cardSkuMap[$sku])) {
                    CardRepository::updateCurrentStock($cardSkuMap[$sku], $quantity);
                    $updated++;
                } elseif (isset($productSkuMap[$sku])) {
                    ProductRepository::updateCurrentStock($productSkuMap[$sku], $quantity);
                    $updated++;
                }
            }

            $notFound = array_values(array_diff($allSkus, array_keys($inventory)));
            sort($untracked);
            sort($notFound);

            $result = [
                'total_skus' => count($allSkus),
                'updated' => $updated,
                'untracked' => $untracked,
                'not_found' => $notFound,
            ];
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$pageTitle = 'Faire sync';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1>🔄 Faire sync</h1>
</div>

<div class="card" style="margin-bottom: 20px;">
    <p>Haalt de actuele voorraad op bij Faire (op basis van SKU) en werkt daarmee het
        <strong>huidige voorraad</strong>-veld bij van alle kaarten en producten waarvan de SKU bij
        Faire bekend is. Dit gaat altijd één kant op: van Faire naar hier - er wordt nooit iets
        naar Faire teruggeschreven.</p>
    <p class="hint">
        Kaarten/producten die (nog) niet op Faire staan, blijven gewoon ongewijzigd. Nieuwe
        kaarten/producten voeg je dus eerst hier toe (met dezelfde SKU als op Faire) - de sync
        vult daarna alleen de voorraad aan, hij maakt zelf niets aan.
    </p>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="faire-sync.php">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="action" value="sync">
        <button type="submit" class="btn" style="width: auto;">🔄 Nu synchroniseren met Faire</button>
    </form>
</div>

<?php if ($result !== null): ?>
    <div class="card">
        <div class="stat-grid">
            <div class="stat-tile">
                <div class="value"><?= (int) $result['total_skus'] ?></div>
                <div class="label">SKU's gecontroleerd</div>
            </div>
            <div class="stat-tile">
                <div class="value"><?= (int) $result['updated'] ?></div>
                <div class="label">Voorraad bijgewerkt</div>
            </div>
            <div class="stat-tile">
                <div class="value"><?= count($result['untracked']) ?></div>
                <div class="label">Wel op Faire, geen voorraadgetal</div>
            </div>
            <div class="stat-tile">
                <div class="value"><?= count($result['not_found']) ?></div>
                <div class="label">Niet gevonden op Faire</div>
            </div>
        </div>

        <?php if ($result['untracked'] !== []): ?>
            <p><strong>SKU's zonder voorraadgetal op Faire</strong> (Faire houdt hier geen aantal
                bij, "untracked" - voorraad hier dus niet aangepast):</p>
            <p class="hint"><?= h(implode(', ', $result['untracked'])) ?></p>
        <?php endif; ?>

        <?php if ($result['not_found'] !== []): ?>
            <p><strong>SKU's die niet op Faire gevonden zijn</strong> (nog niet gepubliceerd bij
                Faire, of typefout in de SKU):</p>
            <p class="hint"><?= h(implode(', ', $result['not_found'])) ?></p>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
