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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        ProductTypeRepository::delete((int) ($_POST['id'] ?? 0));
    }
    header('Location: product-types.php?deleted=1');
    exit;
}

$productTypes = ProductTypeRepository::findAll();

$pageTitle = 'Producttypes';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1>🏷️ Producttypes</h1>
    <a href="product-type-form.php" class="btn" style="width: auto;">+ Producttype toevoegen</a>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Producttype is aangemaakt.</div>
<?php elseif (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Producttype is bijgewerkt.</div>
<?php elseif (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">Producttype is verwijderd.</div>
<?php endif; ?>

<div class="card">
    <?php if ($productTypes === []): ?>
        <p>Er zijn nog geen producttypes toegevoegd.</p>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="orders">
                <thead>
                <tr>
                    <th style="width: 220px;">Naam</th>
                    <th>Comments</th>
                    <th style="width: 76px;">Acties</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($productTypes as $type): ?>
                    <tr>
                        <td><?= h($type['name']) ?></td>
                        <td><?= h($type['comments'] ?? '—') ?></td>
                        <td>
                            <div class="actions-dropdown">
                                <button type="button" class="icon-btn actions-trigger" title="Acties" aria-label="Acties">⋮</button>
                                <div class="actions-menu">
                                    <a href="product-type-form.php?id=<?= (int) $type['id'] ?>">✏️ Bewerken</a>
                                    <form method="post" action="product-types.php"
                                          onsubmit="return confirm('Producttype &quot;<?= h($type['name']) ?>&quot; verwijderen?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $type['id'] ?>">
                                        <button type="submit" class="danger">🗑️ Verwijderen</button>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
