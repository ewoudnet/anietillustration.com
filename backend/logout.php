<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Auth;

Auth::logout();
header('Location: login.php');
