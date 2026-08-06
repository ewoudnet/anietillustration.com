<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\ImageUpload;
use App\ProductTypeRepository;
use App\SalesChannelRepository;

Auth::requireAdmin();

$activeSection = 'settings';
$activePage = 'sales-channels';
$csrfToken = Csrf::token();

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$existing = $id !== null ? SalesChannelRepository::find($id) : null;

if ($id !== null && $existing === null) {
    header('Location: sales-channels.php');
    exit;
}

$errors = [];
$values = [
    'name' => $existing['name'] ?? '',
    'abbreviation' => $existing['abbreviation'] ?? '',
    'color' => $existing['color'] ?? '#012b55',
    'sort_order' => (string) ($existing['sort_order'] ?? 0),
    'comments' => $existing['comments'] ?? '',
    'active' => $existing['active'] ?? 1,
];
$selectedProductTypeIds = $existing['product_type_ids'] ?? [];
$logoPath = $existing['logo_path'] ?? null;
$productTypes = ProductTypeRepository::findAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['name'] = trim((string) ($_POST['name'] ?? ''));
    $values['abbreviation'] = strtoupper(trim((string) ($_POST['abbreviation'] ?? '')));
    $values['color'] = trim((string) ($_POST['color'] ?? '#012b55'));
    $values['sort_order'] = trim((string) ($_POST['sort_order'] ?? '0'));
    $values['comments'] = trim((string) ($_POST['comments'] ?? ''));
    $values['active'] = isset($_POST['active']) ? 1 : 0;
    $selectedProductTypeIds = array_map('intval', $_POST['product_type_ids'] ?? []);

    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Je sessie is verlopen. Probeer het opnieuw.';
    }

    if ($values['name'] === '') {
        $errors[] = 'Vul een naam in.';
    } elseif (SalesChannelRepository::nameExists($values['name'], $id)) {
        $errors[] = 'Er bestaat al een sale channel met deze naam.';
    }

    if ($values['abbreviation'] === '') {
        $errors[] = 'Vul een afkorting in (bijv. GRZ).';
    }

    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $values['color'])) {
        $errors[] = 'Kies een geldige kleur.';
    }

    if (!ctype_digit($values['sort_order'])) {
        $errors[] = 'Volgorde moet een geheel getal zijn.';
    }

    if (empty($errors)) {
        try {
            $uploaded = ImageUpload::store($_FILES['logo'] ?? [], 'channels', BO_ASSETS_PATH);
            if ($uploaded !== null) {
                if ($existing !== null) {
                    ImageUpload::delete($existing['logo_path'], BO_ASSETS_PATH);
                }
                $logoPath = $uploaded;
            }
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (empty($errors)) {
        $data = [
            'name' => $values['name'],
            'abbreviation' => $values['abbreviation'],
            'color' => $values['color'],
            'sort_order' => (int) $values['sort_order'],
            'logo_path' => $logoPath,
            'comments' => $values['comments'] !== '' ? $values['comments'] : null,
            'active' => $values['active'],
        ];

        if ($id !== null) {
            SalesChannelRepository::update($id, $data, $selectedProductTypeIds);
            header('Location: sales-channels.php?updated=1');
        } else {
            SalesChannelRepository::create($data, $selectedProductTypeIds);
            header('Location: sales-channels.php?created=1');
        }
        exit;
    }
}

$pageTitle = $existing !== null ? 'Sale channel bewerken' : 'Sale channel toevoegen';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1><?= $existing !== null ? '✏️ Sale channel bewerken' : '+ Sale channel toevoegen' ?></h1>
</div>

<div class="card">
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="sales-channel-form.php<?= $id !== null ? '?id=' . (int) $id : '' ?>" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

        <fieldset>
            <legend>Logo</legend>
            <?php if ($logoPath !== null): ?>
                <img class="table-thumb" style="width: 72px; height: 72px; margin-bottom: 10px;" src="<?= h(BO_ASSETS_URL) ?>/<?= h($logoPath) ?>" alt="">
            <?php endif; ?>
            <div class="field">
                <input type="file" name="logo" accept="image/png,image/jpeg,image/webp">
                <small class="hint">jpg, png of webp, max 5 MB. Laat leeg om het huidige logo te behouden.</small>
            </div>
        </fieldset>

        <fieldset>
            <legend>Basisgegevens</legend>
            <div class="row">
                <div class="field">
                    <label for="name">Naam *</label>
                    <input type="text" id="name" name="name" required value="<?= h($values['name']) ?>">
                </div>
                <div class="field">
                    <label for="abbreviation">Afkorting *</label>
                    <input type="text" id="abbreviation" name="abbreviation" required maxlength="10" value="<?= h($values['abbreviation']) ?>">
                    <small class="hint">Gebruikt als badge in het kaartoverzicht (bijv. GRZ, K2G, WS).</small>
                </div>
                <div class="field" style="flex: 0 0 auto;">
                    <label for="color">Kleur</label>
                    <input type="color" id="color" name="color" value="<?= h($values['color']) ?>" style="width: 60px; padding: 2px; height: 42px;">
                    <small class="hint">Kleur van de badge.</small>
                </div>
                <div class="field">
                    <label for="sort_order">Volgorde</label>
                    <input type="number" id="sort_order" name="sort_order" min="0" value="<?= h($values['sort_order']) ?>">
                    <small class="hint">Bepaalt de vaste volgorde van de badges.</small>
                </div>
            </div>
            <div class="field">
                <label><input type="checkbox" name="active" value="1" <?= ((int) $values['active'] === 1) ? 'checked' : '' ?>> Actief</label>
            </div>
        </fieldset>

        <fieldset>
            <legend>Producttypes die via dit kanaal verkocht worden</legend>
            <div class="checkbox-group">
                <?php foreach ($productTypes as $type): ?>
                    <label>
                        <input type="checkbox" name="product_type_ids[]" value="<?= (int) $type['id'] ?>"
                               <?= in_array((int) $type['id'], $selectedProductTypeIds, true) ? 'checked' : '' ?>>
                        <?= h($type['name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <fieldset>
            <legend>Comments</legend>
            <div class="field">
                <textarea name="comments" rows="4"><?= h($values['comments']) ?></textarea>
                <small class="hint">Bijv. afspraken over voorraad, aanlevering, terugkoppeltermijnen.</small>
            </div>
        </fieldset>

        <button type="submit" class="btn"><?= $existing !== null ? 'Opslaan' : 'Toevoegen' ?></button>
        <a href="sales-channels.php" class="btn btn-secondary">Annuleren</a>
    </form>
</div>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
