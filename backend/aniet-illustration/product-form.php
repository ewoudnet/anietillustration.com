<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\ImageUpload;
use App\ProductRepository;
use App\ProductTypeRepository;

Auth::requireSection('aniet-illustration');

$typeId = (int) ($_GET['type_id'] ?? $_POST['type_id'] ?? 0);
$productType = $typeId > 0 ? ProductTypeRepository::find($typeId) : null;

if ($productType === null || $productType['name'] === 'Kaarten') {
    header('Location: cards.php');
    exit;
}

$activeSection = 'aniet-illustration';
$activeProductType = $typeId;
$activePage = 'product-form';
$csrfToken = Csrf::token();

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$existing = $id !== null ? ProductRepository::find($id) : null;

if ($id !== null && ($existing === null || (int) $existing['product_type_id'] !== $typeId)) {
    header('Location: products.php?type_id=' . $typeId);
    exit;
}

$errors = [];
$values = [
    'sku' => $existing['sku'] ?? '',
    'title' => $existing['title'] ?? '',
    'min_stock' => (string) ($existing['min_stock'] ?? 0),
    'current_stock' => $existing !== null && $existing['current_stock'] !== null ? (string) $existing['current_stock'] : '',
    'to_order' => (string) ($existing['to_order'] ?? 0),
    'comments' => $existing['comments'] ?? '',
    'wholesale_draft' => (int) ($existing['wholesale_draft'] ?? 0),
];
$imagePath = $existing['image_path'] ?? null;

if ($existing === null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $values['sku'] = ProductRepository::suggestNextSku(date('ymd'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');

    $values['sku'] = trim((string) ($_POST['sku'] ?? ''));
    $values['title'] = trim((string) ($_POST['title'] ?? ''));
    $values['min_stock'] = trim((string) ($_POST['min_stock'] ?? '0'));
    $values['current_stock'] = trim((string) ($_POST['current_stock'] ?? ''));
    $values['to_order'] = trim((string) ($_POST['to_order'] ?? '0'));
    if ($values['to_order'] === '') {
        $values['to_order'] = '0';
    }
    $values['comments'] = trim((string) ($_POST['comments'] ?? ''));
    $values['wholesale_draft'] = isset($_POST['wholesale_draft']) ? 1 : 0;

    if (!Csrf::verify($submittedToken)) {
        $errors[] = 'Je sessie is verlopen. Probeer het opnieuw.';
    }

    if ($values['sku'] === '') {
        $errors[] = 'Vul een SKU in.';
    } elseif (ProductRepository::skuExists($values['sku'], $id)) {
        $errors[] = 'Deze SKU is al in gebruik door een ander product.';
    }

    if ($values['title'] === '') {
        $errors[] = 'Vul een titel in.';
    }

    if (!ctype_digit($values['min_stock'])) {
        $errors[] = 'Minimale voorraad moet een geheel getal zijn.';
    }

    if ($values['current_stock'] !== '' && !ctype_digit($values['current_stock'])) {
        $errors[] = 'Huidige voorraad moet leeg zijn of een geheel getal.';
    }

    if (!ctype_digit($values['to_order'])) {
        $errors[] = 'Te bestellen moet een geheel getal zijn.';
    }

    if (empty($errors)) {
        try {
            $uploaded = ImageUpload::store($_FILES['image'] ?? [], 'products', BO_ASSETS_PATH);
            if ($uploaded !== null) {
                if ($existing !== null) {
                    ImageUpload::delete($existing['image_path'], BO_ASSETS_PATH);
                }
                $imagePath = $uploaded;
            }
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (empty($errors)) {
        try {
            $data = [
                'product_type_id' => $typeId,
                'sku' => $values['sku'],
                'title' => $values['title'],
                'image_path' => $imagePath,
                'min_stock' => (int) $values['min_stock'],
                'current_stock' => $values['current_stock'] === '' ? null : (int) $values['current_stock'],
                'to_order' => (int) $values['to_order'],
                'comments' => $values['comments'] !== '' ? $values['comments'] : null,
                'wholesale_draft' => $values['wholesale_draft'],
            ];

            if ($id !== null) {
                ProductRepository::update($id, $data);
                header('Location: products.php?type_id=' . $typeId . '&updated=1');
            } else {
                $id = ProductRepository::create($data);
                header('Location: products.php?type_id=' . $typeId . '&created=1');
            }
            exit;
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$pageTitle = $existing !== null ? 'Product bewerken' : 'Product toevoegen';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1><?= $existing !== null ? '✏️ Product bewerken' : '+ Product toevoegen' ?> - <?= h($productType['name']) ?></h1>
</div>

<div class="card">
    <?php if (isset($_GET['duplicated'])): ?>
        <div class="alert alert-success">Product gedupliceerd met SKU <?= h($values['sku']) ?>. Controleer en pas de gegevens hieronder aan.</div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <strong>Controleer de volgende velden:</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="product-form.php?type_id=<?= (int) $typeId ?><?= $id !== null ? '&id=' . (int) $id : '' ?>" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="type_id" value="<?= (int) $typeId ?>">

        <fieldset>
            <legend>Afbeelding</legend>
            <?php if ($imagePath !== null): ?>
                <img class="table-thumb" style="width: 96px; height: 96px; margin-bottom: 10px;" src="<?= h(BO_ASSETS_URL) ?>/<?= h($imagePath) ?>" alt="">
            <?php endif; ?>
            <div class="field">
                <input type="file" name="image" accept="image/png,image/jpeg,image/webp">
                <small class="hint">jpg, png of webp, max 20 MB. Grote afbeeldingen worden automatisch verkleind tot max 2000px. Laat leeg om de huidige afbeelding te behouden.</small>
            </div>
        </fieldset>

        <fieldset>
            <legend>Basisgegevens</legend>
            <div class="row">
                <div class="field">
                    <label for="sku">SKU *</label>
                    <input type="text" id="sku" name="sku" required value="<?= h($values['sku']) ?>">
                </div>
                <div class="field" style="flex: 2 1 300px;">
                    <label for="title">Titel *</label>
                    <input type="text" id="title" name="title" required value="<?= h($values['title']) ?>">
                </div>
            </div>
            <?php if ($existing !== null): ?>
                <div class="field">
                    <label>Datum creatie</label>
                    <input type="text" disabled value="<?= h((new DateTime($existing['created_at']))->format('d-m-Y H:i')) ?>">
                </div>
            <?php endif; ?>
        </fieldset>

        <fieldset>
            <legend>Voorraad</legend>
            <div class="row">
                <div class="field">
                    <label for="min_stock">Minimale voorraad *</label>
                    <input type="number" id="min_stock" name="min_stock" min="0" required value="<?= h($values['min_stock']) ?>">
                </div>
                <div class="field">
                    <label for="current_stock">Huidige voorraad</label>
                    <input type="number" id="current_stock" name="current_stock" min="0" value="<?= h($values['current_stock']) ?>">
                    <small class="hint">Handmatig bijhouden totdat de Faire-koppeling live voorraad kan aanleveren.</small>
                </div>
                <div class="field">
                    <label for="to_order">Te bestellen</label>
                    <input type="number" id="to_order" name="to_order" min="0" value="<?= h($values['to_order']) ?>">
                    <small class="hint">Kan ook direct op de bestelpagina aangepast worden.</small>
                </div>
            </div>
            <div class="field" style="margin-top: 10px;">
                <label>
                    <input type="checkbox" id="wholesale_draft" name="wholesale_draft" value="1" <?= $values['wholesale_draft'] === 1 ? 'checked' : '' ?>>
                    Wholesale Draft
                </label>
                <small class="hint">Draft-producten met 0 of lage voorraad verschijnen niet automatisch in de bestellijst; alleen via het aparte draft-filter of een expliciete "te bestellen".</small>
            </div>
        </fieldset>

        <fieldset>
            <legend>Comments</legend>
            <div class="field">
                <textarea name="comments" rows="4"><?= h($values['comments']) ?></textarea>
            </div>
        </fieldset>

        <button type="submit" class="btn"><?= $existing !== null ? 'Opslaan' : 'Product toevoegen' ?></button>
        <a href="products.php?type_id=<?= (int) $typeId ?>" class="btn btn-secondary">Annuleren</a>
    </form>
</div>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
