<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\CardRepository;
use App\ProductRepository;
use App\ProductTypeRepository;
use Dompdf\Dompdf;
use Dompdf\Options;

Auth::requireSection('aniet-illustration');

// Geen paginering nodig hier - de catalogus is bedoeld om in één keer alles
// van de gekozen producttypes te tonen.
const CATALOG_MAX_ITEMS_PER_TYPE = 100000;

$includeDraft = ($_GET['include_draft'] ?? '') === '1';
$draftOnly = $includeDraft ? null : false;

$requestedTypes = array_map('strval', (array) ($_GET['types'] ?? []));
$productTypes = ProductTypeRepository::findAll();

$groups = [];
foreach ($productTypes as $productType) {
    $isCards = $productType['name'] === 'Kaarten';
    $value = $isCards ? 'cards' : (string) $productType['id'];
    if (!in_array($value, $requestedTypes, true)) {
        continue;
    }

    $items = $isCards
        ? CardRepository::findAllForOrderPage(CATALOG_MAX_ITEMS_PER_TYPE, 0, 'title', 'asc', $draftOnly)
        : ProductRepository::findAllForOrderPage((int) $productType['id'], CATALOG_MAX_ITEMS_PER_TYPE, 0, 'title', 'asc', $draftOnly);

    if ($items === []) {
        continue;
    }

    $groups[] = ['name' => $productType['name'], 'items' => $items];
}

if ($groups === []) {
    header('Location: catalog.php?empty=1');
    exit;
}

/**
 * Bestaand, lokaal bestandspad voor een product-/kaartfoto, of null als er geen
 * afbeelding is (dan tonen we een lege placeholder i.p.v. een gebroken <img>).
 */
function catalogImagePath(array $item): ?string
{
    if (empty($item['image_path'])) {
        return null;
    }

    $path = BO_ASSETS_PATH . '/' . $item['image_path'];

    return is_file($path) ? str_replace('\\', '/', $path) : null;
}

ob_start();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
    h1 { font-size: 18px; margin: 0 0 4px; }
    .subtitle { font-size: 11px; color: #666; margin: 0 0 20px; }
    h2 { font-size: 14px; margin: 22px 0 8px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; font-size: 10px; color: #666; border-bottom: 1px solid #ccc; padding: 4px 6px; }
    td { padding: 6px; border-bottom: 1px solid #eee; vertical-align: middle; }
    td.photo { width: 64px; }
    td.sku { width: 90px; font-family: monospace; }
    td.qty { width: 90px; }
    .thumb { width: 56px; height: 56px; }
    .thumb-empty { display: block; width: 56px; height: 56px; border: 1px solid #ddd; background: #f7f7f7; }
    .qty-box { display: block; width: 70px; height: 24px; border: 1px solid #999; }
    tr { page-break-inside: avoid; }
</style>
</head>
<body>
    <h1>Aniet Illustration &ndash; Catalogus</h1>
    <p class="subtitle">Gegenereerd op <?= date('d-m-Y') ?> &ndash; vul het gewenste aantal in en stuur deze PDF terug.</p>

    <?php foreach ($groups as $group): ?>
        <h2><?= h($group['name']) ?></h2>
        <table>
            <thead>
                <tr>
                    <th class="photo"></th>
                    <th class="sku">SKU</th>
                    <th>Titel</th>
                    <th class="qty">Aantal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($group['items'] as $item): ?>
                    <?php $imagePath = catalogImagePath($item); ?>
                    <tr>
                        <td class="photo">
                            <?php if ($imagePath !== null): ?>
                                <img class="thumb" src="<?= h($imagePath) ?>" alt="">
                            <?php else: ?>
                                <div class="thumb-empty"></div>
                            <?php endif; ?>
                        </td>
                        <td class="sku"><?= h($item['sku']) ?></td>
                        <td><?= h($item['title']) ?></td>
                        <td class="qty"><span class="qty-box"></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>
</body>
</html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');
// dompdf staat standaard alleen bestanden binnen zijn eigen vendor-map toe
// (chroot-beveiliging tegen path traversal) - de product-/kaartfoto's staan
// fysiek in BO_ASSETS_PATH, dus die (en de projectroot) moeten expliciet
// toegevoegd worden, anders laden de thumbnails niet.
$options->setChroot([BO_ASSETS_PATH, dirname(__DIR__, 2)]);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = 'catalogus-' . date('Y-m-d') . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo $dompdf->output();
