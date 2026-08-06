<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\MollieService;
use App\OrderRepository;
use App\OrderValidator;
use App\SpecialRepository;
use Mollie\Api\Exceptions\ApiException;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

function backToFormWithErrors(array $errors, array $old, int $specialId, ?string $slug = null): void
{
    $_SESSION['flash_errors'] = $errors;
    $_SESSION['flash_old'] = $old;
    header('Location: ' . ($slug !== null && $slug !== '' ? $slug : 'index.php?s=' . $specialId));
    exit;
}

$specialId = (int) ($_POST['special_id'] ?? 0);
$specialRepository = new SpecialRepository();
$specialForRedirect = $specialRepository->find($specialId);
$slugForRedirect = $specialForRedirect['slug'] ?? null;

$submittedToken = (string) ($_POST['csrf_token'] ?? '');
$sessionToken = (string) ($_SESSION['form_csrf'] ?? '');
if ($submittedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
    backToFormWithErrors(['Je sessie is verlopen. Probeer het formulier opnieuw te versturen.'], $_POST, $specialId, $slugForRedirect);
}

$special = $specialRepository->findOrderable($specialId);
if ($special === null) {
    backToFormWithErrors(['Deze special is niet (meer) beschikbaar.'], $_POST, $specialId, $slugForRedirect);
}

[$data, $errors] = OrderValidator::validate($_POST, $special);

if (!empty($errors)) {
    backToFormWithErrors($errors, $_POST, $specialId, $slugForRedirect);
}

try {
    $orderRepository = new OrderRepository();
    $order = $orderRepository->create($specialId, $data, $data['variant']);

    $mollie = new MollieService();
    $payment = $mollie->createPaymentForOrder($order);

    $orderRepository->attachMolliePayment((int) $order['id'], $payment->id);

    unset($_SESSION['form_csrf']);

    header('Location: ' . $payment->getCheckoutUrl());
    exit;
} catch (ApiException $e) {
    error_log('Mollie API error: ' . $e->getMessage());
    backToFormWithErrors(['Er ging iets mis bij het starten van de betaling. Probeer het later opnieuw.'], $_POST, $specialId, $slugForRedirect);
} catch (\Throwable $e) {
    error_log('Order processing error: ' . $e->getMessage());
    backToFormWithErrors(['Er ging iets mis bij het verwerken van je bestelling. Probeer het later opnieuw.'], $_POST, $specialId, $slugForRedirect);
}
