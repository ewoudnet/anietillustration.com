<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\FaireService;
use App\OrderchampService;
use App\WholesaleOrderImporter;

Auth::requireSection('wholesale');

$csrfToken = Csrf::token();
$errors = [];
$result = null;
$resultPlatform = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::isAdmin()) {
        http_response_code(403);
        echo '403 - Alleen beheerders kunnen de import starten.';
        exit;
    }

    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Je sessie is verlopen. Probeer het opnieuw.';
    } else {
        $resultPlatform = (string) ($_POST['platform'] ?? '');
        $cursor = trim((string) ($_POST['cursor'] ?? '')) ?: null;
        $since = trim((string) ($_POST['since'] ?? '')) ?: null;

        try {
            if ($resultPlatform === 'faire') {
                if (!FaireService::isConfigured()) {
                    throw new \RuntimeException('Faire-credentials zijn nog niet ingesteld in .env (FAIRE_ACCESS_TOKEN).');
                }
                $result = WholesaleOrderImporter::importFairePage($cursor, $since !== null ? $since . 'T00:00:00Z' : null);
            } elseif ($resultPlatform === 'orderchamp') {
                if (!OrderchampService::isConfigured()) {
                    throw new \RuntimeException('Orderchamp-credentials zijn nog niet ingesteld in .env (ORDERCHAMP_ACCESS_TOKEN).');
                }
                $result = WholesaleOrderImporter::importOrderchampPage($cursor, $since !== null ? $since . 'T00:00:00Z' : null);
            } else {
                $errors[] = 'Onbekend platform.';
            }
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$pageTitle = 'Wholesale - Historische import';
$activeSection = 'wholesale';
$activePage = 'import';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1>Historische import</h1>
</div>

<div class="card" style="margin-bottom: 20px;">
    <p class="hint">
        Haalt bestaande orders op bij Faire en/of Orderchamp en zet ze in het
        wholesale-orderoverzicht (incl. shops). <strong>Dit past nooit de
        voorraad aan</strong> (`products.current_stock`/`cards.current_stock`
        blijven ongewijzigd) - dat volgt in een latere bouwfase. Elke klik
        haalt één pagina (max. 50 orders) op; bij veel historie klik je
        "Volgende batch" net zo vaak als nodig. Herhaald importeren van
        dezelfde order overschrijft die order gewoon opnieuw (idempotent).
    </p>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= h($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($result !== null): ?>
    <div class="card" style="margin-bottom: 20px;">
        <h3 style="margin-top: 0;">Resultaat (<?= h($resultPlatform) ?>)</h3>
        <div class="stat-grid">
            <div class="stat-tile">
                <div class="value"><?= (int) $result['imported'] ?></div>
                <div class="label">Orders in deze batch</div>
            </div>
            <div class="stat-tile">
                <div class="value"><?= count($result['unmatchedSkus']) ?></div>
                <div class="label">Niet-gematchte SKU's in deze batch</div>
            </div>
        </div>
        <?php if ($result['unmatchedSkus'] !== []): ?>
            <p class="hint">Niet gevonden in producten/kaarten: <?= h(implode(', ', $result['unmatchedSkus'])) ?></p>
        <?php endif; ?>
        <?php if ($result['done']): ?>
            <div class="alert alert-success">Klaar - er zijn geen orders meer om op te halen voor <?= h($resultPlatform) ?>.</div>
        <?php else: ?>
            <form method="post" action="import.php">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="platform" value="<?= h($resultPlatform) ?>">
                <input type="hidden" name="cursor" value="<?= h($result['nextCursor'] ?? '') ?>">
                <input type="hidden" name="since" value="<?= h($_POST['since'] ?? '') ?>">
                <button type="submit" class="btn" style="width: auto;">Volgende batch importeren &raquo;</button>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="row">
    <div class="card" style="flex: 1 1 320px;">
        <h3 style="margin-top: 0;">🟣 Faire</h3>
        <?php if (!FaireService::isConfigured()): ?>
            <p class="hint">Niet ingesteld in .env.</p>
        <?php elseif (Auth::isAdmin()): ?>
            <form method="post" action="import.php">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="platform" value="faire">
                <div class="field">
                    <label for="since_faire">Alleen orders vanaf (optioneel)</label>
                    <input type="date" id="since_faire" name="since">
                </div>
                <button type="submit" class="btn" style="width: auto;">Start import</button>
            </form>
        <?php else: ?>
            <p class="hint">Alleen beheerders kunnen de import starten.</p>
        <?php endif; ?>
    </div>
    <div class="card" style="flex: 1 1 320px;">
        <h3 style="margin-top: 0;">🟠 Orderchamp</h3>
        <?php if (!OrderchampService::isConfigured()): ?>
            <p class="hint">Niet ingesteld in .env.</p>
        <?php elseif (Auth::isAdmin()): ?>
            <form method="post" action="import.php">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="platform" value="orderchamp">
                <div class="field">
                    <label for="since_orderchamp">Alleen orders vanaf (optioneel)</label>
                    <input type="date" id="since_orderchamp" name="since">
                </div>
                <button type="submit" class="btn" style="width: auto;">Start import</button>
            </form>
        <?php else: ?>
            <p class="hint">Alleen beheerders kunnen de import starten.</p>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
