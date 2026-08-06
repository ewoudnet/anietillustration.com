<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Alleen-lezen toegang tot `advent_orders`, de orders-tabel van het losse,
 * nog operationele `adventskaarten-bestellen`-project. Beide projecten delen
 * dezelfde hosting-database, dus dit is een gewone query op een tabel naast
 * de eigen `orders`-tabel - geen apart DB-connectie nodig. Schrijft nooit
 * naar deze tabel: het bestaande advent-systeem blijft de enige eigenaar.
 */
final class AdventOrderRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public function findAll(): array
    {
        $stmt = $this->db->query(
            'SELECT * FROM advent_orders WHERE deleted_at IS NULL ORDER BY created_at DESC'
        );

        return $stmt->fetchAll();
    }
}
