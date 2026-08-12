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
        return Database::fetchAll(
            'SELECT s.*, wp.code AS platform_code, wp.name AS platform_name, wp.color AS platform_color,
                    wp.icon AS platform_icon,
                    COUNT(wo.id) AS order_count,
                    COALESCE(SUM(wo.total_amount_cents), 0) AS total_amount_cents
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
}
