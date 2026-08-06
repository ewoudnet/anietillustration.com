<?php

declare(strict_types=1);

namespace App;

final class UserRepository
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function findAll(): array
    {
        $users = Database::fetchAll(
            'SELECT id, username, is_admin, active, created_at FROM users ORDER BY username ASC'
        );

        return self::attachSections($users);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $user = Database::fetchOne(
            'SELECT id, username, is_admin, active, created_at FROM users WHERE id = ?',
            'i',
            [$id]
        );

        if ($user === null) {
            return null;
        }

        $user['section_ids'] = array_map(
            static fn (array $row): int => (int) $row['section_id'],
            Database::fetchAll('SELECT section_id FROM user_sections WHERE user_id = ?', 'i', [$id])
        );

        return $user;
    }

    public static function usernameExists(string $username, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $row = Database::fetchOne(
                'SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1',
                'si',
                [$username, $excludeId]
            );
        } else {
            $row = Database::fetchOne('SELECT id FROM users WHERE username = ? LIMIT 1', 's', [$username]);
        }

        return $row !== null;
    }

    /**
     * @param array<int, int> $sectionIds
     */
    public static function create(string $username, string $passwordHash, bool $isAdmin, array $sectionIds): int
    {
        return Database::transaction(static function () use ($username, $passwordHash, $isAdmin, $sectionIds): int {
            $id = Database::insert(
                'INSERT INTO users (username, password_hash, is_admin, active) VALUES (?, ?, ?, 1)',
                'ssi',
                [$username, $passwordHash, $isAdmin ? 1 : 0]
            );

            self::syncSections($id, $sectionIds);

            return $id;
        });
    }

    /**
     * @param array<int, int> $sectionIds
     */
    public static function update(int $id, string $username, bool $isAdmin, bool $active, array $sectionIds): void
    {
        Database::transaction(static function () use ($id, $username, $isAdmin, $active, $sectionIds): void {
            Database::run(
                'UPDATE users SET username = ?, is_admin = ?, active = ? WHERE id = ?',
                'siii',
                [$username, $isAdmin ? 1 : 0, $active ? 1 : 0, $id]
            );

            self::syncSections($id, $sectionIds);
        });
    }

    public static function updatePassword(int $id, string $passwordHash): void
    {
        Database::run('UPDATE users SET password_hash = ? WHERE id = ?', 'si', [$passwordHash, $id]);
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM users WHERE id = ?', 'i', [$id]);
    }

    public static function countAdmins(): int
    {
        $row = Database::fetchOne('SELECT COUNT(*) AS total FROM users WHERE is_admin = 1 AND active = 1');

        return (int) ($row['total'] ?? 0);
    }

    /**
     * @param array<int, int> $sectionIds
     */
    private static function syncSections(int $userId, array $sectionIds): void
    {
        Database::run('DELETE FROM user_sections WHERE user_id = ?', 'i', [$userId]);

        foreach (array_unique(array_map('intval', $sectionIds)) as $sectionId) {
            Database::run(
                'INSERT INTO user_sections (user_id, section_id) VALUES (?, ?)',
                'ii',
                [$userId, $sectionId]
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $users
     * @return array<int, array<string, mixed>>
     */
    private static function attachSections(array $users): array
    {
        if ($users === []) {
            return [];
        }

        $ids = array_map(static fn (array $u): int => (int) $u['id'], $users);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));

        $rows = Database::fetchAll(
            "SELECT us.user_id AS user_id, s.name AS name
             FROM user_sections us
             INNER JOIN sections s ON s.id = us.section_id
             WHERE us.user_id IN ({$placeholders})
             ORDER BY s.sort_order ASC",
            $types,
            $ids
        );

        $byUser = [];
        foreach ($rows as $row) {
            $byUser[(int) $row['user_id']][] = $row['name'];
        }

        foreach ($users as &$user) {
            $user['section_names'] = $byUser[(int) $user['id']] ?? [];
        }
        unset($user);

        return $users;
    }
}
