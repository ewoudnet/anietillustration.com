<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Config;
use App\Csrf;
use App\ImageUpload;
use App\SpecialRepository;

Auth::requireLogin();

$repository = new SpecialRepository();
$id = isset($_GET['id']) ? (int) $_GET['id'] : (isset($_POST['id']) ? (int) $_POST['id'] : null);
$special = $id !== null ? $repository->find($id) : null;

if ($id !== null && $special === null) {
    header('Location: index.php');
    exit;
}

$errors = [];

/** @var array<string, mixed> $formData */
$formData = $special ?? [
    'title' => '',
    'description' => '',
    'banner_path' => null,
    'active' => 0,
    'starts_at' => null,
    'ends_at' => null,
];
$variantRows = $special !== null
    ? array_map(
        static fn (array $v): array => ['label' => $v['label'], 'price' => number_format($v['price_cents'] / 100, 2, '.', '')],
        $repository->findVariants((int) $special['id'])
    )
    : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!Csrf::verify($submittedToken)) {
        $errors[] = 'Je sessie is verlopen. Probeer het opnieuw.';
    }

    $formData['title'] = trim((string) ($_POST['title'] ?? ''));
    $formData['description'] = trim((string) ($_POST['description'] ?? ''));
    $formData['active'] = isset($_POST['active']) ? 1 : 0;
    $formData['starts_at'] = trim((string) ($_POST['starts_at'] ?? '')) !== '' ? $_POST['starts_at'] . ' 00:00:00' : null;
    $formData['ends_at'] = trim((string) ($_POST['ends_at'] ?? '')) !== '' ? $_POST['ends_at'] . ' 23:59:59' : null;

    if ($formData['title'] === '') {
        $errors[] = 'Vul een titel in.';
    }

    if ($formData['starts_at'] !== null && $formData['ends_at'] !== null && $formData['starts_at'] > $formData['ends_at']) {
        $errors[] = 'De startdatum moet vóór de einddatum liggen.';
    }

    $variantLabels = $_POST['variant_label'] ?? [];
    $variantPrices = $_POST['variant_price'] ?? [];
    $variants = [];
    $variantRows = [];

    foreach ($variantLabels as $index => $label) {
        $label = trim((string) $label);
        $priceRaw = trim((string) ($variantPrices[$index] ?? ''));

        if ($label === '' && $priceRaw === '') {
            continue;
        }

        $variantRows[] = ['label' => $label, 'price' => $priceRaw];

        if ($label === '' || $priceRaw === '' || !is_numeric(str_replace(',', '.', $priceRaw))) {
            $errors[] = 'Vul bij elke prijsvariant een label en een geldige prijs in.';
            continue;
        }

        $variants[] = [
            'label' => $label,
            'price_cents' => (int) round((float) str_replace(',', '.', $priceRaw) * 100),
        ];
    }

    if (empty($errors) && count($variants) === 0) {
        $errors[] = 'Voeg minimaal één prijsvariant toe.';
    }

    $bannerPath = $formData['banner_path'] ?? null;

    if (empty($errors)) {
        try {
            $uploaded = ImageUpload::store($_FILES['banner'] ?? [], 'banners', SPECIALS_ASSETS_PATH);
            if ($uploaded !== null) {
                ImageUpload::delete($bannerPath, SPECIALS_ASSETS_PATH);
                $bannerPath = $uploaded;
            }
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (empty($errors)) {
        $data = [
            'title' => $formData['title'],
            'description' => $formData['description'] !== '' ? $formData['description'] : null,
            'banner_path' => $bannerPath,
            'active' => (bool) $formData['active'],
            'starts_at' => $formData['starts_at'],
            'ends_at' => $formData['ends_at'],
        ];

        if ($special !== null) {
            $repository->update((int) $special['id'], $data, $variants);
            header('Location: index.php?updated=1');
        } else {
            $repository->create($data, $variants);
            header('Location: index.php?created=1');
        }
        exit;
    }

    $formData['banner_path'] = $bannerPath;
}

if (empty($variantRows)) {
    $variantRows[] = ['label' => '', 'price' => ''];
}

function dateInputValue(?string $value): string
{
    return $value !== null ? substr($value, 0, 10) : '';
}

$pageTitle = $special !== null ? 'Special bewerken' : 'Nieuwe special';
$activeSection = 'specials';
$activePage = 'form';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1><?= h($pageTitle) ?></h1>
</div>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= h($error) ?></div>
<?php endforeach; ?>

<div class="card">
    <form method="post" action="form.php<?= $special !== null ? '?id=' . (int) $special['id'] : '' ?>" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
        <?php if ($special !== null): ?>
            <input type="hidden" name="id" value="<?= (int) $special['id'] ?>">
        <?php endif; ?>

        <div class="field">
            <label for="title">Titel</label>
            <input type="text" id="title" name="title" required value="<?= h($formData['title']) ?>">
        </div>

        <div class="field">
            <label for="description">Tekst / introductie</label>
            <textarea id="description" name="description" rows="4"><?= h($formData['description'] ?? '') ?></textarea>
        </div>

        <div class="field">
            <label for="banner">Banner-afbeelding (jpg, png of webp)</label>
            <?php if (!empty($formData['banner_path'])): ?>
                <p><img src="<?= h(Config::appUrl()) ?>/assets/<?= h($formData['banner_path']) ?>" alt="" style="max-width: 240px; border-radius: 6px; display: block; margin-bottom: 8px;"></p>
            <?php endif; ?>
            <input type="file" id="banner" name="banner" accept=".jpg,.jpeg,.png,.webp">
        </div>

        <div class="row">
            <div class="field" style="flex: 1 1 200px;">
                <label for="starts_at">Startdatum (optioneel)</label>
                <input type="date" id="starts_at" name="starts_at" value="<?= h(dateInputValue($formData['starts_at'])) ?>">
            </div>
            <div class="field" style="flex: 1 1 200px;">
                <label for="ends_at">Einddatum (optioneel)</label>
                <input type="date" id="ends_at" name="ends_at" value="<?= h(dateInputValue($formData['ends_at'])) ?>">
            </div>
        </div>

        <div class="field">
            <label>
                <input type="checkbox" name="active" value="1" <?= !empty($formData['active']) ? 'checked' : '' ?> style="width: auto; margin-right: 6px;">
                Special is actief (zichtbaar/bestelbaar op de publieke pagina)
            </label>
        </div>

        <h3>Prijsvarianten</h3>
        <div id="variant-rows">
            <?php foreach ($variantRows as $variant): ?>
                <div class="variant-row">
                    <div class="field" style="flex: 2 1 200px;">
                        <label>Label</label>
                        <input type="text" name="variant_label[]" placeholder="bijv. 1 kaart" value="<?= h($variant['label']) ?>">
                    </div>
                    <div class="field" style="flex: 1 1 120px;">
                        <label>Prijs (€)</label>
                        <input type="text" name="variant_price[]" placeholder="bijv. 5,95" value="<?= h($variant['price']) ?>">
                    </div>
                    <button type="button" class="btn btn-secondary" style="width: auto;" onclick="this.closest('.variant-row').remove()">Verwijderen</button>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-secondary" style="width: auto; margin-bottom: 18px;" id="add-variant">+ Prijsvariant toevoegen</button>

        <button type="submit" class="btn" style="width: auto;">Opslaan</button>
        &nbsp;<a href="index.php">Annuleren</a>
    </form>
</div>

<template id="variant-row-template">
    <div class="variant-row">
        <div class="field" style="flex: 2 1 200px;">
            <label>Label</label>
            <input type="text" name="variant_label[]" placeholder="bijv. 1 kaart">
        </div>
        <div class="field" style="flex: 1 1 120px;">
            <label>Prijs (€)</label>
            <input type="text" name="variant_price[]" placeholder="bijv. 5,95">
        </div>
        <button type="button" class="btn btn-secondary" style="width: auto;" onclick="this.closest('.variant-row').remove()">Verwijderen</button>
    </div>
</template>
<script>
document.getElementById('add-variant').addEventListener('click', function () {
    const template = document.getElementById('variant-row-template');
    document.getElementById('variant-rows').appendChild(template.content.cloneNode(true));
});
</script>

<?php require __DIR__ . '/../partials/layout-end.php'; ?>
