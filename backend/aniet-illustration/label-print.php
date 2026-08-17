<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\CardRepository;

Auth::requireSection('aniet-illustration');

$selectedIds = array_values(array_unique(array_filter(
    array_map('intval', (array) ($_GET['ids'] ?? [])),
    static fn (int $id): bool => $id > 0
)));

if ($selectedIds !== []) {
    $labelCards = CardRepository::findByIds($selectedIds);
    $isSelectionMode = true;
} else {
    $labelCards = array_values(array_filter(
        CardRepository::needsOrdering(),
        static fn (array $c): bool => (int) $c['wholesale_draft'] === 1
    ));
    $isSelectionMode = false;
}

$widthMm = (int) ($_GET['width_mm'] ?? 50);
$heightMm = (int) ($_GET['height_mm'] ?? 70);
$widthMm = max(15, min(150, $widthMm));
$heightMm = max(15, min(150, $heightMm));

$generatedAt = (new DateTime())->format('d-m-Y H:i');
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Labels nieuwe kaarten <?= h($generatedAt) ?></title>
    <link rel="icon" href="<?= h(BACKEND_BASE) ?>/assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= h(BACKEND_BASE) ?>/assets/css/style.css">
    <style>
        body { background: #fff; padding: 20px; max-width: none; }
        .print-header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 16px; flex-wrap: wrap; gap: 8px; }
        .label-settings { display: flex; align-items: end; gap: 14px; flex-wrap: wrap; margin-bottom: 16px; }
        .label-settings .field { display: flex; flex-direction: column; gap: 4px; }
        .label-settings input[type="number"] { width: 90px; }
        .label-grid { display: flex; flex-wrap: wrap; gap: 4mm; }
        .label-cell {
            width: <?= $widthMm ?>mm;
            box-sizing: border-box;
            border: 1px dashed #999;
            padding: 1mm;
            text-align: center;
            page-break-inside: avoid;
        }
        .label-cell .thumb {
            width: 100%;
            height: <?= $heightMm ?>mm;
            object-fit: contain;
            display: block;
        }
        .label-cell .thumb-placeholder {
            width: 100%;
            height: <?= $heightMm ?>mm;
            background: #f2f2f2;
        }
        .label-cell .sku { font-weight: 700; font-size: 1.2rem; margin-top: 1mm; }
        @media print {
            .print-actions, .label-settings { display: none; }
            body { padding: 0; }
            .label-cell { border-style: dashed; }
        }
    </style>
</head>
<body>
<div class="print-actions" style="margin-bottom: 16px;">
    <button onclick="window.print()" class="btn" style="width: auto;">🖨️ Afdrukken / opslaan als PDF</button>
</div>
<form method="get" action="label-print.php" class="label-settings">
    <?php foreach ($selectedIds as $selectedId): ?>
        <input type="hidden" name="ids[]" value="<?= (int) $selectedId ?>">
    <?php endforeach; ?>
    <div class="field">
        <label for="width_mm">Breedte (mm)</label>
        <input type="number" id="width_mm" name="width_mm" min="15" max="150" value="<?= (int) $widthMm ?>">
    </div>
    <div class="field">
        <label for="height_mm">Hoogte (mm)</label>
        <input type="number" id="height_mm" name="height_mm" min="15" max="150" value="<?= (int) $heightMm ?>">
    </div>
    <div class="field">
        <button type="submit" class="btn" style="width: auto; margin-top: 0;">Toepassen</button>
    </div>
</form>
<div class="print-header">
    <h1 style="font-size: 1.3rem; margin: 0;"><?= $isSelectionMode ? 'Labels geselecteerde kaarten' : 'Labels nieuwe (draft) kaarten' ?></h1>
    <span>Gegenereerd op <?= h($generatedAt) ?></span>
</div>
<p>Aantal ontwerpen: <strong><?= count($labelCards) ?></strong>
    &middot; Labelgrootte: <strong><?= (int) $widthMm ?> x <?= (int) $heightMm ?> mm</strong></p>

<?php if ($labelCards === []): ?>
    <p><?= $isSelectionMode ? 'Geen van de geselecteerde kaarten kon gevonden worden.' : 'Er zijn momenteel geen bestelde draft-kaarten om te labelen.' ?></p>
<?php else: ?>
    <div class="label-grid">
        <?php foreach ($labelCards as $cardRow): ?>
            <div class="label-cell">
                <?php if (!empty($cardRow['image_path'])): ?>
                    <img class="thumb" src="<?= h(BO_ASSETS_URL) ?>/<?= h($cardRow['image_path']) ?>" alt="">
                <?php else: ?>
                    <div class="thumb-placeholder"></div>
                <?php endif; ?>
                <div class="sku"><?= h($cardRow['sku']) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</body>
</html>
