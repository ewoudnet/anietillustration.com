<?php

declare(strict_types=1);

namespace App;

final class SpecialRepository
{
    /**
     * @return array<int, array<string, mixed>> Nieuwste eerst, incl. aantal prijsvarianten.
     */
    public function findAll(): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->query(
            'SELECT s.*, (SELECT COUNT(*) FROM special_price_variants v WHERE v.special_id = s.id) AS variant_count
             FROM specials s
             ORDER BY s.created_at DESC'
        );

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM specials WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $special = $stmt->fetch();

        return $special === false ? null : $special;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findVariants(int $specialId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM special_price_variants WHERE special_id = ? ORDER BY sort_order, id');
        $stmt->execute([$specialId]);

        return $stmt->fetchAll();
    }

    /**
     * "Lopend": actief en (nog niet begonnen-check n.v.t.) en niet verlopen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findPublicActive(): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->query(
            "SELECT * FROM specials
             WHERE active = 1
               AND (starts_at IS NULL OR starts_at <= NOW())
               AND (ends_at IS NULL OR ends_at >= NOW())
             ORDER BY created_at DESC"
        );

        return $stmt->fetchAll();
    }

    /**
     * "Verlopen": was actief, maar de einddatum is inmiddels gepasseerd.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findPublicExpired(): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->query(
            "SELECT * FROM specials
             WHERE active = 1 AND ends_at IS NOT NULL AND ends_at < NOW()
             ORDER BY ends_at DESC"
        );

        return $stmt->fetchAll();
    }

    /**
     * Een special die publiek besteld mag worden (actief en binnen de looptijd), met varianten.
     *
     * @return array<string, mixed>|null
     */
    public function findOrderable(int $id): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT * FROM specials
             WHERE id = ? AND active = 1
               AND (starts_at IS NULL OR starts_at <= NOW())
               AND (ends_at IS NULL OR ends_at >= NOW())
             LIMIT 1"
        );
        $stmt->execute([$id]);
        $special = $stmt->fetch();

        if ($special === false) {
            return null;
        }

        $special['variants'] = array_values(array_filter(
            $this->findVariants($id),
            static fn (array $variant): bool => (int) $variant['active'] === 1
        ));

        return $special;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, array{label: string, price_cents: int}> $variants
     */
    public function create(array $data, array $variants): int
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO specials (title, banner_path, description, active, starts_at, ends_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $data['title'],
                $data['banner_path'],
                $data['description'],
                $data['active'] ? 1 : 0,
                $data['starts_at'],
                $data['ends_at'],
            ]);

            $specialId = (int) $pdo->lastInsertId();
            $this->saveVariants($specialId, $variants);

            $pdo->commit();

            return $specialId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, array{label: string, price_cents: int}> $variants
     */
    public function update(int $id, array $data, array $variants): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'UPDATE specials SET title = ?, banner_path = ?, description = ?, active = ?, starts_at = ?, ends_at = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $data['title'],
                $data['banner_path'],
                $data['description'],
                $data['active'] ? 1 : 0,
                $data['starts_at'],
                $data['ends_at'],
                $id,
            ]);

            $this->saveVariants($id, $variants);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Vervangt alle prijsvarianten van een special door de meegegeven set. Bestaande orders
     * blijven intact - die bewaren een eigen snapshot (variant_label/unit_price_cents) en
     * hebben geen foreign key naar price_variant_id.
     *
     * @param array<int, array{label: string, price_cents: int}> $variants
     */
    private function saveVariants(int $specialId, array $variants): void
    {
        $pdo = Database::connection();

        $delete = $pdo->prepare('DELETE FROM special_price_variants WHERE special_id = ?');
        $delete->execute([$specialId]);

        $insert = $pdo->prepare(
            'INSERT INTO special_price_variants (special_id, label, price_cents, sort_order, active)
             VALUES (?, ?, ?, ?, 1)'
        );

        foreach (array_values($variants) as $sortOrder => $variant) {
            $insert->execute([$specialId, $variant['label'], $variant['price_cents'], $sortOrder]);
        }
    }

    public function setActive(int $id, bool $active): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE specials SET active = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, $id]);
    }

    public function delete(int $id): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('DELETE FROM specials WHERE id = ?');
        $stmt->execute([$id]);
    }
}
