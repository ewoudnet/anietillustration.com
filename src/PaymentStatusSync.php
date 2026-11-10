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
            // Webhook en bedankpagina lopen tegelijk binnen; alleen wie de claim wint verstuurt.
            if ($orderRepository->claimConfirmationEmail((int) $order['id'])) {
                $order['confirmation_email_sent_at'] = date('Y-m-d H:i:s');
                try {
                    Mailer::sendOrderConfirmation($order);
                    self::notifyOwner($order);
                } catch (\Throwable $mailError) {
                    $orderRepository->releaseConfirmationEmail((int) $order['id']);
                    $order['confirmation_email_sent_at'] = null;
                    error_log('Bevestigingsmail versturen mislukt voor order ' . $order['order_reference'] . ': ' . $mailError->getMessage());
                }
            }
        }

        return $order;
    }

    /**
     * Meldt de eigenaar een nieuwe (betaalde) bestelling, als dat in Settings aan staat. Een
     * fout hier mag de klantmail/orderverwerking nooit raken.
     *
     * @param array<string,mixed> $order
     */
    private static function notifyOwner(array $order): void
    {
        try {
            $to = SettingsRepository::newOrderNotificationEmail();
            if (SettingsRepository::newOrderNotificationEnabled() && filter_var($to, FILTER_VALIDATE_EMAIL)) {
                Mailer::sendNewOrderNotification($order, $to);
            }
        } catch (\Throwable $e) {
            error_log('Melding nieuwe bestelling mislukt voor order ' . $order['order_reference'] . ': ' . $e->getMessage());
        }
    }
}
