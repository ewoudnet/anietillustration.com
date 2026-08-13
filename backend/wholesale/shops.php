<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\GeocodingService;
use App\ShopRepository;

Auth::requireSection('wholesale');

$csrfToken = Csrf::token();
$geocodeErrors = [];
$geocodeResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'geocode') {
    if (!Auth::isAdmin()) {
        http_response_code(403);
        echo '403 - Alleen beheerders kunnen coördinaten ophalen.';
        exit;
    }

    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        $geocodeErrors[] = 'Je sessie is verlopen. Probeer het opnieuw.';
    } else {
        $geocodeResult = GeocodingService::geocodePending(10);
    }
}

$shops = ShopRepository::findAllWithOrderStats();
$shopsWithCoordinates = array_values(array_filter(
    $shops,
    static fn (array $s): bool => $s['lat'] !== null && $s['lng'] !== null
));
$missingCoordinates = ShopRepository::countWithoutCoordinates();

$markers = array_map(static function (array $shop): array {
    return [
        'id' => (int) $shop['id'],
        'name' => $shop['name'],
        'lat' => (float) $shop['lat'],
        'lng' => (float) $shop['lng'],
        'color' => $shop['platform_color'],
        'platform' => $shop['platform_name'],
        'orderCount' => (int) $shop['order_count'],
        'totalAmount' => money((int) $shop['total_amount_cents'], $shop['currency']),
        'ordersUrl' => 'orders.php?' . http_build_query(['q' => $shop['name']]),
    ];
}, $shopsWithCoordinates);

$pageTitle = 'Wholesale - Shops';
$activeSection = 'wholesale';
$activePage = 'shops';
require __DIR__ . '/../partials/layout-start.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">

<div class="page-header">
    <h1>Shoplocaties</h1>
</div>

<?php if (count($shops) === 0): ?>
    <div class="card">
        <p>Er zijn nog geen shops ingeladen. Zodra wholesale-orders van Faire/Orderchamp
            worden ingeladen, verschijnen de bijbehorende shops hier automatisch op de kaart.</p>
    </div>
<?php else: ?>
    <?php if (!empty($geocodeErrors)): ?>
        <div class="alert alert-error">
            <ul>
                <?php foreach ($geocodeErrors as $error): ?>
                    <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($geocodeResult !== null): ?>
        <div class="alert alert-success">
            <?= (int) $geocodeResult['located'] ?> van <?= (int) $geocodeResult['attempted'] ?>
            shop(s) op de kaart gezet.
            <?php if ((int) $geocodeResult['remaining'] > 0): ?>
                Nog <?= (int) $geocodeResult['remaining'] ?> te gaan - klik nogmaals.
            <?php else: ?>
                Alle adressen zijn nu afgehandeld.
            <?php endif; ?>
        </div>
        <?php if ($geocodeResult['failed'] !== []): ?>
            <div class="card" style="margin-bottom: 20px;">
                <p class="hint">Niet gelukt in deze ronde:</p>
                <ul class="hint">
                    <?php foreach ($geocodeResult['failed'] as $failure): ?>
                        <li><?= h($failure) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($missingCoordinates > 0): ?>
        <div class="card" style="margin-bottom: 20px;">
            <p class="hint"><?= $missingCoordinates ?> van de <?= count($shops) ?> shops
                hebben nog geen coördinaten en staan daarom niet op de kaart.</p>
            <?php if (Auth::isAdmin()): ?>
                <?php $pending = ShopRepository::countNeedingGeocoding(); ?>
                <?php if ($pending > 0): ?>
                    <p class="hint">
                        Adressen worden omgezet via OpenStreetMap, dat maximaal één
                        adres per seconde toestaat. Daarom gaat dit per 10 tegelijk -
                        nog <?= $pending ?> te gaan, dus ongeveer
                        <?= (int) ceil($pending / 10) ?> klik(ken).
                    </p>
                    <form method="post" action="shops.php">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="action" value="geocode">
                        <button type="submit" class="btn" style="width: auto;">📍 Haal coördinaten op (10 tegelijk)</button>
                    </form>
                <?php else: ?>
                    <p class="hint">
                        Voor deze shops is het adres al een keer geprobeerd maar niet
                        gevonden - meestal een onvolledig of ongebruikelijk adres. Wil
                        je het opnieuw laten proberen, maak dan <code>geocoded_at</code>
                        leeg voor die shops.
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="card" style="margin-bottom: 20px;">
        <div id="shops-map" style="height: 520px; border-radius: var(--radius);"></div>
    </div>

    <div class="card">
        <div class="table-wrapper">
            <table>
                <thead>
                <tr>
                    <th>Shop</th>
                    <th>Platform</th>
                    <th>Aantal orders</th>
                    <th>Totale waarde</th>
                    <th>Acties</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($shops as $shop): ?>
                    <tr>
                        <td><?= h($shop['name']) ?></td>
                        <td><span class="badge badge-channel" style="background: <?= h($shop['platform_color']) ?>;"><?= h($shop['platform_icon'] ?? '') ?> <?= h($shop['platform_name']) ?></span></td>
                        <td><?= (int) $shop['order_count'] ?></td>
                        <td><?= h(money((int) $shop['total_amount_cents'], $shop['currency'])) ?></td>
                        <td><a href="orders.php?<?= h(http_build_query(['q' => $shop['name']])) ?>">Bestellingen</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if ($markers !== []): ?>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
    (function () {
        var markers = <?= json_encode($markers, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
        var map = L.map('shops-map').setView([52.1, 5.3], 7);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            maxZoom: 18
        }).addTo(map);

        var bounds = [];
        markers.forEach(function (shop) {
            var marker = L.circleMarker([shop.lat, shop.lng], {
                radius: 8,
                color: shop.color,
                fillColor: shop.color,
                fillOpacity: 0.85
            }).addTo(map);
            marker.bindPopup(
                '<strong>' + shop.name + '</strong><br>' +
                shop.platform + '<br>' +
                shop.orderCount + ' order(s), ' + shop.totalAmount + '<br>' +
                '<a href="' + shop.ordersUrl + '">Bekijk bestellingen</a>'
            );
            bounds.push([shop.lat, shop.lng]);
        });

        if (bounds.length > 0) {
            map.fitBounds(bounds, { padding: [30, 30], maxZoom: 12 });
        }
    })();
    </script>
<?php endif; ?>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
