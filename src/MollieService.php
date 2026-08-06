<?php

declare(strict_types=1);

namespace App;

use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment;

final class MollieService
{
    private MollieApiClient $client;

    public function __construct()
    {
        $this->client = new MollieApiClient();
        $this->client->setApiKey(Config::get('MOLLIE_API_KEY', ''));
    }

    /**
     * @param array<string,mixed> $order
     * @throws ApiException
     */
    public function createPaymentForOrder(array $order): Payment
    {
        return $this->client->payments->create([
            'amount' => [
                'currency' => $order['currency'],
                'value' => number_format($order['total_amount_cents'] / 100, 2, '.', ''),
            ],
            'description' => sprintf('Bestelling %s - %s', $order['order_reference'], $order['variant_label'] ?? 'Special'),
            'redirectUrl' => Config::appUrl() . '/success.php?order=' . urlencode($order['order_reference']),
            'webhookUrl' => Config::appUrl() . '/webhook.php',
            'metadata' => [
                'order_id' => (string) $order['id'],
                'order_reference' => $order['order_reference'],
            ],
        ]);
    }

    /**
     * @throws ApiException
     */
    public function getPayment(string $paymentId): Payment
    {
        return $this->client->payments->get($paymentId);
    }
}
