<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\AdventOrderRepository;
use App\OrderRepository;
use App\XlsxWriter;

Auth::requireSection('specials');

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'special_id' => trim((string) ($_GET['special_id'] ?? '')),
];
$hasFilters = (bool) array_filter($filters);

$orderRepository = new OrderRepository();
$orders = $hasFilters ? $orderRepository->search($filters) : $orderRepository->findAll();
$adventOrders = (new AdventOrderRepository())->findAll();

$statusLabels = [
    'open' => 'Open',
    'paid' => 'Betaald',
    'failed' => 'Mislukt',
    'expired' => 'Verlopen',
    'canceled' => 'Geannuleerd',
];

$adventTypeLabels = [
    'advent' => 'Adventkalender',
    'kalender2027' => 'Kalender 2027',
];

$rows = [
    ['Referentie', 'Special', 'Variant', 'Datum', 'Voornaam', 'Achternaam', 'E-mail', 'Straat', 'Huisnr', 'Postcode', 'Plaats', 'Land', 'Aantal', 'Totaal (EUR)', 'Status', 'Bevestigingsmail verzonden'],
];

foreach ($orders as $order) {
    $rows[] = [
        $order['order_reference'],
        $order['special_title'] ?? '',
        $order['variant_label'] ?? '',
        (new DateTimeImmutable($order['created_at']))->format('d-m-Y H:i'),
        $order['first_name'],
        $order['last_name'],
        $order['email'],
        $order['street'],
        $order['house_number'],
        $order['postal_code'],
        $order['city'],
        $order['country_code'],
        (int) $order['quantity'],
        round(((int) $order['total_amount_cents']) / 100, 2),
        $statusLabels[$order['status']] ?? $order['status'],
        $order['confirmation_email_sent_at'] !== null ? 'Ja' : 'Nee',
    ];
}

$adventRows = [
    ['Referentie', 'Type', 'Datum', 'Voornaam', 'Achternaam', 'E-mail', 'Straat', 'Huisnr', 'Postcode', 'Plaats', 'Land', 'Aantal', 'Totaal (EUR)', 'Status', 'Bevestigingsmail verzonden'],
];

foreach ($adventOrders as $order) {
    $adventRows[] = [
        $order['order_reference'],
        $adventTypeLabels[$order['product_type']] ?? ucfirst($order['product_type']),
        (new DateTimeImmutable($order['created_at']))->format('d-m-Y H:i'),
        $order['first_name'],
        $order['last_name'],
        $order['email'],
        $order['street'],
        $order['house_number'],
        $order['postal_code'],
        $order['city'],
        $order['country_code'],
        (int) $order['quantity'],
        round(((int) $order['total_amount_cents']) / 100, 2),
        $statusLabels[$order['status']] ?? $order['status'],
        $order['confirmation_email_sent_at'] !== null ? 'Ja' : 'Nee',
    ];
}

$writer = new XlsxWriter();
$writer->addSheet('Nieuw (specials)', $rows);
$writer->addSheet('Oud (advent)', $adventRows);

$tmpPath = tempnam(sys_get_temp_dir(), 'orders_export_');
$writer->save($tmpPath);

$filename = 'bestellingen-' . date('Y-m-d') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpPath));

readfile($tmpPath);
unlink($tmpPath);
