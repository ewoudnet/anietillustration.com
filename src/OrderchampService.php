<?php

declare(strict_types=1);

namespace App;

/**
 * Koppeling met de Orderchamp GraphQL API voor Wholesale.
 *
 * - Endpoint: POST https://api.orderchamp.com/v1/graphql (één endpoint voor
 *   alle queries/mutations, zoals gebruikelijk bij GraphQL).
 * - Authenticatie: Authorization: Bearer <token> - een privé/directe
 *   toegangstoken uit de Orderchamp-shopdashboard ("API"-pagina in de
 *   supplier-backoffice), niet de OAuth-flow (die is voor apps die door
 *   meerdere Orderchamp-accounts geïnstalleerd worden, niet van toepassing
 *   op onze eigen shop).
 * - Schema geverifieerd op 2026-08-12 via de publieke, statisch-gegenereerde
 *   schema-referentie op developers.orderchamp.com (types/Order,
 *   types/OrderStatus, types/OrderProduct, types/Customer, types/Address,
 *   types/CountryCode, queries/orders, authentication, rate-limits) - NIET
 *   live getest (er is nog geen ORDERCHAMP_ACCESS_TOKEN in .env, zie
 *   docs/wholesale.md). Verifieer de eerste echte respons zorgvuldig zodra
 *   het token is ingesteld.
 *
 * Credential hoort in .env (ORDERCHAMP_ACCESS_TOKEN), niet hier.
 */
final class OrderchampService
{
    private const ENDPOINT = 'https://api.orderchamp.com/v1/graphql';
    private const ORDERS_PAGE_LIMIT = 50;

    public static function isConfigured(): bool
    {
        return Config::get('ORDERCHAMP_ACCESS_TOKEN', '') !== '';
    }

    /**
     * Eén pagina orders (nieuw + historisch), cursor-gepagineerd (Relay-stijl
     * `first`/`after`). Gebruikt door WholesaleOrderImporter - roep herhaald
     * aan met de teruggegeven cursor totdat hasNextPage false is.
     *
     * includeCancelled/includeUnconfirmed staan aan: een historische import
     * moet ook geannuleerde en nog-niet-bevestigde orders zien, anders
     * ontbreken ze straks in het orderoverzicht.
     *
     * @return array{orders: array<int, array<string, mixed>>, cursor: ?string, hasNextPage: bool}
     */
    public static function fetchOrdersPage(?string $after = null, ?string $since = null): array
    {
        $query = <<<'GRAPHQL'
            query WholesaleOrders($first: Int, $after: String, $since: DateTime, $includeCancelled: Boolean, $includeUnconfirmed: Boolean) {
                orders(
                    first: $first
                    after: $after
                    since: $since
                    includeCancelled: $includeCancelled
                    includeUnconfirmed: $includeUnconfirmed
                    sort: CREATED_AT_ASC
                ) {
                    nodes {
                        id
                        number
                        reference
                        status
                        currency
                        totalPrice
                        createdAt
                        updatedAt
                        cancelledAt
                        deliveredAt
                        confirmedAt
                        customer {
                            id
                            companyName
                            address {
                                street
                                houseNumber
                                addressLine2
                                city
                                postalCode
                                country
                            }
                        }
                        products {
                            nodes {
                                sku
                                title
                                quantity
                                unitPrice
                            }
                        }
                    }
                    pageInfo {
                        endCursor
                        hasNextPage
                    }
                }
            }
            GRAPHQL;

        $variables = [
            'first' => self::ORDERS_PAGE_LIMIT,
            'after' => $after,
            'since' => $since,
            'includeCancelled' => true,
            'includeUnconfirmed' => true,
        ];

        $response = self::request($query, $variables);
        $connection = $response['data']['orders'] ?? ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]];

        return [
            'orders' => $connection['nodes'] ?? [],
            'cursor' => $connection['pageInfo']['endCursor'] ?? null,
            'hasNextPage' => (bool) ($connection['pageInfo']['hasNextPage'] ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    private static function request(string $query, array $variables): array
    {
        if (!self::isConfigured()) {
            throw new \RuntimeException(
                'Orderchamp-credentials zijn nog niet ingesteld in .env (ORDERCHAMP_ACCESS_TOKEN).'
            );
        }

        $curl = curl_init(self::ENDPOINT);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . Config::get('ORDERCHAMP_ACCESS_TOKEN', ''),
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode(['query' => $query, 'variables' => $variables]),
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            throw new \RuntimeException('Orderchamp API-aanroep mislukt: ' . $error);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Orderchamp API gaf geen geldige JSON terug (status ' . $status . ').');
        }

        if (!empty($decoded['errors'])) {
            $messages = array_map(static fn (array $e): string => (string) ($e['message'] ?? 'onbekende fout'), $decoded['errors']);
            throw new \RuntimeException('Orderchamp API-fout: ' . implode('; ', $messages));
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Orderchamp API gaf status ' . $status . ' terug.');
        }

        return $decoded;
    }
}
