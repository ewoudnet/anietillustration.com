<?php

declare(strict_types=1);

namespace App;

final class PaymentStatusSync
{
    /**
     * Haalt de actuele status bij Mollie op, werkt de order bij en verstuurt (indien nodig)
     * de bevestigingsmail. Idempotent: mag meerdere keren voor dezelfde order aangeroepen worden.
     *
     * @param array<string,mixed> $order
     * @return array<string,mixed> de bijgewerkte order
     */
    public static function sync(array $order, OrderRepository $orderRepository, MollieService $mollie): array
    {
        if ($order['mollie_payment_id'] === null) {
            return $order;
        }

        $payment = $mollie->getPayment($order['mollie_payment_id']);

        $status = match (true) {
            $payment->isPaid() => 'paid',
            $payment->isCanceled() => 'canceled',
            $payment->isExpired() => 'expired',
            $payment->isFailed() => 'failed',
            default => 'open',
        };

        if ($status !== $order['status']) {
            $orderRepository->updateStatus((int) $order['id'], $status);
            $order['status'] = $status;
        }

        if ($status === 'paid' && $order['confirmation_email_sent_at'] === null) {
            try {
                Mailer::sendOrderConfirmation($order);
                $orderRepository->markConfirmationEmailSent((int) $order['id']);
                $order['confirmation_email_sent_at'] = date('Y-m-d H:i:s');
            } catch (\Throwable $mailError) {
                error_log('Bevestigingsmail versturen mislukt voor order ' . $order['order_reference'] . ': ' . $mailError->getMessage());
            }
        }

        return $order;
    }
}
