<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\UserRepository;

Auth::requireAdmin();

$activeSection = 'settings';
$activePage = 'users';
$csrfToken = Csrf::token();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $error = null;
    if (Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === Auth::userId()) {
            $error = 'own_account';
        } else {
            UserRepository::delete($id);
        }
    }
    header('Location: users.php' . ($error !== null ? '?error=' . $error : '?deleted=1'));
    exit;
}

$users = UserRepository::findAll();

$pageTitle = 'Gebruikers';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1>👤 Gebruikers</h1>
    <a href="user-form.php" class="btn" style="width: auto;">+ Gebruiker toevoegen</a>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Gebruiker is aangemaakt.</div>
<?php elseif (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Gebruiker is bijgewerkt.</div>
<?php elseif (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">Gebruiker is verwijderd.</div>
<?php elseif (($_GET['error'] ?? '') === 'own_account'): ?>
    <div class="alert alert-error">Je kunt je eigen account niet verwijderen.</div>
<?php endif; ?>

<div class="card">
    <div class="table-wrapper">
        <table class="orders">
            <thead>
            <tr>
                <th style="width: 180px;">Gebruikersnaam</th>
                <th style="width: 110px;">Beheerder</th>
                <th>Secties</th>
                <th style="width: 80px;">Actief</th>
                <th style="width: 120px;">Aangemaakt</th>
                <th style="width: 76px;">Acties</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $userRow): ?>
                <tr>
                    <td><?= h($userRow['username']) ?></td>
                    <td><?= ((int) $userRow['is_admin'] === 1) ? '✅' : '—' ?></td>
                    <td><?= ((int) $userRow['is_admin'] === 1) ? 'Alle (beheerder)' : h(implode(', ', $userRow['section_names']) ?: '—') ?></td>
                    <td><?= ((int) $userRow['active'] === 1) ? '✅' : '—' ?></td>
                    <td><?= h((new DateTime($userRow['created_at']))->format('d-m-Y')) ?></td>
                    <td>
                        <div class="actions-dropdown">
                            <button type="button" class="icon-btn actions-trigger" title="Acties" aria-label="Acties">⋮</button>
                            <div class="actions-menu">
                                <a href="user-form.php?id=<?= (int) $userRow['id'] ?>">✏️ Bewerken</a>
                                <?php if ((int) $userRow['id'] !== Auth::userId()): ?>
                                    <form method="post" action="users.php"
                                          onsubmit="return confirm('Gebruiker &quot;<?= h($userRow['username']) ?>&quot; verwijderen?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $userRow['id'] ?>">
                                        <button type="submit" class="danger">🗑️ Verwijderen</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
