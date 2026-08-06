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
        $pdo = SpecialsDatabase::connection();
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
        $pdo = SpecialsDatabase::connection();
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
        $pdo = SpecialsDatabase::connection();
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
        $pdo = SpecialsDatabase::connection();
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
        $pdo = SpecialsDatabase::connection();
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
        $pdo = SpecialsDatabase::connection();
        $stmt = $pdo->prepare(
            "SELECT * FROM specials
             WHERE id = ? AND active = 1
               AND (starts_at IS NULL OR starts_at <= NOW())
               AND (ends_at IS NULL OR ends_at >= NOW())
             LIMIT 1"
        );
        $stmt->execute([$id]);

        return $this->attachOrderableVariants($stmt->fetch());
    }

    /**
     * Zelfde als findOrderable(), maar via de deelbare slug (/specials/{slug}) i.p.v. het id.
     *
     * @return array<string, mixed>|null
     */
    public function findOrderableBySlug(string $slug): ?array
    {
        $pdo = SpecialsDatabase::connection();
        $stmt = $pdo->prepare(
            "SELECT * FROM specials
             WHERE slug = ? AND active = 1
               AND (starts_at IS NULL OR starts_at <= NOW())
               AND (ends_at IS NULL OR ends_at >= NOW())
             LIMIT 1"
        );
        $stmt->execute([$slug]);

        return $this->attachOrderableVariants($stmt->fetch());
    }

    /**
     * @param array<string, mixed>|false $special
     * @return array<string, mixed>|null
     */
    private function attachOrderableVariants(array|false $special): ?array
    {
        if ($special === false) {
            return null;
        }

        $special['variants'] = array_values(array_filter(
            $this->findVariants((int) $special['id']),
            static fn (array $variant): bool => (int) $variant['active'] === 1
        ));

        return $special;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $pdo = SpecialsDatabase::connection();
        $stmt = $pdo->prepare('SELECT * FROM specials WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $special = $stmt->fetch();

        return $special === false ? null : $special;
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $pdo = SpecialsDatabase::connection();

        if ($excludeId !== null) {
            $stmt = $pdo->prepare('SELECT 1 FROM specials WHERE slug = ? AND id != ? LIMIT 1');
            $stmt->execute([$slug, $excludeId]);
        } else {
            $stmt = $pdo->prepare('SELECT 1 FROM specials WHERE slug = ? LIMIT 1');
            $stmt->execute([$slug]);
        }

        return $stmt->fetch() !== false;
    }

    /**
     * Zet een titel om naar een URL-vriendelijke slug: kleine letters, cijfers en
     * koppeltekens, geen dubbele of rand-koppeltekens.
     */
    public static function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, array{label: string, price_nl_cents: int, price_eu_cents: ?int, price_world_cents: ?int}> $variants
     */
    public function create(array $data, array $variants): int
    {
        $pdo = SpecialsDatabase::connection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO specials (title, slug, banner_path, description, active, ship_eu, ship_world, starts_at, ends_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $data['title'],
                $data['slug'],
                $data['banner_path'],
                $data['description'],
                $data['active'] ? 1 : 0,
                $data['ship_eu'] ? 1 : 0,
                $data['ship_world'] ? 1 : 0,
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
     * @param array<int, array{label: string, price_nl_cents: int, price_eu_cents: ?int, price_world_cents: ?int}> $variants
     */
    public function update(int $id, array $data, array $variants): void
    {
        $pdo = SpecialsDatabase::connection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'UPDATE specials SET title = ?, slug = ?, banner_path = ?, description = ?, active = ?, ship_eu = ?, ship_world = ?, starts_at = ?, ends_at = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $data['title'],
                $data['slug'],
                $data['banner_path'],
                $data['description'],
                $data['active'] ? 1 : 0,
                $data['ship_eu'] ? 1 : 0,
                $data['ship_world'] ? 1 : 0,
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
     * @param array<int, array{label: string, price_nl_cents: int, price_eu_cents: ?int, price_world_cents: ?int}> $variants
     */
    private function saveVariants(int $specialId, array $variants): void
    {
        $pdo = SpecialsDatabase::connection();

        $delete = $pdo->prepare('DELETE FROM special_price_variants WHERE special_id = ?');
        $delete->execute([$specialId]);

        $insert = $pdo->prepare(
            'INSERT INTO special_price_variants (special_id, label, price_nl_cents, price_eu_cents, price_world_cents, sort_order, active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );

        foreach (array_values($variants) as $sortOrder => $variant) {
            $insert->execute([
                $specialId,
                $variant['label'],
                $variant['price_nl_cents'],
                $variant['price_eu_cents'],
                $variant['price_world_cents'],
                $sortOrder,
            ]);
        }
    }

    public function setActive(int $id, bool $active): void
    {
        $pdo = SpecialsDatabase::connection();
        $stmt = $pdo->prepare('UPDATE specials SET active = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, $id]);
    }

    public function delete(int $id): void
    {
        $pdo = SpecialsDatabase::connection();
        $stmt = $pdo->prepare('DELETE FROM specials WHERE id = ?');
        $stmt->execute([$id]);
    }
}
