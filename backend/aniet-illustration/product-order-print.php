<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\ProductRepository;
use App\ProductTypeRepository;

Auth::requireSection('aniet-illustration');

$typeId = (int) ($_GET['type_id'] ?? 0);
$productType = $typeId > 0 ? ProductTypeRepository::find($typeId) : null;

if ($productType === null) {
    header('Location: cards.php');
    exit;
}

$needsOrdering = ProductRepository::needsOrdering($typeId);
$generatedAt = (new DateTime())->format('d-m-Y H:i');
$totalToOrder = array_sum(array_map(static fn (array $p): int => (int) $p['to_order'], $needsOrdering));
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bestellijst <?= h($productType['name']) ?> <?= h($generatedAt) ?></title>
    <link rel="icon" href="<?= h(BACKEND_BASE) ?>/assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= h(BACKEND_BASE) ?>/assets/css/style.css">
    <style>
        body { background: #fff; padding: 20px; max-width: none; }
        .print-header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 16px; flex-wrap: wrap; gap: 8px; }
        table.print-table { width: 100%; border-collapse: collapse; }
        table.print-table th, table.print-table td { border: 1px solid #ccc; padding: 8px 10px; text-align: left; }
        table.print-table img { width: 42px; height: 58px; object-fit: contain; border-radius: 4px; }
        @media print {
            .print-actions { display: none; }
            body { padding: 0; }
        }
    </style>
</head>
<body>
<div class="print-actions" style="margin-bottom: 16px;">
    <button onclick="window.print()" class="btn" style="width: auto;">🖨️ Afdrukken / opslaan als PDF</button>
</div>
<div class="print-header">
    <h1 style="font-size: 1.3rem; margin: 0;">Bestellijst <?= h($productType['name']) ?></h1>
    <span>Gegenereerd op <?= h($generatedAt) ?></span>
</div>
<p>Totaal te bestellen: <strong><?= (int) $totalToOrder ?></strong>
    &middot; Aantal producten: <strong><?= count($needsOrdering) ?></strong></p>

<?php if ($needsOrdering === []): ?>
    <p>Er hoeft momenteel niets besteld te worden.</p>
<?php else: ?>
    <table class="print-table">
        <thead>
        <tr>
            <th>Afbeelding</th>
            <th>SKU</th>
            <th>Titel</th>
            <th>Te bestellen</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($needsOrdering as $product): ?>
            <tr>
                <td><?php if (!empty($product['image_path'])): ?><img src="<?= h(BO_ASSETS_URL) ?>/<?= h($product['image_path']) ?>" alt=""><?php endif; ?></td>
                <td><?= h($product['sku']) ?></td>
                <td><?= h($product['title']) ?></td>
                <td><?= (int) $product['to_order'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</body>
</html>
