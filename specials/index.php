<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Countries;
use App\Csrf;
use App\SpecialRepository;

$repository = new SpecialRepository();
$slug = isset($_GET['slug']) ? trim((string) $_GET['slug']) : '';
$specialId = isset($_GET['s']) ? (int) $_GET['s'] : null;

if ($slug !== '') {
    $special = $repository->findOrderableBySlug($slug);
} elseif ($specialId !== null) {
    $special = $repository->findOrderable($specialId);
} else {
    $special = null;
}

$notFound = ($slug !== '' || $specialId !== null) && $special === null;

$errors = $_SESSION['flash_errors'] ?? [];
$old = $_SESSION['flash_old'] ?? [];
unset($_SESSION['flash_errors'], $_SESSION['flash_old']);

$csrfToken = Csrf::token();

$countries = [];
$countryZones = [];
if ($special !== null) {
    $countries = Countries::shippableFor((bool) $special['ship_eu'], (bool) $special['ship_world']);
    asort($countries, SORT_LOCALE_STRING);
    foreach (array_keys($countries) as $code) {
        $countryZones[$code] = Countries::zoneFor($code);
    }
}

function formatEuro(?int $cents): string
{
    return $cents !== null ? number_format($cents / 100, 2, ',', '.') : '-';
}

/**
 * @param array<string, mixed> $item
 */
function specialUrl(array $item): string
{
    return !empty($item['slug']) ? $item['slug'] : 'index.php?s=' . (int) $item['id'];
}
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $special !== null ? h($special['title']) : 'Specials' ?> - Aniet Illustration</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body>
<div class="page">
    <?php if ($special === null): ?>
        <div class="header">
            <h1>🎁 Aniet Illustration</h1>
        </div>
    <?php endif; ?>

    <?php if ($notFound): ?>
        <div class="card">
            <div class="alert alert-error">Deze special is niet (meer) beschikbaar.</div>
            <p><a href="index.php">&larr; Terug naar het overzicht</a></p>
        </div>

    <?php elseif ($special !== null): ?>
        <?php if ($special['banner_path']): ?>
            <img class="banner" src="assets/<?= h($special['banner_path']) ?>" alt="">
        <?php endif; ?>

        <div class="intro-card">
            <h2><?= h($special['title']) ?></h2>
            <?php if ($special['description']): ?>
                <p><?= nl2br(h($special['description'])) ?></p>
            <?php endif; ?>
        </div>

        <div class="card">
            <?php foreach ($errors as $error): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endforeach; ?>

            <?php if (empty($special['variants'])): ?>
                <div class="alert alert-error">Er zijn momenteel geen prijsvarianten beschikbaar voor deze special.</div>
            <?php else: ?>
                <form method="post" action="process-order.php" id="order-form">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="special_id" value="<?= (int) $special['id'] ?>">

                    <fieldset>
                        <legend>Jouw gegevens</legend>
                        <div class="row">
                            <div class="field">
                                <label for="first_name">Voornaam *</label>
                                <input type="text" id="first_name" name="first_name" required value="<?= h($old['first_name'] ?? '') ?>">
                            </div>
                            <div class="field">
                                <label for="last_name">Achternaam *</label>
                                <input type="text" id="last_name" name="last_name" required value="<?= h($old['last_name'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="field">
                            <label for="email">E-mailadres *</label>
                            <input type="email" id="email" name="email" required value="<?= h($old['email'] ?? '') ?>">
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend>Verzendadres</legend>
                        <div class="row">
                            <div class="field" style="flex: 2 1 220px;">
                                <label for="street">Straatnaam *</label>
                                <input type="text" id="street" name="street" required value="<?= h($old['street'] ?? '') ?>">
                            </div>
                            <div class="field" style="flex: 1 1 100px;">
                                <label for="house_number">Huisnr. *</label>
                                <input type="text" id="house_number" name="house_number" required value="<?= h($old['house_number'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="field" style="flex: 1 1 140px;">
                                <label for="postal_code">Postcode *</label>
                                <input type="text" id="postal_code" name="postal_code" required value="<?= h($old['postal_code'] ?? '') ?>">
                            </div>
                            <div class="field" style="flex: 1 1 200px;">
                                <label for="city">Plaats *</label>
                                <input type="text" id="city" name="city" required value="<?= h($old['city'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="field">
                            <label for="country_code">Land *</label>
                            <select id="country_code" name="country_code" required>
                                <?php foreach ($countries as $code => $name): ?>
                                    <option value="<?= h($code) ?>" <?= ($old['country_code'] ?? 'NL') === $code ? 'selected' : '' ?>><?= h($name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend>Bestelling</legend>
                        <div class="field">
                            <label>Keuze *</label>
                            <?php foreach ($special['variants'] as $variant): ?>
                                <label class="variant-option">
                                    <input type="radio" name="price_variant_id" value="<?= (int) $variant['id'] ?>"
                                        <?= (string) ($old['price_variant_id'] ?? '') === (string) $variant['id'] ? 'checked' : '' ?> required>
                                    <?= h($variant['label']) ?> &mdash; €<span class="variant-price" data-variant-id="<?= (int) $variant['id'] ?>"><?= h(formatEuro((int) $variant['price_nl_cents'])) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="field" style="max-width: 160px;">
                            <label for="quantity">Aantal *</label>
                            <input type="number" id="quantity" name="quantity" min="1" max="20" value="<?= h($old['quantity'] ?? '1') ?>" required>
                        </div>

                        <div class="summary" id="summary">
                            <div class="summary-row">
                                <span>Prijs per stuk (<span id="summary-qty">1</span>x)</span>
                                <span>€<span id="summary-unit-price">0,00</span></span>
                            </div>
                            <div class="summary-row total">
                                <span>Totaal</span>
                                <span>€<span id="summary-total">0,00</span></span>
                            </div>
                        </div>
                    </fieldset>

                    <button type="submit" class="btn">Bestellen &amp; betalen</button>
                </form>

                <script>
                (function () {
                    const pricing = {
                        variants: <?= json_encode(array_map(
                            static fn (array $v): array => [
                                'id' => (int) $v['id'],
                                'nl' => (int) $v['price_nl_cents'],
                                'eu' => $v['price_eu_cents'] !== null ? (int) $v['price_eu_cents'] : null,
                                'world' => $v['price_world_cents'] !== null ? (int) $v['price_world_cents'] : null,
                            ],
                            $special['variants']
                        )) ?>,
                        countryZones: <?= json_encode($countryZones) ?>,
                    };

                    function formatCents(cents) {
                        return (cents / 100).toLocaleString('nl-NL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    }

                    function selectedVariant() {
                        const checked = document.querySelector('input[name="price_variant_id"]:checked');
                        if (!checked) {
                            return null;
                        }
                        return pricing.variants.find((v) => v.id === parseInt(checked.value, 10)) || null;
                    }

                    function updatePricing() {
                        const variant = selectedVariant();
                        const countryCode = document.getElementById('country_code').value;
                        const zone = pricing.countryZones[countryCode] || 'nl';
                        const qty = Math.max(1, parseInt(document.getElementById('quantity').value || '1', 10));

                        document.querySelectorAll('.variant-price').forEach(function (el) {
                            const v = pricing.variants.find((item) => item.id === parseInt(el.dataset.variantId, 10));
                            if (v && v[zone] !== null) {
                                el.textContent = formatCents(v[zone]);
                            }
                        });

                        const unitPrice = variant && variant[zone] !== null ? variant[zone] : 0;
                        document.getElementById('summary-qty').textContent = qty;
                        document.getElementById('summary-unit-price').textContent = formatCents(unitPrice);
                        document.getElementById('summary-total').textContent = formatCents(unitPrice * qty);
                    }

                    document.getElementById('order-form').addEventListener('change', updatePricing);
                    document.getElementById('quantity').addEventListener('input', updatePricing);
                    updatePricing();
                })();
                </script>
            <?php endif; ?>
        </div>

        <p class="footer-note">Je gegevens worden alleen gebruikt om je bestelling te verwerken en te verzenden.</p>

    <?php else: ?>
        <?php $active = $repository->findPublicActive(); ?>
        <?php $expired = $repository->findPublicExpired(); ?>

        <h2 class="section-title">Lopende specials</h2>
        <?php if (empty($active)): ?>
            <p>Er zijn momenteel geen lopende specials. Kom snel weer terug!</p>
        <?php else: ?>
            <div class="special-grid">
                <?php foreach ($active as $item): ?>
                    <a class="special-tile" href="<?= h(specialUrl($item)) ?>">
                        <?php if ($item['banner_path']): ?>
                            <img src="assets/<?= h($item['banner_path']) ?>" alt="">
                        <?php endif; ?>
                        <div class="special-tile-title"><?= h($item['title']) ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($expired)): ?>
            <h2 class="section-title">Verlopen specials</h2>
            <div class="special-grid special-grid-expired">
                <?php foreach ($expired as $item): ?>
                    <div class="special-tile special-tile-expired">
                        <?php if ($item['banner_path']): ?>
                            <img src="assets/<?= h($item['banner_path']) ?>" alt="">
                        <?php endif; ?>
                        <div class="special-tile-title"><?= h($item['title']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <p class="footer-note">
        &copy; <?= date('Y') ?> Aniet Illustration &middot;
        <a href="https://www.anietillustration.com" target="_blank" rel="noopener">anietillustration.com</a> &middot;
        <a href="https://www.instagram.com/aniet_illustration" target="_blank" rel="noopener">Instagram</a>
    </p>
</div>
</body>
</html>
