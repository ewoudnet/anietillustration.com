<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Config;
use App\Csrf;
use App\SpecialRepository;

Auth::requireLogin();

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
                            <?php if ($special['banner_path']): ?>
                                <img src="<?= h(Config::appUrl()) ?>/assets/<?= h($special['banner_path']) ?>" alt="" style="width: 60px; height: 40px; object-fit: cover; border-radius: 4px;">
                            <?php else: ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                        <td><?= h($special['title']) ?></td>
                        <td>
                            <?= $special['starts_at'] ? h((new DateTimeImmutable($special['starts_at']))->format('d-m-Y')) : '—' ?>
                            &ndash;
                            <?= $special['ends_at'] ? h((new DateTimeImmutable($special['ends_at']))->format('d-m-Y')) : '—' ?>
                        </td>
                        <td><?= (int) $special['variant_count'] ?></td>
                        <td><span class="badge <?= $status['class'] ?>"><?= h($status['label']) ?></span></td>
                        <td class="actions">
                            <a href="form.php?id=<?= (int) $special['id'] ?>">Bewerken</a>
                            <form method="post" action="toggle-active.php" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="id" value="<?= (int) $special['id'] ?>">
                                <input type="hidden" name="active" value="<?= (int) $special['active'] === 1 ? '0' : '1' ?>">
                                <button type="submit" class="link-danger" style="color: var(--color-accent);"><?= (int) $special['active'] === 1 ? 'Zet uit' : 'Zet aan' ?></button>
                            </form>
                            <form method="post" action="delete.php" style="display: inline;"
                                  onsubmit="return confirm('Weet je zeker dat je deze special wilt verwijderen? Dit kan niet ongedaan worden gemaakt.');">
                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="id" value="<?= (int) $special['id'] ?>">
                                <button type="submit" class="link-danger">Verwijderen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
