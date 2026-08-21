<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Config;
use App\Csrf;
use App\SpecialRepository;

Auth::requireSection('specials');

$csrfToken = Csrf::token();
$specials = (new SpecialRepository())->findAll();

function specialStatusInfo(array $special): array
{
    if ((int) $special['active'] !== 1) {
        return ['label' => 'Concept', 'class' => 'badge-off'];
    }

    $now = new DateTimeImmutable();
    $startsAt = $special['starts_at'] !== null ? new DateTimeImmutable($special['starts_at']) : null;
    $endsAt = $special['ends_at'] !== null ? new DateTimeImmutable($special['ends_at']) : null;

    if ($endsAt !== null && $endsAt < $now) {
        return ['label' => 'Verlopen', 'class' => 'badge-off'];
    }

    if ($startsAt !== null && $startsAt > $now) {
        return ['label' => 'Gepland', 'class' => 'badge-open'];
    }

    return ['label' => 'Lopend', 'class' => 'badge-on'];
}

$pageTitle = 'Specials - Overzicht';
$activeSection = 'specials';
$activePage = 'index';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1>Specials</h1>
    <a href="form.php" class="btn" style="width: auto;">+ Nieuwe special</a>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">Special is aangemaakt.</div>
<?php elseif (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Special is bijgewerkt.</div>
<?php elseif (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">Special is verwijderd.</div>
<?php endif; ?>

<div class="card">
    <?php if (count($specials) === 0): ?>
        <p>Er zijn nog geen specials. <a href="form.php">Maak de eerste aan</a>.</p>
    <?php else: ?>
        <div class="table-wrapper">
            <table>
                <thead>
                <tr>
                    <th>Banner</th>
                    <th>Titel</th>
                    <th>Periode</th>
                    <th>Prijsvarianten</th>
                    <th>Status</th>
                    <th>Acties</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($specials as $special): ?>
                    <?php $status = specialStatusInfo($special); ?>
                    <tr>
                        <td>
                            <?php if ($special['banner_path'] && is_file(SPECIALS_ASSETS_PATH . '/' . $special['banner_path'])): ?>
                                <img class="table-thumb" src="<?= h(Config::appUrl()) ?>/assets/<?= h($special['banner_path']) ?>" alt="">
                            <?php else: ?>
                                <div class="table-thumb"></div>
                            <?php endif; ?>
                        </td>
                        <td><?= h($special['title']) ?></td>
                        <td>
                            <?= $special['starts_at'] ? h((new DateTimeImmutable($special['starts_at']))->format('d-m-Y')) : '—' ?>
                            &ndash;
                            <?= $special['ends_at'] ? h((new DateTimeImmutable($special['ends_at']))->format('d-m-Y')) : '—' ?>
                        </td>
                        <td><?= (int) $special['variant_count'] ?></td>
                        <td>
                            <span class="badge <?= $status['class'] ?>"><?= h($status['label']) ?></span>
                            <?php if ((int) $special['sold_out'] === 1): ?>
                                <span class="badge badge-off">Uitverkocht</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="actions-dropdown">
                                <button type="button" class="icon-btn actions-trigger" title="Acties" aria-label="Acties">⋮</button>
                                <div class="actions-menu">
                                    <a href="<?= h(specialPublicUrl($special)) ?>" target="_blank" rel="noopener">👁️ Bekijk live</a>
                                    <a href="form.php?id=<?= (int) $special['id'] ?>">✏️ Bewerken</a>
                                    <form method="post" action="toggle-active.php">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $special['id'] ?>">
                                        <input type="hidden" name="active" value="<?= (int) $special['active'] === 1 ? '0' : '1' ?>">
                                        <button type="submit"><?= (int) $special['active'] === 1 ? '🔕 Zet uit' : '🔔 Zet aan' ?></button>
                                    </form>
                                    <form method="post" action="toggle-sold-out.php">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $special['id'] ?>">
                                        <input type="hidden" name="sold_out" value="<?= (int) $special['sold_out'] === 1 ? '0' : '1' ?>">
                                        <button type="submit"><?= (int) $special['sold_out'] === 1 ? '📦 Weer op voorraad' : '🚫 Zet op uitverkocht' ?></button>
                                    </form>
                                    <form method="post" action="delete.php"
                                          onsubmit="return confirm('Weet je zeker dat je deze special wilt verwijderen? Dit kan niet ongedaan worden gemaakt.');">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $special['id'] ?>">
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
