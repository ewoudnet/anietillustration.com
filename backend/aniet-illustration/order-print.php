<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\CardRepository;

Auth::requireSection('aniet-illustration');

$needsOrdering = CardRepository::needsOrdering();
$generatedAt = (new DateTime())->format('d-m-Y H:i');
$totalToOrder = array_sum(array_map(static fn (array $c): int => (int) $c['to_order'], $needsOrdering));
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bestellijst <?= h($generatedAt) ?></title>
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
    <h1 style="font-size: 1.3rem; margin: 0;">Bestellijst kaarten</h1>
    <span>Gegenereerd op <?= h($generatedAt) ?></span>
</div>
<p>Totaal te bestellen: <strong><?= (int) $totalToOrder ?></strong>
    &middot; Aantal ontwerpen: <strong><?= count($needsOrdering) ?></strong></p>

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
        <?php foreach ($needsOrdering as $cardRow): ?>
            <tr>
                <td><?php if (!empty($cardRow['image_path'])): ?><img src="<?= h(BO_ASSETS_URL) ?>/<?= h($cardRow['image_path']) ?>" alt=""><?php endif; ?></td>
                <td><?= h($cardRow['sku']) ?></td>
                <td><?= h($cardRow['title']) ?></td>
                <td><?= (int) $cardRow['to_order'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</body>
</html>
