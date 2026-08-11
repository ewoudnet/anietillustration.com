<?php

declare(strict_types=1);

namespace App;

/**
 * Koppeling met de Faire External API (v2) voor voorraadsynchronisatie van Wholesale
 * (op dit moment uitsluitend Faire). Vult het handmatige `current_stock`-veld op kaarten
 * en producten (zie CardRepository/ProductRepository) automatisch aan op basis van SKU.
 *
 * Endpoint en response-structuur geverifieerd op 30-07-2026 door de ingebedde OpenAPI-spec
 * van developers.faire.com/docs rechtstreeks uit te lezen (het "apiDescriptionDocument"-
 * attribuut op het <elements-api>-element) - dus niet uit documentatietekst afgeleid. De
 * OpenAPI-spec beschrijft het generieke OAuth-schema (X-FAIRE-APP-CREDENTIALS +
 * X-FAIRE-OAUTH-ACCESS-TOKEN), maar dat gaf op 11-08-2026 een 401 terug. De token die Faire's
 * integrations.support@faire.com voor deze eigen/custom integratie (zonder tussenpartij)
 * afgeeft, werkt met het simpelere enkele-header-schema hieronder (zelf getest, 200 OK) - dat
 * is dus het schema voor dit type token, niet het OAuth-schema uit de spec.
 *
 * - Base URL (production): https://www.faire.com/external-api/v2
 * - Endpoint: GET /product-inventory/by-skus?skus=SKU1,SKU2,... (max. SKUS_PER_REQUEST per
 *   aanroep; bij meer wordt automatisch in batches opgesplitst). De oudere
 *   /products/variants/inventory-levels-by-skus is deprecated.
 * - Authenticatie: X-FAIRE-ACCESS-TOKEN: <access token> (enkele header, geen
 *   X-FAIRE-APP-CREDENTIALS nodig).
 * - Response: {"inventories": {"<sku>": {"available_quantity": {"type": "QUANTITY"|"UNTRACKED", "quantity": 42}, ...}, ...}}
 *   SKU's die niet in de response voorkomen, bestaan niet (meer) bij Faire onder die SKU.
 *   "UNTRACKED" betekent dat Faire geen voorraadaantal bijhoudt voor die variant - dan is
 *   er geen bruikbaar getal om over te nemen.
 *
 * Credential hoort in .env (FAIRE_ACCESS_TOKEN), niet hier.
 */
final class FaireService
{
    private const BASE_URL = 'https://www.faire.com/external-api/v2';
    private const SKUS_PER_REQUEST = 100;

    public static function isConfigured(): bool
    {
        return Config::get('FAIRE_ACCESS_TOKEN', '') !== '';
    }

    /**
     * Haalt de beschikbare voorraad op voor een lijst SKU's.
     *
     * @param array<int, string> $skus
     * @return array<string, int|null> sku => beschikbare voorraad, of null als Faire deze
     *                                  SKU niet bijhoudt (UNTRACKED). SKU's die niet in de
     *                                  respons voorkomen, staan niet in de returnwaarde.
     */
    public static function fetchInventoryBySkus(array $skus): array
    {
        $skus = array_values(array_unique(array_filter($skus, static fn (string $s): bool => $s !== '')));
        if ($skus === []) {
            return [];
        }

        $result = [];
        foreach (array_chunk($skus, self::SKUS_PER_REQUEST) as $chunk) {
            $response = self::request('GET', '/product-inventory/by-skus', ['skus' => implode(',', $chunk)]);

            foreach ($response['inventories'] ?? [] as $sku => $inventory) {
                $available = $inventory['available_quantity'] ?? null;
                $result[$sku] = (is_array($available) && ($available['type'] ?? null) === 'QUANTITY')
                    ? (int) $available['quantity']
                    : null;
            }
        }

        return $result;
    }

    /**
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    private static function request(string $method, string $path, array $query = []): array
    {
        if (!self::isConfigured()) {
            throw new \RuntimeException(
                'Faire-credentials zijn nog niet ingesteld in .env (FAIRE_ACCESS_TOKEN).'
            );
        }

        $url = self::BASE_URL . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'X-FAIRE-ACCESS-TOKEN: ' . Config::get('FAIRE_ACCESS_TOKEN', ''),
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            throw new \RuntimeException('Faire API-aanroep mislukt: ' . $error);
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Faire API gaf status ' . $status . ' terug: ' . $response);
        }

        $decoded = json_decode($response, true);

        return is_array($decoded) ? $decoded : [];
    }
}
