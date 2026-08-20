<?php

declare(strict_types=1);

namespace App;

/**
 * Generieke producten (alle producttypes behalve "Kaarten", die hun eigen tabel
 * hebben in CardRepository omdat ze veel meer specifieke velden nodig hebben).
 * Deze producten worden (nu) uitsluitend via Wholesale verkocht, dus zonder
 * verkoopkanaal-koppeling.
 */
final class ProductRepository
{
    /**
     * @param array{q?: string} $filters
     * @return array<int, array<string, mixed>>
     */
    public static function search(int $productTypeId, array $filters = [], ?int $limit = null, int $offset = 0): array
    {
        [$where, $types, $params] = self::buildWhereClause($productTypeId, $filters);

        $sql = "SELECT * FROM products p {$where} ORDER BY p.sku DESC";
        if ($limit !== null) {
            $sql .= ' LIMIT ? OFFSET ?';
            $types .= 'ii';
            $params[] = $limit;
            $params[] = $offset;
        }

        return Database::fetchAll($sql, $types, $params);
    }

    /**
     * @param array{q?: string} $filters
     */
    public static function countSearch(int $productTypeId, array $filters = []): int
    {
        [$where, $types, $params] = self::buildWhereClause($productTypeId, $filters);

        $row = Database::fetchOne("SELECT COUNT(*) AS total FROM products p {$where}", $types, $params);

        return (int) ($row['total'] ?? 0);
    }

    /**
     * @param array{q?: string} $filters
     * @return array{0: string, 1: string, 2: array<int, mixed>}
     */
    private static function buildWhereClause(int $productTypeId, array $filters): array
    {
        $conditions = ['p.product_type_id = ?'];
        $types = 'i';
        $params = [$productTypeId];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $conditions[] = '(p.sku = ? OR p.title LIKE ?)';
            $types .= 'ss';
            $params[] = $q;
            $params[] = '%' . $q . '%';
        }

        return ['WHERE ' . implode(' AND ', $conditions), $types, $params];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM products WHERE id = ?', 'i', [$id]);
    }

    /**
     * Alle producten van alle (niet-kaart) producttypes, met de producttype-naam erbij
     * - gebruikt voor de Backup-export.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function findAllWithTypeName(): array
    {
        return Database::fetchAll(
            'SELECT p.*, pt.name AS product_type_name
             FROM products p
             INNER JOIN product_types pt ON pt.id = p.product_type_id
             ORDER BY pt.name ASC, p.title ASC'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findBySku(string $sku): ?array
    {
        return Database::fetchOne('SELECT * FROM products WHERE sku = ?', 's', [$sku]);
    }

    private const ORDER_PAGE_SORT_COLUMNS = ['title', 'min_stock', 'current_stock', 'to_order'];

    /**
     * @param bool|null $draftOnly null = alle producten, true = alleen Wholesale Draft,
     *                             false = Wholesale Draft uitsluiten.
     * @return array<int, array<string, mixed>>
     */
    public static function findAllForOrderPage(
        int $productTypeId,
        int $limit,
        int $offset,
        string $sortColumn = 'title',
        string $direction = 'asc',
        ?bool $draftOnly = null,
        string $q = ''
    ): array {
        if (!in_array($sortColumn, self::ORDER_PAGE_SORT_COLUMNS, true)) {
            $sortColumn = 'title';
        }
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';

        [$where, $types, $params] = self::buildOrderPageWhereClause($productTypeId, $draftOnly, $q);
        $types .= 'ii';
        $params[] = $limit;
        $params[] = $offset;

        return Database::fetchAll(
            "SELECT * FROM products {$where} ORDER BY {$sortColumn} {$direction}, title ASC LIMIT ? OFFSET ?",
            $types,
            $params
        );
    }

    public static function countAll(int $productTypeId, ?bool $draftOnly = null, string $q = ''): int
    {
        [$where, $types, $params] = self::buildOrderPageWhereClause($productTypeId, $draftOnly, $q);
        $row = Database::fetchOne("SELECT COUNT(*) AS total FROM products {$where}", $types, $params);

        return (int) ($row['total'] ?? 0);
    }

    /**
     * @return array{0: string, 1: string, 2: array<int, mixed>}
     */
    private static function buildOrderPageWhereClause(int $productTypeId, ?bool $draftOnly, string $q): array
    {
        $conditions = ['product_type_id = ?'];
        $types = 'i';
        $params = [$productTypeId];

        if ($draftOnly !== null) {
            $conditions[] = 'wholesale_draft = ?';
            $types .= 'i';
            $params[] = $draftOnly ? 1 : 0;
        }

        $q = trim($q);
        if ($q !== '') {
            $conditions[] = 'sku LIKE ?';
            $types .= 's';
            $params[] = '%' . $q . '%';
        }

        return ['WHERE ' . implode(' AND ', $conditions), $types, $params];
    }

    /**
     * Producten die nu handmatig op "te bestellen" gezet zijn. Geen automatische
     * voorraad-gebaseerde selectie meer (min./huidige voorraad) - de gebruiker bepaalt
     * zelf via de productenlijst hieronder wat er besteld moet worden.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function needsOrdering(int $productTypeId): array
    {
        return Database::fetchAll(
            'SELECT * FROM products WHERE product_type_id = ? AND to_order > 0 ORDER BY title ASC',
            'i',
            [$productTypeId]
        );
    }

    public static function skuExists(string $sku, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $row = Database::fetchOne(
                'SELECT id FROM products WHERE sku = ? AND id != ? LIMIT 1',
                'si',
                [$sku, $excludeId]
            );
        } else {
            $row = Database::fetchOne('SELECT id FROM products WHERE sku = ? LIMIT 1', 's', [$sku]);
        }

        return $row !== null;
    }

    /**
     * Zelfde suggestie-logica als CardRepository::suggestNextSku (kale prefix, anders
     * met oplopend volgnummer), maar dan tegen de products-tabel.
     */
    public static function suggestNextSku(string $prefix): string
    {
        $rows = Database::fetchAll('SELECT sku FROM products WHERE sku LIKE ?', 's', [$prefix . '%']);
        $existing = array_map(static fn (array $row): string => $row['sku'], $rows);

        if (!in_array($prefix, $existing, true)) {
            return $prefix;
        }

        $suffix = 1;
        while (in_array($prefix . $suffix, $existing, true)) {
            $suffix++;
        }

        return $prefix . $suffix;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        if (self::skuExists($data['sku'])) {
            throw new \RuntimeException('SKU "' . $data['sku'] . '" bestaat al.');
        }

        return Database::insert(
            'INSERT INTO products
                (product_type_id, sku, title, image_path, min_stock, current_stock, to_order,
                 wholesale_draft, comments)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'isssiiiis',
            [
                $data['product_type_id'],
                $data['sku'],
                $data['title'],
                $data['image_path'],
                $data['min_stock'],
                $data['current_stock'],
                $data['to_order'],
                $data['wholesale_draft'],
                $data['comments'],
            ]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        if (self::skuExists($data['sku'], $id)) {
            throw new \RuntimeException('SKU "' . $data['sku'] . '" is al in gebruik door een ander product.');
        }

        Database::run(
            'UPDATE products SET
                sku = ?, title = ?, image_path = ?, min_stock = ?, current_stock = ?, to_order = ?,
                wholesale_draft = ?, comments = ?
             WHERE id = ?',
            'sssiiiisi',
            [
                $data['sku'],
                $data['title'],
                $data['image_path'],
                $data['min_stock'],
                $data['current_stock'],
                $data['to_order'],
                $data['wholesale_draft'],
                $data['comments'],
                $id,
            ]
        );
    }

    public static function updateToOrder(int $id, int $toOrder): void
    {
        Database::run('UPDATE products SET to_order = ? WHERE id = ?', 'ii', [$toOrder, $id]);
    }

    public static function clearAllToOrder(int $productTypeId): void
    {
        Database::run(
            'UPDATE products SET to_order = 0 WHERE product_type_id = ? AND to_order > 0',
            'i',
            [$productTypeId]
        );
    }

    /**
     * Atomische op-/aftelling (i.p.v. lees-dan-schrijf) - gebruikt door
     * WholesaleStockDeductionService (fase E) zodat een webhook en een
     * cron-run die toevallig gelijktijdig hetzelfde product raken elkaar niet
     * kunnen overschrijven.
     */
    public static function adjustCurrentStock(int $id, int $delta): void
    {
        Database::run(
            'UPDATE products SET current_stock = COALESCE(current_stock, 0) + ? WHERE id = ?',
            'ii',
            [$delta, $id]
        );
    }

    /**
     * @return array<string, int> sku => id, voor alle producten - gebruikt bij de Faire-sync.
     */
    public static function allIdsBySku(): array
    {
        $map = [];
        foreach (Database::fetchAll('SELECT id, sku FROM products') as $row) {
            $map[$row['sku']] = (int) $row['id'];
        }

        return $map;
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM products WHERE id = ?', 'i', [$id]);
    }
}
