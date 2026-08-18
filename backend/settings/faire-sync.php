<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;

Auth::requireAdmin();

$activeSection = 'settings';
$activePage = 'faire-sync';

$pageTitle = 'Faire sync';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1>🔄 Faire sync</h1>
</div>

<div class="card" style="margin-bottom: 20px;">
    <div class="alert alert-error">
        <strong>Uitgezet (2026-08-18).</strong> Deze tool overschreef "huidige voorraad" hard met
        Faire's "beschikbaar"-aantal (dus al verminderd met toegewezen open bestellingen),
        volledig los van en ongelogd naast de nieuwe Wholesale-sync - dit heeft op 17-18 augustus
        2026 tot grootschalige voorraadcorruptie geleid. Gebruik voor voorraadsynchronisatie met
        Faire de <a href="../wholesale/sku-comparison.php">Wholesale-sectie</a>.
    </div>
</div>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
