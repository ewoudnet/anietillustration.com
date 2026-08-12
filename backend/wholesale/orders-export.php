<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\WholesaleOrderRepository;
use App\XlsxWriter;

Auth::requireSection('wholesale');

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'platform_id' => trim((string) ($_GET['platform_id'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
];

$orders = WholesaleOrderRepository::search($filters);

$statusLabels = [
    'open' => 'Open',
    'confirmed' => 'Bevestigd',
    'shipped' => 'Onderweg',
    'delivered' => 'Geleverd',
    'canceled' => 'Geannuleerd',
];

$rows = [
    ['Platform', 'Referentie', 'Shop', 'Datum', 'Totaal', 'Valuta', 'Status'],
];

foreach ($orders as $order) {
    $rows[] = [
        $order['platform_name'],
        $order['external_order_id'],
        $order['shop_name'] ?? '',
        (new DateTimeImmutable($order['placed_at']))->format('d-m-Y H:i'),
        round(((int) $order['total_amount_cents']) / 100, 2),
        $order['currency'],
        $statusLabels[$order['status']] ?? $order['status'],
    ];
}

$writer = new XlsxWriter();
$writer->addSheet('Wholesale-bestellingen', $rows);

$tmpPath = tempnam(sys_get_temp_dir(), 'wholesale_orders_export_');
$writer->save($tmpPath);

$filename = 'wholesale-bestellingen-' . date('Y-m-d') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpPath));

readfile($tmpPath);
unlink($tmpPath);
