<?php

declare(strict_types=1);

/**
 * Verwacht dat de aanroepende pagina vooraf $pageTitle, $activeSection en $activePage
 * (en voor aniet-illustration: $activeProductType) heeft gezet, en dat bootstrap.php
 * (h(), Auth, BACKEND_BASE) al geladen is. De horizontale sectienavigatie zelf staat in
 * partials/nav-topbar.php.
 */

$pageTitle ??= 'Backend';
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?> - Backend</title>
    <link rel="icon" href="<?= h(BACKEND_BASE) ?>/assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= h(BACKEND_BASE) ?>/assets/css/style.css?v=<?= filemtime(dirname(__DIR__) . '/assets/css/style.css') ?>">
</head>
<body>
<div class="app-shell">
    <main class="main-content">
        <?php require __DIR__ . '/nav-topbar.php'; ?>
