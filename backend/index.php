<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Auth;
use App\SectionRepository;

Auth::requireLogin();

/**
 * Entry-page per sectieslug, zelfde koppeling als in partials/nav-topbar.php.
 */
const SECTION_ENTRY_PAGES = [
    'aniet-illustration' => 'aniet-illustration/cards.php',
    'specials' => 'specials/index.php',
];

if (Auth::isAdmin()) {
    header('Location: ' . SECTION_ENTRY_PAGES['aniet-illustration']);
    exit;
}

foreach (SectionRepository::findAll() as $section) {
    if (Auth::hasSection($section['slug']) && isset(SECTION_ENTRY_PAGES[$section['slug']])) {
        header('Location: ' . SECTION_ENTRY_PAGES[$section['slug']]);
        exit;
    }
}

header('Location: login.php');
