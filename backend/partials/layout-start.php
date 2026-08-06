<?php

declare(strict_types=1);

/**
 * Verwacht dat de aanroepende pagina vooraf $pageTitle, $activeSection en $activePage heeft
 * gezet, en dat bootstrap.php (h(), Auth, BACKEND_BASE) al geladen is. Sectie-items hieronder
 * uitbreiden voor latere fases (Producten, Wholesale-orders, Klanten) - het uitklapbaar menu
 * en de "actief"-markering werken dan automatisch mee.
 */

use App\Auth;

$pageTitle ??= 'Backend';
$activeSection ??= null;
$activePage ??= null;

$menu = [
    [
        'slug' => 'specials',
        'label' => 'Specials',
        'icon' => '🎁',
        'items' => [
            ['page' => 'index', 'label' => 'Overzicht', 'href' => BACKEND_BASE . '/specials/index.php'],
            ['page' => 'form', 'label' => '+ Nieuwe special', 'href' => BACKEND_BASE . '/specials/form.php'],
            ['page' => 'orders', 'label' => 'Bestellingen', 'href' => BACKEND_BASE . '/specials/orders.php'],
        ],
    ],
];
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?> - Backend</title>
    <link rel="stylesheet" href="<?= h(BACKEND_BASE) ?>/assets/css/style.css?v=<?= filemtime(dirname(__DIR__) . '/assets/css/style.css') ?>">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="sidebar-brand">🗂️ Aniet Illustration</div>
        <nav class="sidebar-nav">
            <?php foreach ($menu as $section): ?>
                <details class="nav-group" <?= $activeSection === $section['slug'] ? 'open' : '' ?>>
                    <summary><span class="nav-icon"><?= h($section['icon']) ?></span> <?= h($section['label']) ?></summary>
                    <div class="nav-items">
                        <?php foreach ($section['items'] as $item): ?>
                            <a href="<?= h($item['href']) ?>"
                               class="<?= $activeSection === $section['slug'] && $activePage === $item['page'] ? 'active' : '' ?>"><?= h($item['label']) ?></a>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-user">
            <?= h(Auth::username()) ?><br>
            <a href="<?= h(BACKEND_BASE) ?>/logout.php">Uitloggen</a>
        </div>
    </aside>
    <main class="main-content">
