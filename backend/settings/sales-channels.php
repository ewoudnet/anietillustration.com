<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\ImageUpload;
use App\SalesChannelRepository;

Auth::requireAdmin();

$activeSection = 'settings';
$activePage = 'sales-channels';
$csrfToken = Csrf::token();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $id = (int) ($_POST['id'] ?? 0);
        $existing = SalesChannelRepository::find($id);
        if ($existing !== null) {
            ImageUpload::delete($existing['logo_path'], BO_ASSETS_PATH);
            SalesChannelRepository::delete($id);
        }
    }
    header('Location: sales-channels.php?deleted=1');
    exit;
}

$channels = SalesChannelRepository::findAll();

$pageTitle = 'Sale channels';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1>🛒 Sale channels</h1>
    <a href="sales-channel-form.php" class="btn" style="width: auto;">+ Sale channel toevoegen</a>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Sale channel is aangemaakt.</div>
<?php elseif (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Sale channel is bijgewerkt.</div>
<?php elseif (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">Sale channel is verwijderd.</div>
<?php endif; ?>

<div class="card">
    <?php if ($channels === []): ?>
        <p>Er zijn nog geen sale channels toegevoegd.</p>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="orders">
                <thead>
                <tr>
                    <th style="width: 76px;">Logo</th>
                    <th style="width: 140px;">Naam</th>
                    <th style="width: 100px;">Afkorting</th>
                    <th style="width: 90px;">Volgorde</th>
                    <th style="width: 200px;">Producttypes</th>
                    <th style="width: 76px;">Actief</th>
                    <th>Comments</th>
                    <th style="width: 76px;">Acties</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($channels as $channel): ?>
                    <tr>
                        <td>
                            <?php if (!empty($channel['logo_path'])): ?>
                                <img class="table-thumb table-thumb-card" src="<?= h(BO_ASSETS_URL) ?>/<?= h($channel['logo_path']) ?>" alt="">
                            <?php else: ?>
                                <div class="table-thumb table-thumb-card"></div>
                            <?php endif; ?>
                        </td>
                        <td><?= h($channel['name']) ?></td>
                        <td><span class="badge badge-channel" style="background-color: <?= h($channel['color']) ?>;"><?= h($channel['abbreviation']) ?></span></td>
                        <td><?= (int) $channel['sort_order'] ?></td>
                        <td><?= h(implode(', ', $channel['product_type_names']) ?: '—') ?></td>
                        <td><?= ((int) $channel['active'] === 1) ? '✅' : '—' ?></td>
                        <td><?= h($channel['comments'] ?? '—') ?></td>
                        <td>
                            <div class="actions-dropdown">
                                <button type="button" class="icon-btn actions-trigger" title="Acties" aria-label="Acties">⋮</button>
                                <div class="actions-menu">
                                    <a href="sales-channel-form.php?id=<?= (int) $channel['id'] ?>">✏️ Bewerken</a>
                                    <form method="post" action="sales-channels.php"
                                          onsubmit="return confirm('Sale channel &quot;<?= h($channel['name']) ?>&quot; verwijderen?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $channel['id'] ?>">
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
