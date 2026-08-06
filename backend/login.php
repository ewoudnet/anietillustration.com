<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Auth;
use App\Csrf;

if (Auth::isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$csrfToken = Csrf::token();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!Csrf::verify($submittedToken)) {
        $error = 'Je sessie is verlopen. Probeer het opnieuw.';
    } elseif ($username === '' || $password === '') {
        $error = 'Vul een gebruikersnaam en wachtwoord in.';
    } elseif (!Auth::attempt($username, $password)) {
        $error = 'Ongeldige gebruikersnaam of wachtwoord.';
    } else {
        header('Location: index.php');
        exit;
    }
}
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Inloggen - Backend</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="login-page">
    <div class="login-card">
        <h1>🗂️ Aniet Illustration</h1>
        <p class="login-subtitle">Backend</p>

        <?php if ($error !== null): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

            <div class="field">
                <label for="username">Gebruikersnaam</label>
                <input type="text" id="username" name="username" required autofocus value="<?= h($_POST['username'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="password">Wachtwoord</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit" class="btn">Inloggen</button>
        </form>
    </div>
</div>
</body>
</html>
