<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Auth;

Auth::requireLogin();

header('Location: specials/index.php');
