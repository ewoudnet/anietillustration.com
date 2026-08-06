<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\ProductTypeRepository;

Auth::requireAdmin();

$activeSection = 'settings';
$activePage = 'product-types';
$csrfToken = Csrf::token();

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$existing = $id !== null ? ProductTypeRepository::find($id) : null;

if ($id !== null && $existing === null) {
    header('Location: product-types.php');
    exit;
}

$errors = [];
$values = [
    'name' => $existing['name'] ?? '',
    'comments' => $existing['comments'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['name'] = trim((string) ($_POST['name'] ?? ''));
    $values['comments'] = trim((string) ($_POST['comments'] ?? ''));

    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Je sessie is verlopen. Probeer het opnieuw.';
    }

    if ($values['name'] === '') {
        $errors[] = 'Vul een naam in.';
    } elseif (ProductTypeRepository::nameExists($values['name'], $id)) {
        $errors[] = 'Er bestaat al een producttype met deze naam.';
    }

    if (empty($errors)) {
        $comments = $values['comments'] !== '' ? $values['comments'] : null;

        if ($id !== null) {
            ProductTypeRepository::update($id, $values['name'], $comments);
            header('Location: product-types.php?updated=1');
        } else {
            ProductTypeRepository::create($values['name'], $comments);
            header('Location: product-types.php?created=1');
        }
        exit;
    }
}

$pageTitle = $existing !== null ? 'Producttype bewerken' : 'Producttype toevoegen';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1><?= $existing !== null ? '✏️ Producttype bewerken' : '+ Producttype toevoegen' ?></h1>
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

    <form method="post" action="product-type-form.php<?= $id !== null ? '?id=' . (int) $id : '' ?>">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

        <div class="field">
            <label for="name">Naam *</label>
            <input type="text" id="name" name="name" required value="<?= h($values['name']) ?>">
        </div>
        <div class="field">
            <label for="comments">Comments</label>
            <textarea id="comments" name="comments" rows="4"><?= h($values['comments']) ?></textarea>
            <small class="hint">Bijv. bij welke leverancier je dit producttype bestelt, levertijden of andere afspraken.</small>
        </div>

        <button type="submit" class="btn"><?= $existing !== null ? 'Opslaan' : 'Toevoegen' ?></button>
        <a href="product-types.php" class="btn btn-secondary">Annuleren</a>
    </form>
</div>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
