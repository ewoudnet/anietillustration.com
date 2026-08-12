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
 * - Schema geverifieerd via de publieke, statisch-gegenereerde
 *   schema-referentie op developers.orderchamp.com (types/Order,
 *   types/OrderStatus, types/OrderProduct, types/Customer, types/Address,
 *   types/CountryCode, types/ProductVariant, queries/orders,
 *   queries/productVariants, authentication, rate-limits).
 * - Orders/klant-velden en productVariants(skus:...) zijn op 2026-08-12 live
 *   geverifieerd tegen de echte API (zie docs/wholesale.md) - twee
 *   eigenaardigheden die niet uit de schema-docs te halen waren staan
 *   gedocumenteerd bij fetchOrdersPage().
 *
 * `inventoryLevelBulkAdjust` (fase D, voorraad SCHRIJVEN) opgezocht op
 * 2026-08-12 via de publieke schema-referentie (developers.orderchamp.com/
 * manage-inventory + developers.orderchamp.com/mutations/
 * inventoryLevelBulkAdjust). Belangrijk: dit is standaard een RELATIEVE
 * aanpassing (`adjustment` telt op/af bij de huidige voorraad) - om een
 * ABSOLUTE waarde te zetten (wat wij nodig hebben, want we schrijven onze
 * eigen `current_stock` terug) moet `action: SET` expliciet mee, anders
 * verdubbelt de voorraad bij elke sync in plaats van gelijk te trekken.
 * Alleen schema-geverifieerd, NOG NIET live getest (dat zou een echte
 * voorraadwijziging bij Orderchamp betekenen) - blijft daarom achter de
 * sync_enabled-schakelaar (zie WholesaleStockSyncService) totdat er bewust,
 * met een enkele lage-impact-SKU, een echte test gedaan wordt.
 *
 * Order-webhook (fase E, zie backend/wholesale/webhook-orderchamp.php) is
 * OPGEZOCHT (developers.orderchamp.com/manage-orders-fulfilment +
 * .../webhooks) maar NOG NIET GEREGISTREERD bij Orderchamp - dat is een
 * wijziging bij een externe partij en dus bewust aan de gebruiker gelaten.
 * Twee dingen zijn daardoor nog onbevestigd (schema zegt het niet expliciet):
 * 1. Orderchamp's docs noemen expliciet "Contact us at support@orderchamp.com
 *    so we can create an API token for you" voor het order-webhook-gebruik -
 *    onduidelijk of ons bestaande ORDERCHAMP_ACCESS_TOKEN (private-app-token,
 *    zelf aangemaakt in de shop-backoffice) hiervoor al volstaat, of dat er
 *    eerst mailcontact nodig is.
 * 2. De signing-secret voor `X-Orderchamp-Signature` is voor een private-app
 *    (geen OAuth-app met apart client_secret) niet expliciet gedocumenteerd -
 *    zie ORDERCHAMP_WEBHOOK_SECRET in .env en de verificatie in
 *    webhook-orderchamp.php, die dus ongetest is totdat er echt een webhook
 *    geregistreerd is.
 * De webhook-payload zelf is wel bevestigd minimaal ({"data":{"order":
 * {"id",...}}}) - vandaar fetchOrderById() om de volledige order op te halen
 * i.p.v. op de payload-inhoud te vertrouwen.
 *
 * Credential hoort in .env (ORDERCHAMP_ACCESS_TOKEN), niet hier.
 */
final class OrderchampService
{
    private const ENDPOINT = 'https://api.orderchamp.com/v1/graphql';
    private const ORDERS_PAGE_LIMIT = 50;
    private const INVENTORY_SKUS_PER_REQUEST = 100;

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
     * Live geverifieerd op 2026-08-12: `since` moet volledig weggelaten worden
     * uit de query als er geen datumfilter is - in tegenstelling tot `after`
     * (dat werkt prima als expliciete null-variabele) geeft Orderchamp bij
     * `since: null` een lege resultatenset terug in plaats van "geen filter"
     * (totalCount 0 i.p.v. de 86 echte orders). Vandaar de query-opbouw hier
     * i.p.v. één vast querytemplate.
     *
     * `products` is zelf ook een Relay-connection en telt daardoor vermenig-
     * vuldigend mee in Orderchamp's query-costlimiet (max. 2000): 50 orders x
     * 100 regels per order kostte al 5100. Bij 30 regels per order (ruim
     * genoeg voor dit soort kaarten/cadeau-bestellingen) blijft de kost op
     * 1600 - als een order toch meer dan 30 regels heeft, wordt dat via
     * `hasNextPage` gedetecteerd (zie WholesaleOrderImporter) i.p.v. stil
     * afgekapt.
     *
     * @return array{orders: array<int, array<string, mixed>>, cursor: ?string, hasNextPage: bool}
     */
    public static function fetchOrdersPage(?string $after = null, ?string $since = null): array
    {
        $sinceArgDefinition = $since !== null ? ', $since: DateTime' : '';
        $sinceArgUsage = $since !== null ? "\n                    since: \$since" : '';

        $query = <<<GRAPHQL
            query WholesaleOrders(\$first: Int, \$after: String, \$includeCancelled: Boolean, \$includeUnconfirmed: Boolean{$sinceArgDefinition}) {
                orders(
                    first: \$first
                    after: \$after
                    includeCancelled: \$includeCancelled
                    includeUnconfirmed: \$includeUnconfirmed{$sinceArgUsage}
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
                        products(first: 30) {
                            nodes {
                                sku
                                title
                                quantity
                                unitPrice
                            }
                            pageInfo {
                                hasNextPage
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
            'includeCancelled' => true,
            'includeUnconfirmed' => true,
        ];
        if ($since !== null) {
            $variables['since'] = $since;
        }

        $response = self::request($query, $variables);
        $connection = $response['data']['orders'] ?? ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]];

        return [
            'orders' => $connection['nodes'] ?? [],
            'cursor' => $connection['pageInfo']['endCursor'] ?? null,
            'hasNextPage' => (bool) ($connection['pageInfo']['hasNextPage'] ?? false),
        ];
    }

    /**
     * Haalt één order op via zijn Orderchamp-id - gebruikt door de
     * order-webhook (fase E), die zelf alleen een minimale payload
     * ({data:{order:{id, number, createdAt, updatedAt}}}) binnenkrijgt en
     * daarna de volledige, actuele order moet opvragen. Zelfde veldenset als
     * fetchOrdersPage() zodat WholesaleOrderImporter::normalizeOrderchampOrder()
     * ongewijzigd voor beide paden werkt.
     *
     * @return array<string, mixed>|null null als de order niet (meer) bestaat
     */
    public static function fetchOrderById(string $id): ?array
    {
        $query = <<<'GRAPHQL'
            query WholesaleOrderById($id: ID!) {
                order(id: $id) {
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
                    products(first: 30) {
                        nodes {
                            sku
                            title
                            quantity
                            unitPrice
                        }
                        pageInfo {
                            hasNextPage
                        }
                    }
                }
            }
            GRAPHQL;

        $response = self::request($query, ['id' => $id]);

        return $response['data']['order'] ?? null;
    }

    /**
     * Haalt de beschikbare voorraad op voor een lijst SKU's - zelfde
     * signatuur/semantiek als FaireService::fetchInventoryBySkus(): SKU's die
     * niet in de respons voorkomen, staan niet in de returnwaarde (niet
     * geplaatst bij Orderchamp onder die SKU).
     *
     * Live geverifieerd op 2026-08-12: `skus`-filter op `productVariants`
     * werkt zoals verwacht, kost per SKU is triviaal (100 SKU's = kost 100,
     * ruim onder de limiet van 2000) - vandaar dezelfde batchgrootte als
     * Faire (100), niet omdat het hier ook nodig was.
     *
     * @param array<int, string> $skus
     * @return array<string, int|null> sku => beschikbare voorraad, of null als Orderchamp
     *                                  geen aantal bijhoudt voor die variant
     */
    public static function fetchInventoryBySkus(array $skus): array
    {
        $skus = array_values(array_unique(array_filter($skus, static fn (string $s): bool => $s !== '')));
        if ($skus === []) {
            return [];
        }

        $query = <<<'GRAPHQL'
            query WholesaleInventory($skus: [String], $first: Int) {
                productVariants(skus: $skus, first: $first) {
                    nodes {
                        sku
                        inventoryQuantity
                    }
                }
            }
            GRAPHQL;

        $result = [];
        foreach (array_chunk($skus, self::INVENTORY_SKUS_PER_REQUEST) as $chunk) {
            $response = self::request($query, ['skus' => $chunk, 'first' => count($chunk)]);

            foreach ($response['data']['productVariants']['nodes'] ?? [] as $node) {
                $result[$node['sku']] = $node['inventoryQuantity'] !== null ? (int) $node['inventoryQuantity'] : null;
            }
        }

        return $result;
    }

    /**
     * Schrijft voorraadaantallen terug naar Orderchamp (fase D). Roep dit
     * alleen aan als het platform sync_enabled=1 heeft - deze methode zelf
     * controleert dat niet, dat is de verantwoordelijkheid van de aanroeper
     * (WholesaleStockSyncService). `action: SET` maakt van `adjustment` een
     * absolute waarde i.p.v. een relatieve op-/aftelling.
     *
     * @param array<string, int> $skuToQuantity sku => nieuwe voorraad
     * @return array<string, int> sku => bevestigde voorraad zoals Orderchamp teruggeeft
     */
    public static function updateInventoryBySkus(array $skuToQuantity): array
    {
        $skuToQuantity = array_filter(
            $skuToQuantity,
            static fn (int $qty, string $sku): bool => $sku !== '',
            ARRAY_FILTER_USE_BOTH
        );
        if ($skuToQuantity === []) {
            return [];
        }

        $query = <<<'GRAPHQL'
            mutation WholesaleInventoryBulkAdjust($input: InventoryLevelBulkAdjustInput!) {
                inventoryLevelBulkAdjust(input: $input) {
                    userErrors {
                        field
                        message
                    }
                    inventoryLevels {
                        quantity
                        productVariant {
                            sku
                        }
                    }
                }
            }
            GRAPHQL;

        $result = [];
        foreach (array_chunk($skuToQuantity, self::INVENTORY_SKUS_PER_REQUEST, true) as $chunk) {
            $inventoryLevels = [];
            foreach ($chunk as $sku => $qty) {
                $inventoryLevels[] = ['sku' => $sku, 'adjustment' => $qty, 'action' => 'SET'];
            }

            $response = self::request($query, ['input' => ['inventoryLevels' => $inventoryLevels]]);
            $payload = $response['data']['inventoryLevelBulkAdjust'] ?? ['userErrors' => [], 'inventoryLevels' => []];

            if (!empty($payload['userErrors'])) {
                $messages = array_map(
                    static fn (array $e): string => (string) ($e['message'] ?? 'onbekende fout'),
                    $payload['userErrors']
                );
                throw new \RuntimeException('Orderchamp voorraad-update gaf een fout terug: ' . implode('; ', $messages));
            }

            foreach ($payload['inventoryLevels'] ?? [] as $level) {
                $sku = $level['productVariant']['sku'] ?? null;
                if ($sku !== null) {
                    $result[$sku] = (int) $level['quantity'];
                }
            }
        }

        return $result;
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
