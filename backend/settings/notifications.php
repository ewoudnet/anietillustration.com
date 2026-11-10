<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\SettingsRepository;

Auth::requireAdmin();

$activeSection = 'settings';
$activePage = 'notifications';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['notify_email'] ?? ''));

    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Je sessie is verlopen. Probeer het opnieuw.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Vul een geldig e-mailadres in (of laat het leeg voor het standaard afzenderadres).';
    } else {
        try {
            SettingsRepository::set(SettingsRepository::NOTIFY_ENABLED, isset($_POST['notify_enabled']) ? '1' : '0');
            SettingsRepository::set(SettingsRepository::NOTIFY_EMAIL, $email);
            header('Location: notifications.php?saved=1');
            exit;
        } catch (\Throwable $e) {
            error_log('Instellingen opslaan mislukt: ' . $e->getMessage());
            $errors[] = 'Opslaan mislukt. Is migratie 014_app_settings.sql al uitgevoerd?';
        }
    }
}

$enabled = SettingsRepository::newOrderNotificationEnabled();
$storedEmail = trim((string) SettingsRepository::get(SettingsRepository::NOTIFY_EMAIL, ''));
$fallbackEmail = SettingsRepository::newOrderNotificationEmail();

$pageTitle = 'Meldingen';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1>🔔 Meldingen</h1>
</div>

<?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success">Instellingen opgeslagen.</div>
<?php endif; ?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= h($error) ?></div>
<?php endforeach; ?>

<div class="card">
    <form method="post" action="notifications.php">
        <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">

        <div class="field">
            <label>
                <input type="checkbox" name="notify_enabled" value="1" <?= $enabled ? 'checked' : '' ?> style="width: auto; margin-right: 6px;">
                Mail mij bij elke nieuwe bestelling (wat is er besteld en door wie)
            </label>
            <p class="hint">De melding wordt verstuurd zodra een bestelling betaald is, tegelijk met de bevestiging naar de klant.</p>
        </div>

        <div class="field">
            <label for="notify_email">Meldingen sturen naar</label>
            <input type="email" id="notify_email" name="notify_email" placeholder="<?= h($fallbackEmail) ?>" value="<?= h($storedEmail) ?>">
            <p class="hint">Laat leeg om het standaard afzenderadres (<?= h($fallbackEmail !== '' ? $fallbackEmail : 'niet ingesteld') ?>) te gebruiken.</p>
        </div>

        <button type="submit" class="btn" style="width: auto;">Opslaan</button>
    </form>
</div>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
