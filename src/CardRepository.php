<?php

declare(strict_types=1);

namespace App;

final class CardRepository
{
    /**
     * @param array{q?: string, sales_channel_id?: int, greetz_status?: string, wholesale_status?: string} $filters
     * @return array<int, array<string, mixed>>
     */
    public static function search(array $filters = [], ?int $limit = null, int $offset = 0): array
    {
        [$where, $types, $params] = self::buildWhereClause($filters);

        $sql = "SELECT c.* FROM cards c {$where} ORDER BY c.sku DESC";
        if ($limit !== null) {
            $sql .= ' LIMIT ? OFFSET ?';
            $types .= 'ii';
            $params[] = $limit;
            $params[] = $offset;
        }

        $cards = Database::fetchAll($sql, $types, $params);

        return self::attachSalesChannels($cards);
    }

    /**
     * @param array{q?: string, sales_channel_id?: int, greetz_status?: string, wholesale_status?: string} $filters
     */
    public static function countSearch(array $filters = []): int
    {
        [$where, $types, $params] = self::buildWhereClause($filters);

        $row = Database::fetchOne("SELECT COUNT(*) AS total FROM cards c {$where}", $types, $params);

        return (int) ($row['total'] ?? 0);
    }

    /**
     * @param array{q?: string, sales_channel_id?: int, greetz_status?: string, wholesale_status?: string} $filters
     * @return array{0: string, 1: string, 2: array<int, mixed>}
     */
    private static function buildWhereClause(array $filters): array
    {
        $conditions = [];
        $types = '';
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $conditions[] = '(c.sku LIKE ? OR c.title LIKE ?)';
            $types .= 'ss';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $salesChannelId = (int) ($filters['sales_channel_id'] ?? 0);
        if ($salesChannelId > 0) {
            $conditions[] = 'c.id IN (SELECT card_id FROM card_sales_channels WHERE sales_channel_id = ?)';
            $types .= 'i';
            $params[] = $salesChannelId;
        }

        // Zelfde prioriteitsvolgorde als greetzStatusLabel() in bootstrap.php.
        $greetzStatus = (string) ($filters['greetz_status'] ?? '');
        $greetzCondition = match ($greetzStatus) {
            'actief' => "(c.rejected_date IS NULL AND c.psd_filename IS NOT NULL AND c.psd_filename != '')",
            'ingediend' => "(c.rejected_date IS NULL AND (c.psd_filename IS NULL OR c.psd_filename = '') AND c.submission_date IS NOT NULL)",
            'afgewezen' => 'c.rejected_date IS NOT NULL',
            'nog_in_te_sturen' => "(c.rejected_date IS NULL AND (c.psd_filename IS NULL OR c.psd_filename = '') AND c.submission_date IS NULL)",
            default => null,
        };
        if ($greetzCondition !== null) {
            $conditions[] = $greetzCondition;
        }

        $wholesaleStatus = (string) ($filters['wholesale_status'] ?? '');
        if ($wholesaleStatus === 'draft') {
            $conditions[] = 'c.wholesale_draft = 1';
        } elseif ($wholesaleStatus === 'actief') {
            $conditions[] = 'c.wholesale_draft = 0';
        }

        $where = $conditions === [] ? '' : ('WHERE ' . implode(' AND ', $conditions));

        return [$where, $types, $params];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $card = Database::fetchOne('SELECT * FROM cards WHERE id = ?', 'i', [$id]);

        if ($card === null) {
            return null;
        }

        $card['sales_channel_ids'] = array_map(
            static fn (array $row): int => (int) $row['sales_channel_id'],
            Database::fetchAll('SELECT sales_channel_id FROM card_sales_channels WHERE card_id = ?', 'i', [$id])
        );

        return $card;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findBySku(string $sku): ?array
    {
        $card = Database::fetchOne('SELECT * FROM cards WHERE sku = ?', 's', [$sku]);

        if ($card === null) {
            return null;
        }

        $card['sales_channel_ids'] = array_map(
            static fn (array $row): int => (int) $row['sales_channel_id'],
            Database::fetchAll(
                'SELECT sales_channel_id FROM card_sales_channels WHERE card_id = ?',
                'i',
                [(int) $card['id']]
            )
        );

        return $card;
    }

    private const ORDER_PAGE_SORT_COLUMNS = ['title', 'min_stock', 'current_stock', 'to_order'];

    /**
     * Alle kaarten voor de bestelpagina (geen sales-channel-koppeling nodig daar).
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * @param bool|null $draftOnly null = alle kaarten, true = alleen Wholesale Draft,
     *                             false = Wholesale Draft uitsluiten.
     */
    public static function findAllForOrderPage(
        int $limit,
        int $offset,
        string $sortColumn = 'title',
        string $direction = 'asc',
        ?bool $draftOnly = null
    ): array {
        if (!in_array($sortColumn, self::ORDER_PAGE_SORT_COLUMNS, true)) {
            $sortColumn = 'title';
        }
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';

        $where = '';
        $types = '';
        $params = [];
        if ($draftOnly !== null) {
            $where = 'WHERE wholesale_draft = ?';
            $types = 'i';
            $params[] = $draftOnly ? 1 : 0;
        }
        $types .= 'ii';
        $params[] = $limit;
        $params[] = $offset;

        return Database::fetchAll(
            "SELECT * FROM cards {$where} ORDER BY {$sortColumn} {$direction}, title ASC LIMIT ? OFFSET ?",
            $types,
            $params
        );
    }

    public static function countAll(?bool $draftOnly = null): int
    {
        if ($draftOnly !== null) {
            $row = Database::fetchOne(
                'SELECT COUNT(*) AS total FROM cards WHERE wholesale_draft = ?',
                'i',
                [$draftOnly ? 1 : 0]
            );
        } else {
            $row = Database::fetchOne('SELECT COUNT(*) AS total FROM cards');
        }

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Kaarten die nu besteld moeten worden: handmatig op "te bestellen" gezet, of
     * (zodra handmatig bijgehouden) voorraad onder de minimale voorraad - dat laatste
     * niet voor Wholesale Draft-kaarten, die moeten niet automatisch in de lijst
     * verschijnen zolang ze nog niet (opnieuw) actief zijn op Wholesale.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function needsOrdering(): array
    {
        return Database::fetchAll(
            'SELECT * FROM cards
             WHERE to_order > 0
                OR (current_stock IS NOT NULL AND current_stock < min_stock AND wholesale_draft = 0)
             ORDER BY title ASC'
        );
    }

    public static function skuExists(string $sku, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $row = Database::fetchOne(
                'SELECT id FROM cards WHERE sku = ? AND id != ? LIMIT 1',
                'si',
                [$sku, $excludeId]
            );
        } else {
            $row = Database::fetchOne('SELECT id FROM cards WHERE sku = ? LIMIT 1', 's', [$sku]);
        }

        return $row !== null;
    }

    /**
     * Suggereert een SKU op basis van een datumprefix in YYMMDD-formaat (bijv. "260730"
     * voor 30-07-2026): de kale datum als die nog vrij is, anders de datum met een
     * oplopend volgnummer (260730 -> 2607301 -> 2607302 -> ...). Blijft altijd vrij aan
     * te passen door de gebruiker, dit is puur een voorstel.
     */
    public static function suggestNextSku(string $datePrefix): string
    {
        $rows = Database::fetchAll(
            'SELECT sku FROM cards WHERE sku LIKE ?',
            's',
            [$datePrefix . '%']
        );
        $existing = array_map(static fn (array $row): string => $row['sku'], $rows);

        if (!in_array($datePrefix, $existing, true)) {
            return $datePrefix;
        }

        $suffix = 1;
        while (in_array($datePrefix . $suffix, $existing, true)) {
            $suffix++;
        }

        return $datePrefix . $suffix;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, int> $salesChannelIds
     */
    public static function create(array $data, array $salesChannelIds): int
    {
        // Nogmaals checken, ook al valideert card-form.php dit al - de SKU moet ALTIJD
        // uniek zijn, ongeacht welke aanroeper (bijv. import of duplicate) dit mist.
        if (self::skuExists($data['sku'])) {
            throw new \RuntimeException('SKU "' . $data['sku'] . '" bestaat al.');
        }

        return Database::transaction(static function () use ($data, $salesChannelIds): int {
            $id = Database::insert(
                'INSERT INTO cards
                    (sku, title, image_path, format, card_type, has_envelope, envelope_color, min_stock,
                     current_stock, to_order, wholesale_draft, comments, greetz_type, submission_date,
                     rejected_date, psd_filename)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                'sssssisiiiisssss',
                [
                    $data['sku'],
                    $data['title'],
                    $data['image_path'],
                    $data['format'],
                    $data['card_type'],
                    $data['has_envelope'],
                    $data['envelope_color'],
                    $data['min_stock'],
                    $data['current_stock'],
                    $data['to_order'],
                    $data['wholesale_draft'],
                    $data['comments'],
                    $data['greetz_type'],
                    $data['submission_date'],
                    $data['rejected_date'],
                    $data['psd_filename'],
                ]
            );

            self::syncSalesChannels($id, $salesChannelIds);

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, int> $salesChannelIds
     */
    public static function update(int $id, array $data, array $salesChannelIds): void
    {
        if (self::skuExists($data['sku'], $id)) {
            throw new \RuntimeException('SKU "' . $data['sku'] . '" is al in gebruik door een andere kaart.');
        }

        Database::transaction(static function () use ($id, $data, $salesChannelIds): void {
            Database::run(
                'UPDATE cards SET
                    sku = ?, title = ?, image_path = ?, format = ?, card_type = ?, has_envelope = ?, envelope_color = ?,
                    min_stock = ?, current_stock = ?, to_order = ?, wholesale_draft = ?, comments = ?,
                    greetz_type = ?, submission_date = ?, rejected_date = ?, psd_filename = ?
                 WHERE id = ?',
                'sssssisiiiisssssi',
                [
                    $data['sku'],
                    $data['title'],
                    $data['image_path'],
                    $data['format'],
                    $data['card_type'],
                    $data['has_envelope'],
                    $data['envelope_color'],
                    $data['min_stock'],
                    $data['current_stock'],
                    $data['to_order'],
                    $data['wholesale_draft'],
                    $data['comments'],
                    $data['greetz_type'],
                    $data['submission_date'],
                    $data['rejected_date'],
                    $data['psd_filename'],
                    $id,
                ]
            );

            self::syncSalesChannels($id, $salesChannelIds);
        });
    }

    public static function updateToOrder(int $id, int $toOrder): void
    {
        Database::run('UPDATE cards SET to_order = ? WHERE id = ?', 'ii', [$toOrder, $id]);
    }

    public static function updateCurrentStock(int $id, ?int $stock): void
    {
        Database::run('UPDATE cards SET current_stock = ? WHERE id = ?', 'ii', [$stock, $id]);
    }

    /**
     * @return array<string, int> sku => id, voor alle kaarten - gebruikt bij de Faire-sync.
     */
    public static function allIdsBySku(): array
    {
        $map = [];
        foreach (Database::fetchAll('SELECT id, sku FROM cards') as $row) {
            $map[$row['sku']] = (int) $row['id'];
        }

        return $map;
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM cards WHERE id = ?', 'i', [$id]);
    }

    /**
     * @param array<int, int> $salesChannelIds
     */
    private static function syncSalesChannels(int $cardId, array $salesChannelIds): void
    {
        Database::run('DELETE FROM card_sales_channels WHERE card_id = ?', 'i', [$cardId]);

        foreach (array_unique(array_map('intval', $salesChannelIds)) as $channelId) {
            Database::run(
                'INSERT INTO card_sales_channels (card_id, sales_channel_id) VALUES (?, ?)',
                'ii',
                [$cardId, $channelId]
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $cards
     * @return array<int, array<string, mixed>>
     */
    private static function attachSalesChannels(array $cards): array
    {
        if ($cards === []) {
            return [];
        }

        $ids = array_map(static fn (array $c): int => (int) $c['id'], $cards);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));

        $rows = Database::fetchAll(
            "SELECT csc.card_id AS card_id, sc.id AS channel_id, sc.name AS name, sc.abbreviation AS abbreviation, sc.color AS color
             FROM card_sales_channels csc
             INNER JOIN sales_channels sc ON sc.id = csc.sales_channel_id
             WHERE csc.card_id IN ({$placeholders})
             ORDER BY sc.sort_order ASC",
            $types,
            $ids
        );

        $byCard = [];
        foreach ($rows as $row) {
            $byCard[(int) $row['card_id']][] = [
                'id' => (int) $row['channel_id'],
                'name' => $row['name'],
                'abbreviation' => $row['abbreviation'],
                'color' => $row['color'],
            ];
        }

        foreach ($cards as &$card) {
            $card['sales_channels'] = $byCard[(int) $card['id']] ?? [];
        }
        unset($card);

        return $cards;
    }
}
