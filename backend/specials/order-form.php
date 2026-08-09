<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Countries;
use App\Csrf;
use App\OrderRepository;
use App\SpecialRepository;

Auth::requireSection('specials');

$orderRepository = new OrderRepository();
$id = isset($_GET['id']) ? (int) $_GET['id'] : (isset($_POST['id']) ? (int) $_POST['id'] : 0);
$order = $id > 0 ? $orderRepository->findById($id) : null;

if ($order === null) {
    header('Location: orders.php');
    exit;
}

$specials = (new SpecialRepository())->findAll();

$statusLabels = [
    'open' => 'Open',
    'paid' => 'Betaald',
    'failed' => 'Mislukt',
    'expired' => 'Verlopen',
    'canceled' => 'Geannuleerd',
];

function formatPriceInput(int $cents): string
{
    return number_format($cents / 100, 2, '.', '');
}

$formData = [
    'special_id' => $order['special_id'] !== null ? (string) $order['special_id'] : '',
    'variant_label' => $order['variant_label'] ?? '',
    'first_name' => $order['first_name'],
    'last_name' => $order['last_name'],
    'email' => $order['email'],
    'street' => $order['street'],
    'house_number' => $order['house_number'],
    'postal_code' => $order['postal_code'],
    'city' => $order['city'],
    'country_code' => $order['country_code'],
    'quantity' => (string) $order['quantity'],
    'unit_price' => formatPriceInput((int) $order['unit_price_cents']),
    'status' => $order['status'],
    'notes' => $order['notes'] ?? '',
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Je sessie is verlopen. Probeer het opnieuw.';
    }

    $formData['special_id'] = trim((string) ($_POST['special_id'] ?? ''));
    $formData['variant_label'] = trim((string) ($_POST['variant_label'] ?? ''));
    $formData['first_name'] = trim((string) ($_POST['first_name'] ?? ''));
    $formData['last_name'] = trim((string) ($_POST['last_name'] ?? ''));
    $formData['email'] = trim((string) ($_POST['email'] ?? ''));
    $formData['street'] = trim((string) ($_POST['street'] ?? ''));
    $formData['house_number'] = trim((string) ($_POST['house_number'] ?? ''));
    $formData['postal_code'] = trim((string) ($_POST['postal_code'] ?? ''));
    $formData['city'] = trim((string) ($_POST['city'] ?? ''));
    $formData['country_code'] = strtoupper(trim((string) ($_POST['country_code'] ?? '')));
    $formData['quantity'] = trim((string) ($_POST['quantity'] ?? ''));
    $formData['unit_price'] = trim((string) ($_POST['unit_price'] ?? ''));
    $formData['status'] = trim((string) ($_POST['status'] ?? ''));
    $formData['notes'] = trim((string) ($_POST['notes'] ?? ''));

    if ($formData['first_name'] === '' || mb_strlen($formData['first_name']) > 100) {
        $errors[] = 'Vul een geldige voornaam in.';
    }
    if ($formData['last_name'] === '' || mb_strlen($formData['last_name']) > 100) {
        $errors[] = 'Vul een geldige achternaam in.';
    }
    if ($formData['email'] === '' || !filter_var($formData['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($formData['email']) > 190) {
        $errors[] = 'Vul een geldig e-mailadres in.';
    }
    if ($formData['street'] === '' || mb_strlen($formData['street']) > 150) {
        $errors[] = 'Vul een geldige straatnaam in.';
    }
    if ($formData['house_number'] === '' || mb_strlen($formData['house_number']) > 20) {
        $errors[] = 'Vul een geldig huisnummer in.';
    }
    if ($formData['postal_code'] === '' || mb_strlen($formData['postal_code']) > 20) {
        $errors[] = 'Vul een geldige postcode in.';
    }
    if ($formData['city'] === '' || mb_strlen($formData['city']) > 100) {
        $errors[] = 'Vul een geldige plaats in.';
    }
    if (!Countries::isValid($formData['country_code'])) {
        $errors[] = 'Kies een geldig land.';
    }
    if (!array_key_exists($formData['status'], $statusLabels)) {
        $errors[] = 'Kies een geldige status.';
    }

    $specialId = $formData['special_id'] !== '' ? (int) $formData['special_id'] : null;
    if ($specialId !== null && !in_array($specialId, array_map(static fn (array $s) => (int) $s['id'], $specials), true)) {
        $errors[] = 'Kies een geldige special.';
    }

    $quantity = filter_var($formData['quantity'], FILTER_VALIDATE_INT);
    if ($quantity === false || $quantity < 1 || $quantity > 100) {
        $errors[] = 'Vul een geldig aantal in (1-100).';
        $quantity = 1;
    }

    $unitPriceRaw = str_replace(',', '.', $formData['unit_price']);
    if (!is_numeric($unitPriceRaw) || (float) $unitPriceRaw < 0) {
        $errors[] = 'Vul een geldige prijs per stuk in.';
        $unitPriceCents = 0;
    } else {
        $unitPriceCents = (int) round((float) $unitPriceRaw * 100);
    }

    if (empty($errors)) {
        $orderRepository->update((int) $order['id'], [
            'specialId' => $specialId,
            'variantLabel' => $formData['variant_label'] !== '' ? $formData['variant_label'] : null,
            'firstName' => $formData['first_name'],
            'lastName' => $formData['last_name'],
            'street' => $formData['street'],
            'houseNumber' => $formData['house_number'],
            'postalCode' => $formData['postal_code'],
            'city' => $formData['city'],
            'countryCode' => $formData['country_code'],
            'email' => $formData['email'],
            'quantity' => $quantity,
            'unitPriceCents' => $unitPriceCents,
            'status' => $formData['status'],
            'notes' => $formData['notes'] !== '' ? $formData['notes'] : null,
        ]);

        header('Location: orders.php?updated=' . (int) $order['id']);
        exit;
    }
}

$pageTitle = 'Bestelling bewerken';
$activeSection = 'specials';
$activePage = 'orders';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1><?= h($pageTitle) ?> &mdash; <?= h($order['order_reference']) ?></h1>
</div>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= h($error) ?></div>
<?php endforeach; ?>

<div class="card">
    <form method="post" action="order-form.php?id=<?= (int) $order['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">

        <h3>Product</h3>
        <div class="row">
            <div class="field" style="flex: 1 1 240px;">
                <label for="special_id">Special</label>
                <select id="special_id" name="special_id">
                    <option value="">&mdash; Geen &mdash;</option>
                    <?php foreach ($specials as $special): ?>
                        <option value="<?= (int) $special['id'] ?>" <?= $formData['special_id'] === (string) $special['id'] ? 'selected' : '' ?>><?= h($special['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="flex: 1 1 200px;">
                <label for="variant_label">Variant (vrije tekst)</label>
                <input type="text" id="variant_label" name="variant_label" value="<?= h($formData['variant_label']) ?>">
            </div>
        </div>

        <h3>Klantgegevens</h3>
        <div class="row">
            <div class="field">
                <label for="first_name">Voornaam</label>
                <input type="text" id="first_name" name="first_name" required value="<?= h($formData['first_name']) ?>">
            </div>
            <div class="field">
                <label for="last_name">Achternaam</label>
                <input type="text" id="last_name" name="last_name" required value="<?= h($formData['last_name']) ?>">
            </div>
        </div>
        <div class="field">
            <label for="email">E-mailadres</label>
            <input type="email" id="email" name="email" required value="<?= h($formData['email']) ?>">
        </div>

        <h3>Verzendadres</h3>
        <div class="row">
            <div class="field" style="flex: 2 1 220px;">
                <label for="street">Straatnaam</label>
                <input type="text" id="street" name="street" required value="<?= h($formData['street']) ?>">
            </div>
            <div class="field" style="flex: 1 1 100px;">
                <label for="house_number">Huisnr.</label>
                <input type="text" id="house_number" name="house_number" required value="<?= h($formData['house_number']) ?>">
            </div>
        </div>
        <div class="row">
            <div class="field" style="flex: 1 1 140px;">
                <label for="postal_code">Postcode</label>
                <input type="text" id="postal_code" name="postal_code" required value="<?= h($formData['postal_code']) ?>">
            </div>
            <div class="field" style="flex: 1 1 200px;">
                <label for="city">Plaats</label>
                <input type="text" id="city" name="city" required value="<?= h($formData['city']) ?>">
            </div>
        </div>
        <div class="field">
            <label for="country_code">Land</label>
            <select id="country_code" name="country_code" required>
                <?php foreach (Countries::ALL as $code => $name): ?>
                    <option value="<?= h($code) ?>" <?= $formData['country_code'] === $code ? 'selected' : '' ?>><?= h($name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <h3>Bestelling &amp; betaling</h3>
        <div class="row">
            <div class="field" style="flex: 1 1 120px;">
                <label for="quantity">Aantal</label>
                <input type="number" id="quantity" name="quantity" min="1" max="100" required value="<?= h($formData['quantity']) ?>">
            </div>
            <div class="field" style="flex: 1 1 140px;">
                <label for="unit_price">Prijs per stuk (€)</label>
                <input type="text" id="unit_price" name="unit_price" required value="<?= h($formData['unit_price']) ?>">
            </div>
            <div class="field" style="flex: 1 1 160px;">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <?php foreach ($statusLabels as $value => $label): ?>
                        <option value="<?= h($value) ?>" <?= $formData['status'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="field">
            <label for="notes">Notities</label>
            <textarea id="notes" name="notes" rows="3"><?= h($formData['notes']) ?></textarea>
        </div>

        <p class="hint">
            Referentie: <?= h($order['order_reference']) ?> &middot;
            Aangemaakt: <?= h((new DateTimeImmutable($order['created_at']))->format('d-m-Y H:i')) ?> &middot;
            Bron: <?= h($order['source']) ?>
        </p>

        <button type="submit" class="btn" style="width: auto;">Opslaan</button>
        &nbsp;<a href="orders.php">Annuleren</a>
    </form>
</div>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
