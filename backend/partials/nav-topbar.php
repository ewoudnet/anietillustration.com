<?php

declare(strict_types=1);

/**
 * Horizontale sectienavigatie + subnav per sectie, gemodelleerd naar de backoffice's
 * partials/nav.php. Verwacht dat de aanroepende pagina vooraf $activeSection en
 * $activePage (en voor aniet-illustration: $activeProductType) heeft gezet, en dat
 * bootstrap.php (h(), Auth, BACKEND_BASE) al geladen is.
 */

use App\Auth;
use App\ProductTypeRepository;
use App\SectionRepository;

$activeSection ??= null;
$activePage ??= null;
$activeProductType ??= null; // 'cards' | int (products.product_type_id) | null

$sections = SectionRepository::findAll();

// Entry-page per sectieslug - moet in sync blijven met backend/index.php.
$sectionEntryPages = [
    'aniet-illustration' => 'cards.php',
    'specials' => 'index.php',
];
?>
<div class="topbar">
    <div class="topbar-brand">🗂️ Aniet Illustration</div>
    <nav class="topbar-sections">
        <?php foreach ($sections as $section): ?>
            <?php if (isset($sectionEntryPages[$section['slug']]) && Auth::hasSection($section['slug'])): ?>
                <a href="<?= h(BACKEND_BASE) ?>/<?= h($section['slug']) ?>/<?= h($sectionEntryPages[$section['slug']]) ?>"
                   class="<?= $activeSection === $section['slug'] ? 'active' : '' ?>"><?= h($section['icon'] ?? '') ?> <?= h($section['name']) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if (Auth::isAdmin()): ?>
            <a href="<?= h(BACKEND_BASE) ?>/settings/users.php" class="<?= $activeSection === 'settings' ? 'active' : '' ?>">⚙️ Settings</a>
        <?php endif; ?>
    </nav>
    <div class="topbar-user">
        <?= h(Auth::username()) ?> &middot; <a href="<?= h(BACKEND_BASE) ?>/logout.php">Uitloggen</a>
    </div>
</div>
<?php if ($activeSection === 'aniet-illustration'): ?>
    <?php $productTypes = ProductTypeRepository::findAll(); ?>
    <nav class="subnav">
        <?php foreach ($productTypes as $pt): ?>
            <?php
            $isCards = $pt['name'] === 'Kaarten';
            $href = $isCards ? 'cards.php' : 'products.php?type_id=' . (int) $pt['id'];
            $isActiveType = $isCards ? $activeProductType === 'cards' : $activeProductType === (int) $pt['id'];
            ?>
            <a href="<?= h(BACKEND_BASE) ?>/aniet-illustration/<?= h($href) ?>" class="<?= $isActiveType ? 'active' : '' ?>"><?= h($pt['name']) ?></a>
        <?php endforeach; ?>
    </nav>
    <nav class="subnav subnav-secondary">
        <?php if ($activeProductType === 'cards'): ?>
            <a href="<?= h(BACKEND_BASE) ?>/aniet-illustration/cards.php" class="<?= $activePage === 'cards' ? 'active' : '' ?>">Overzicht</a>
            <a href="<?= h(BACKEND_BASE) ?>/aniet-illustration/card-form.php" class="<?= $activePage === 'card-form' ? 'active' : '' ?>">+ Toevoegen</a>
            <a href="<?= h(BACKEND_BASE) ?>/aniet-illustration/order.php" class="<?= $activePage === 'order' ? 'active' : '' ?>">Bestelpagina</a>
        <?php elseif (is_int($activeProductType)): ?>
            <a href="<?= h(BACKEND_BASE) ?>/aniet-illustration/products.php?type_id=<?= $activeProductType ?>" class="<?= $activePage === 'products' ? 'active' : '' ?>">Overzicht</a>
            <a href="<?= h(BACKEND_BASE) ?>/aniet-illustration/product-form.php?type_id=<?= $activeProductType ?>" class="<?= $activePage === 'product-form' ? 'active' : '' ?>">+ Toevoegen</a>
            <a href="<?= h(BACKEND_BASE) ?>/aniet-illustration/product-order.php?type_id=<?= $activeProductType ?>" class="<?= $activePage === 'product-order' ? 'active' : '' ?>">Bestelpagina</a>
        <?php endif; ?>
    </nav>
<?php elseif ($activeSection === 'settings'): ?>
    <nav class="subnav">
        <a href="<?= h(BACKEND_BASE) ?>/settings/users.php" class="<?= $activePage === 'users' ? 'active' : '' ?>">Gebruikers</a>
        <a href="<?= h(BACKEND_BASE) ?>/settings/product-types.php" class="<?= $activePage === 'product-types' ? 'active' : '' ?>">Producttypes</a>
        <a href="<?= h(BACKEND_BASE) ?>/settings/sales-channels.php" class="<?= $activePage === 'sales-channels' ? 'active' : '' ?>">Sale channels</a>
        <a href="<?= h(BACKEND_BASE) ?>/settings/backup.php" class="<?= $activePage === 'backup' ? 'active' : '' ?>">Backup</a>
        <a href="<?= h(BACKEND_BASE) ?>/settings/faire-sync.php" class="<?= $activePage === 'faire-sync' ? 'active' : '' ?>">Faire sync</a>
    </nav>
<?php elseif ($activeSection === 'specials'): ?>
    <nav class="subnav">
        <a href="<?= h(BACKEND_BASE) ?>/specials/index.php" class="<?= $activePage === 'index' ? 'active' : '' ?>">Overzicht</a>
        <a href="<?= h(BACKEND_BASE) ?>/specials/form.php" class="<?= $activePage === 'form' ? 'active' : '' ?>">+ Nieuwe special</a>
        <a href="<?= h(BACKEND_BASE) ?>/specials/orders.php" class="<?= $activePage === 'orders' ? 'active' : '' ?>">Bestellingen</a>
        <a href="<?= h(BACKEND_BASE) ?>/specials/stats.php" class="<?= $activePage === 'stats' ? 'active' : '' ?>">Statistieken</a>
    </nav>
<?php endif; ?>
