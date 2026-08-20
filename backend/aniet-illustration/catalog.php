<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\ProductTypeRepository;

Auth::requireSection('aniet-illustration');

$activeSection = 'aniet-illustration';
$activeProductType = null;
$activePage = 'catalog';

$productTypes = ProductTypeRepository::findAll();

$pageTitle = 'Catalogus downloaden';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1>📄 Catalogus downloaden</h1>
</div>

<?php if (isset($_GET['empty'])): ?>
    <div class="alert alert-error">Geen producten gevonden voor de gekozen producttypes.</div>
<?php endif; ?>

<div class="card" style="padding: 18px 22px; max-width: 520px;">
    <p>Genereert een PDF-catalogus met foto, SKU en titel per product, met een
        leeg invulveld voor het gewenste aantal. Bedoeld om naar B2B-klanten te
        sturen die niet via Faire bestellen: zij vullen de PDF in en sturen hem
        terug, waarna de factuur handmatig opgemaakt wordt.</p>
    <p><small class="hint">Bij Kaarten komen alleen kaarten in de catalogus die aan de
        Wholesale-verkoopkanaal gekoppeld zijn - kaarten die uitsluitend bij Greetz,
        Kaartje2Go, Thortful of Redbubble verkocht worden blijven erbuiten.</small></p>

    <form method="get" action="catalog-pdf.php">
        <div class="field">
            <label>Producttypes</label>
            <?php foreach ($productTypes as $pt): ?>
                <div class="field field-checkbox">
                    <label>
                        <input type="checkbox" name="types[]" value="<?= $pt['name'] === 'Kaarten' ? 'cards' : (int) $pt['id'] ?>" checked>
                        <?= h($pt['name']) ?>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="field field-checkbox" style="margin-top: 10px;">
            <label>
                <input type="checkbox" name="include_draft" value="1">
                Inclusief Wholesale Draft-producten
            </label>
            <small class="hint">Standaard uitgesloten - dit zijn producten die intern nog niet klaar zijn voor verkoop.</small>
        </div>

        <button type="submit" class="btn" style="margin-top: 16px;">📄 PDF downloaden</button>
    </form>
</div>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
