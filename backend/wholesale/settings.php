<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Config;
use App\Csrf;
use App\FaireService;
use App\OrderchampService;
use App\WholesalePlatformRepository;

Auth::requireSection('wholesale');

$csrfToken = Csrf::token();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_sync') {
    if (!Auth::isAdmin()) {
        http_response_code(403);
        echo '403 - Alleen beheerders kunnen synchronisatie aan/uit zetten.';
        exit;
    }

    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Je sessie is verlopen. Probeer het opnieuw.';
    } else {
        $platformId = (int) ($_POST['platform_id'] ?? 0);
        $enabled = (bool) ($_POST['enabled'] ?? false);
        if ($platformId > 0) {
            WholesalePlatformRepository::setSyncEnabled($platformId, $enabled);
            header('Location: settings.php?updated=1');
            exit;
        }
    }
}

$platforms = WholesalePlatformRepository::findAll();
$configuredCheck = [
    'faire' => FaireService::isConfigured(),
    'orderchamp' => OrderchampService::isConfigured(),
];

$pageTitle = 'Wholesale - Instellingen';
$activeSection = 'wholesale';
$activePage = 'settings';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1>Wholesale-instellingen</h1>
</div>

<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Instelling is bijgewerkt.</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= h($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php foreach ($platforms as $platform): ?>
    <div class="card" style="margin-bottom: 20px;">
        <h3 style="margin-top: 0;"><?= h($platform['icon'] ?? '') ?> <?= h($platform['name']) ?></h3>
        <p>
            API-credential:
            <?php if ($configuredCheck[$platform['code']] ?? false): ?>
                <span class="badge badge-on">Ingesteld in .env</span>
            <?php else: ?>
                <span class="badge badge-off">Nog niet ingesteld in .env</span>
            <?php endif; ?>
        </p>
        <p class="hint">
            Zolang synchronisatie hier op "Uit" staat, wordt er alleen gelezen en
            gelogd - er wordt nooit voorraad teruggeschreven naar dit platform.
            Pas aanzetten zodra alle producten hier correct op geplaatst staan.
        </p>
        <?php if (Auth::isAdmin()): ?>
            <form method="post" action="settings.php">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="toggle_sync">
                <input type="hidden" name="platform_id" value="<?= (int) $platform['id'] ?>">
                <input type="hidden" name="enabled" value="<?= (int) $platform['sync_enabled'] === 1 ? '0' : '1' ?>">
                <button type="submit" class="<?= (int) $platform['sync_enabled'] === 1 ? 'btn btn-secondary' : 'btn' ?>" style="width: auto;">
                    <?= (int) $platform['sync_enabled'] === 1 ? '🔕 Zet synchronisatie uit' : '🔔 Zet synchronisatie live' ?>
                </button>
            </form>
        <?php else: ?>
            <p><span class="badge <?= (int) $platform['sync_enabled'] === 1 ? 'badge-on' : 'badge-off' ?>"><?= (int) $platform['sync_enabled'] === 1 ? 'Live' : 'Uit' ?></span> (alleen beheerders kunnen dit wijzigen)</p>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
