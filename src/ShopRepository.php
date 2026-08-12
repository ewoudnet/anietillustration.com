<?php

declare(strict_types=1);

namespace App;

/**
 * Winkels/retailers van Faire en Orderchamp, gebruikt voor de shoplocatie-kaart
 * en het filteren van het orderoverzicht per shop. lat/lng worden eenmalig
 * gevuld via geocoding van het adres (zie docs/wholesale.md).
 */
final class ShopRepository
{
    /**
     * Alle shops met minimaal 1 order, incl. aantal orders en totale waarde -
     * gebruikt op de kaartpagina.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function findAllWithOrderStats(): array
    {
        // MIN(wo.currency) als weergavevaluta: gaat er in de praktijk van uit dat
        // één shop altijd in dezelfde valuta bestelt (een merk zet doorgaans één
        // vaste prijsvaluta) - bij een echte mix zou dit misleidend zijn, zie
        // docs/wholesale.md.
        return Database::fetchAll(
            'SELECT s.*, wp.code AS platform_code, wp.name AS platform_name, wp.color AS platform_color,
                    wp.icon AS platform_icon,
                    COUNT(wo.id) AS order_count,
                    COALESCE(SUM(wo.total_amount_cents), 0) AS total_amount_cents,
                    COALESCE(MIN(wo.currency), \'EUR\') AS currency
             FROM shops s
             INNER JOIN wholesale_platforms wp ON wp.id = s.platform_id
             LEFT JOIN wholesale_orders wo ON wo.shop_id = s.id AND wo.status != \'canceled\'
             GROUP BY s.id
             ORDER BY s.name ASC'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM shops WHERE id = ?', 'i', [$id]);
    }

    public static function countWithoutCoordinates(): int
    {
        $row = Database::fetchOne('SELECT COUNT(*) AS total FROM shops WHERE lat IS NULL OR lng IS NULL');

        return (int) ($row['total'] ?? 0);
    }

    public static function countAll(): int
    {
        $row = Database::fetchOne('SELECT COUNT(*) AS total FROM shops');

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Maakt een shop aan of werkt hem bij (op platform_id + external_shop_id),
     * en geeft altijd het (bestaande of nieuwe) id terug - gebruikt door
     * WholesaleOrderImporter. Adresgegevens worden bijgewerkt bij elke import
     * (het adres op een order kan preciezer/actueler zijn dan een eerdere).
     * lat/lng worden hier nooit aangeraakt (geocoding is een apart traject).
     *
     * @param array{platform_id: int, external_shop_id: string, name: string, street: ?string, city: ?string, postal_code: ?string, country_code: ?string} $data
     */
    public static function upsert(array $data): int
    {
        return Database::insert(
            'INSERT INTO shops (platform_id, external_shop_id, name, street, city, postal_code, country_code)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                name = VALUES(name),
                street = VALUES(street),
                city = VALUES(city),
                postal_code = VALUES(postal_code),
                country_code = VALUES(country_code)',
            'issssss',
            [
                $data['platform_id'],
                $data['external_shop_id'],
                $data['name'],
                $data['street'],
                $data['city'],
                $data['postal_code'],
                $data['country_code'],
            ]
        );
    }
}
