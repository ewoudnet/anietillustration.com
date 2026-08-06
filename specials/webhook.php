<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\MollieService;
use App\OrderRepository;
use App\PaymentStatusSync;
use Mollie\Api\Exceptions\ApiException;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$paymentId = (string) ($_POST['id'] ?? '');

if ($paymentId === '') {
    http_response_code(400);
    exit;
}

try {
    $orderRepository = new OrderRepository();
    $order = $orderRepository->findByMolliePaymentId($paymentId);

    if ($order === null) {
        error_log("Webhook: onbekend Mollie payment id {$paymentId}");
        http_response_code(404);
        exit;
    }

    PaymentStatusSync::sync($order, $orderRepository, new MollieService());

    http_response_code(200);
} catch (ApiException $e) {
    error_log('Mollie API fout in webhook: ' . $e->getMessage());
    http_response_code(500);
} catch (\Throwable $e) {
    error_log('Onverwachte fout in webhook: ' . $e->getMessage());
    http_response_code(500);
}
