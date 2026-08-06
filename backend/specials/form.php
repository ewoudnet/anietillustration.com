<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Config;
use App\Csrf;
use App\ImageUpload;
use App\SpecialRepository;

Auth::requireSection('specials');

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
    'slug' => '',
    'description' => '',
    'banner_path' => null,
    'active' => 0,
    'ship_eu' => 1,
    'ship_world' => 0,
    'starts_at' => null,
    'ends_at' => null,
];

function formatPriceInput(?int $cents): string
{
    return $cents !== null ? number_format($cents / 100, 2, '.', '') : '';
}

$variantRows = $special !== null
    ? array_map(
        static fn (array $v): array => [
            'label' => $v['label'],
            'price_nl' => formatPriceInput((int) $v['price_nl_cents']),
            'price_eu' => formatPriceInput($v['price_eu_cents'] !== null ? (int) $v['price_eu_cents'] : null),
            'price_world' => formatPriceInput($v['price_world_cents'] !== null ? (int) $v['price_world_cents'] : null),
        ],
        $repository->findVariants((int) $special['id'])
    )
    : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!Csrf::verify($submittedToken)) {
        $errors[] = 'Je sessie is verlopen. Probeer het opnieuw.';
    }

    $formData['title'] = trim((string) ($_POST['title'] ?? ''));
    $formData['slug'] = SpecialRepository::slugify((string) ($_POST['slug'] ?? ''));
    $formData['description'] = trim((string) ($_POST['description'] ?? ''));
    $formData['active'] = isset($_POST['active']) ? 1 : 0;
    $formData['ship_eu'] = isset($_POST['ship_eu']) ? 1 : 0;
    $formData['ship_world'] = isset($_POST['ship_world']) ? 1 : 0;
    $formData['starts_at'] = trim((string) ($_POST['starts_at'] ?? '')) !== '' ? $_POST['starts_at'] . ' 00:00:00' : null;
    $formData['ends_at'] = trim((string) ($_POST['ends_at'] ?? '')) !== '' ? $_POST['ends_at'] . ' 23:59:59' : null;

    if ($formData['title'] === '') {
        $errors[] = 'Vul een titel in.';
    }

    if ($formData['slug'] === '') {
        $formData['slug'] = SpecialRepository::slugify($formData['title']);
    }

    if ($formData['slug'] === '') {
        $errors[] = 'Vul een geldige URL-naam in (letters, cijfers en koppeltekens).';
    } elseif ($repository->slugExists($formData['slug'], $special !== null ? (int) $special['id'] : null)) {
        $errors[] = 'Deze URL-naam is al in gebruik door een andere special. Kies een andere.';
    }

    if ($formData['starts_at'] !== null && $formData['ends_at'] !== null && $formData['starts_at'] > $formData['ends_at']) {
        $errors[] = 'De startdatum moet vóór de einddatum liggen.';
    }

    /**
     * @return array{0: ?int, 1: bool} [prijs in centen of null, is geldig ingevuld]
     */
    $parsePrice = static function (string $raw, bool $required) use (&$errors): array {
        $raw = trim($raw);

        if ($raw === '') {
            return [null, !$required];
        }

        if (!is_numeric(str_replace(',', '.', $raw))) {
            return [null, false];
        }

        return [(int) round((float) str_replace(',', '.', $raw) * 100), true];
    };

    $variantLabels = $_POST['variant_label'] ?? [];
    $variantPricesNl = $_POST['variant_price_nl'] ?? [];
    $variantPricesEu = $_POST['variant_price_eu'] ?? [];
    $variantPricesWorld = $_POST['variant_price_world'] ?? [];
    $variants = [];
    $variantRows = [];

    foreach ($variantLabels as $index => $label) {
        $label = trim((string) $label);
        $rawNl = trim((string) ($variantPricesNl[$index] ?? ''));
        $rawEu = trim((string) ($variantPricesEu[$index] ?? ''));
        $rawWorld = trim((string) ($variantPricesWorld[$index] ?? ''));

        if ($label === '' && $rawNl === '' && $rawEu === '' && $rawWorld === '') {
            continue;
        }

        $variantRows[] = ['label' => $label, 'price_nl' => $rawNl, 'price_eu' => $rawEu, 'price_world' => $rawWorld];

        [$priceNl, $nlValid] = $parsePrice($rawNl, true);
        [$priceEu, $euValid] = $parsePrice($rawEu, (bool) $formData['ship_eu']);
        [$priceWorld, $worldValid] = $parsePrice($rawWorld, (bool) $formData['ship_world']);

        if ($label === '' || !$nlValid || !$euValid || !$worldValid) {
            $errors[] = 'Vul bij elke prijsvariant een label en geldige prijzen in (NL verplicht, EU/wereld verplicht als je daar naar verzendt).';
            continue;
        }

        $variants[] = [
            'label' => $label,
            'price_nl_cents' => $priceNl,
            'price_eu_cents' => $priceEu,
            'price_world_cents' => $priceWorld,
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
            'slug' => $formData['slug'],
            'description' => $formData['description'] !== '' ? $formData['description'] : null,
            'banner_path' => $bannerPath,
            'active' => (bool) $formData['active'],
            'ship_eu' => (bool) $formData['ship_eu'],
            'ship_world' => (bool) $formData['ship_world'],
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
    $variantRows[] = ['label' => '', 'price_nl' => '', 'price_eu' => '', 'price_world' => ''];
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
    <?php if ($special !== null): ?>
        <a href="<?= h(specialPublicUrl($special)) ?>" target="_blank" rel="noopener">Bekijk special-pagina &rarr;</a>
    <?php endif; ?>
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
            <label for="slug">URL-naam</label>
            <input type="text" id="slug" name="slug" pattern="[a-z0-9-]+" placeholder="bijv. kalender2027" value="<?= h($formData['slug'] ?? '') ?>">
            <p class="hint">Deelbare link: <?= h(Config::publicUrl()) ?>/<span id="slug-preview"><?= h($formData['slug'] !== '' ? $formData['slug'] : '...') ?></span></p>
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

        <h3>Verzendgebied</h3>
        <div class="field">
            <label>
                <input type="checkbox" id="ship_eu" name="ship_eu" value="1" <?= !empty($formData['ship_eu']) ? 'checked' : '' ?> style="width: auto; margin-right: 6px;">
                Verzenden binnen de EU (buiten Nederland)
            </label>
            <label>
                <input type="checkbox" id="ship_world" name="ship_world" value="1" <?= !empty($formData['ship_world']) ? 'checked' : '' ?> style="width: auto; margin-right: 6px;">
                Verzenden wereldwijd (buiten de EU)
            </label>
        </div>

        <h3>Prijsvarianten</h3>
        <p class="hint">Prijs is inclusief verzending, per zone. Vul de EU-/wereldprijs alleen in als je daar ook naar verzendt.</p>
        <div id="variant-rows">
            <?php foreach ($variantRows as $variant): ?>
                <div class="variant-row">
                    <div class="field" style="flex: 2 1 200px;">
                        <label>Label</label>
                        <input type="text" name="variant_label[]" placeholder="bijv. 1 kaart" value="<?= h($variant['label']) ?>">
                    </div>
                    <div class="field" style="flex: 1 1 110px;">
                        <label>NL (€)</label>
                        <input type="text" name="variant_price_nl[]" placeholder="bijv. 25,00" value="<?= h($variant['price_nl']) ?>">
                    </div>
                    <div class="field variant-price-eu" style="flex: 1 1 110px;">
                        <label>EU (€)</label>
                        <input type="text" name="variant_price_eu[]" placeholder="bijv. 30,00" value="<?= h($variant['price_eu']) ?>">
                    </div>
                    <div class="field variant-price-world" style="flex: 1 1 110px;">
                        <label>Wereld (€)</label>
                        <input type="text" name="variant_price_world[]" placeholder="bijv. 35,00" value="<?= h($variant['price_world']) ?>">
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
        <div class="field" style="flex: 1 1 110px;">
            <label>NL (€)</label>
            <input type="text" name="variant_price_nl[]" placeholder="bijv. 25,00">
        </div>
        <div class="field variant-price-eu" style="flex: 1 1 110px;">
            <label>EU (€)</label>
            <input type="text" name="variant_price_eu[]" placeholder="bijv. 30,00">
        </div>
        <div class="field variant-price-world" style="flex: 1 1 110px;">
            <label>Wereld (€)</label>
            <input type="text" name="variant_price_world[]" placeholder="bijv. 35,00">
        </div>
        <button type="button" class="btn btn-secondary" style="width: auto;" onclick="this.closest('.variant-row').remove()">Verwijderen</button>
    </div>
</template>
<script>
document.getElementById('add-variant').addEventListener('click', function () {
    const template = document.getElementById('variant-row-template');
    document.getElementById('variant-rows').appendChild(template.content.cloneNode(true));
    toggleZonePriceColumns();
});

function toggleZonePriceColumns() {
    const shipEu = document.getElementById('ship_eu').checked;
    const shipWorld = document.getElementById('ship_world').checked;
    document.querySelectorAll('.variant-price-eu').forEach(function (el) {
        el.style.display = shipEu ? '' : 'none';
    });
    document.querySelectorAll('.variant-price-world').forEach(function (el) {
        el.style.display = shipWorld ? '' : 'none';
    });
}

document.getElementById('ship_eu').addEventListener('change', toggleZonePriceColumns);
document.getElementById('ship_world').addEventListener('change', toggleZonePriceColumns);
toggleZonePriceColumns();

(function () {
    const titleField = document.getElementById('title');
    const slugField = document.getElementById('slug');
    const slugPreview = document.getElementById('slug-preview');
    let slugTouched = slugField.value.trim() !== '';

    function slugify(value) {
        return value.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    }

    function updatePreview() {
        slugPreview.textContent = slugField.value.trim() !== '' ? slugField.value.trim() : '...';
    }

    slugField.addEventListener('input', function () {
        slugTouched = slugField.value.trim() !== '';
        updatePreview();
    });

    titleField.addEventListener('input', function () {
        if (!slugTouched) {
            slugField.value = slugify(titleField.value);
            updatePreview();
        }
    });
})();
</script>

<?php require __DIR__ . '/../partials/layout-end.php'; ?>
