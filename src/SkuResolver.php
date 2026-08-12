<?php

declare(strict_types=1);

namespace App;

/**
 * Matcht een externe (Faire/Orderchamp) SKU tegen de lokale `cards`- of
 * `products`-tabel - dezelfde twee-tabellen-aanpak als de bestaande
 * eenrichtings-Faire-voorraadsync (zie FaireService/faire-sync.php:
 * kaarten worden eerst gecontroleerd, dan generieke producten).
 */
final class SkuResolver
{
    /**
     * @return array{type: 'card'|'product'|null, id: ?int, title: ?string, currentStock: ?int}
     */
    public static function resolve(string $sku): array
    {
        $card = CardRepository::findBySku($sku);
        if ($card !== null) {
            return [
                'type' => 'card',
                'id' => (int) $card['id'],
                'title' => $card['title'],
                'currentStock' => $card['current_stock'] !== null ? (int) $card['current_stock'] : null,
            ];
        }

        $product = ProductRepository::findBySku($sku);
        if ($product !== null) {
            return [
                'type' => 'product',
                'id' => (int) $product['id'],
                'title' => $product['title'],
                'currentStock' => $product['current_stock'] !== null ? (int) $product['current_stock'] : null,
            ];
        }

        return ['type' => null, 'id' => null, 'title' => null, 'currentStock' => null];
    }
}
