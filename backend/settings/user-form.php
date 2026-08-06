<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\SectionRepository;
use App\UserRepository;

Auth::requireAdmin();

$activeSection = 'settings';
$activePage = 'users';
$csrfToken = Csrf::token();

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$existing = $id !== null ? UserRepository::find($id) : null;

if ($id !== null && $existing === null) {
    header('Location: users.php');
    exit;
}

$errors = [];
$values = [
    'username' => $existing['username'] ?? '',
    'is_admin' => $existing['is_admin'] ?? 0,
    'active' => $existing['active'] ?? 1,
];
$selectedSectionIds = $existing['section_ids'] ?? [];
$sections = SectionRepository::findAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['username'] = trim((string) ($_POST['username'] ?? ''));
    $values['is_admin'] = isset($_POST['is_admin']) ? 1 : 0;
    $values['active'] = isset($_POST['active']) ? 1 : 0;
    $password = (string) ($_POST['password'] ?? '');
    $selectedSectionIds = array_map('intval', $_POST['section_ids'] ?? []);

    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Je sessie is verlopen. Probeer het opnieuw.';
    }

    if ($values['username'] === '') {
        $errors[] = 'Vul een gebruikersnaam in.';
    } elseif (UserRepository::usernameExists($values['username'], $id)) {
        $errors[] = 'Er bestaat al een gebruiker met deze gebruikersnaam.';
    }

    if ($id === null && $password === '') {
        $errors[] = 'Vul een wachtwoord in.';
    }

    if ($password !== '' && strlen($password) < 8) {
        $errors[] = 'Het wachtwoord moet minimaal 8 tekens zijn.';
    }

    if ($id !== null && $id === Auth::userId() && $values['is_admin'] === 0) {
        $errors[] = 'Je kunt jezelf niet degraderen van beheerder.';
    }

    if (empty($errors)) {
        if ($id !== null) {
            UserRepository::update($id, $values['username'], $values['is_admin'] === 1, $values['active'] === 1, $selectedSectionIds);
            if ($password !== '') {
                UserRepository::updatePassword($id, password_hash($password, PASSWORD_DEFAULT));
            }
            header('Location: users.php?updated=1');
        } else {
            UserRepository::create($values['username'], password_hash($password, PASSWORD_DEFAULT), $values['is_admin'] === 1, $selectedSectionIds);
            header('Location: users.php?created=1');
        }
        exit;
    }
}

$pageTitle = $existing !== null ? 'Gebruiker bewerken' : 'Gebruiker toevoegen';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1><?= $existing !== null ? '✏️ Gebruiker bewerken' : '+ Gebruiker toevoegen' ?></h1>
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

    <form method="post" action="user-form.php<?= $id !== null ? '?id=' . (int) $id : '' ?>">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

        <div class="row">
            <div class="field">
                <label for="username">Gebruikersnaam *</label>
                <input type="text" id="username" name="username" required value="<?= h($values['username']) ?>">
            </div>
            <div class="field">
                <label for="password">Wachtwoord <?= $existing !== null ? '' : '*' ?></label>
                <input type="password" id="password" name="password" <?= $existing !== null ? '' : 'required' ?>>
                <small class="hint"><?= $existing !== null ? 'Laat leeg om het huidige wachtwoord te behouden.' : 'Minimaal 8 tekens.' ?></small>
            </div>
        </div>

        <div class="field">
            <label><input type="checkbox" name="is_admin" value="1" <?= ((int) $values['is_admin'] === 1) ? 'checked' : '' ?>> Beheerder</label>
            <small class="hint">Beheerders hebben automatisch toegang tot alle secties en tot Settings (Producttypes/Sale channels/Gebruikers/Backup/Faire sync), ongeacht de sectieselectie hieronder.</small>
        </div>

        <?php if ($existing !== null): ?>
            <div class="field">
                <label><input type="checkbox" name="active" value="1" <?= ((int) $values['active'] === 1) ? 'checked' : '' ?>> Actief</label>
                <small class="hint">Uitvinken blokkeert inloggen zonder het account te verwijderen.</small>
            </div>
        <?php endif; ?>

        <fieldset>
            <legend>Secties</legend>
            <div class="checkbox-group">
                <?php foreach ($sections as $section): ?>
                    <label>
                        <input type="checkbox" name="section_ids[]" value="<?= (int) $section['id'] ?>"
                               <?= in_array((int) $section['id'], $selectedSectionIds, true) ? 'checked' : '' ?>>
                        <?= h($section['icon'] ?? '') ?> <?= h($section['name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <button type="submit" class="btn"><?= $existing !== null ? 'Opslaan' : 'Gebruiker toevoegen' ?></button>
        <a href="users.php" class="btn btn-secondary">Annuleren</a>
    </form>
</div>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
