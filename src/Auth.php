<?php

declare(strict_types=1);

namespace App;

final class Auth
{
    private const SESSION_USER_ID = 'user_id';
    private const SESSION_USERNAME = 'username';
    private const SESSION_IS_ADMIN = 'is_admin';
    private const SESSION_SECTIONS = 'section_slugs';

    public static function attempt(string $username, string $password): bool
    {
        $user = Database::fetchOne(
            'SELECT id, username, password_hash, is_admin, active FROM users WHERE username = ? LIMIT 1',
            's',
            [$username]
        );

        if ($user === null || (int) $user['active'] !== 1 || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);

        $_SESSION[self::SESSION_USER_ID] = (int) $user['id'];
        $_SESSION[self::SESSION_USERNAME] = $user['username'];
        $_SESSION[self::SESSION_IS_ADMIN] = (bool) $user['is_admin'];
        $_SESSION[self::SESSION_SECTIONS] = self::loadSectionSlugs((int) $user['id']);

        return true;
    }

    /**
     * @return array<int, string>
     */
    private static function loadSectionSlugs(int $userId): array
    {
        $rows = Database::fetchAll(
            'SELECT s.slug FROM sections s
             INNER JOIN user_sections us ON us.section_id = s.id
             WHERE us.user_id = ?',
            'i',
            [$userId]
        );

        return array_map(static fn (array $row): string => (string) $row['slug'], $rows);
    }

    public static function isLoggedIn(): bool
    {
        return isset($_SESSION[self::SESSION_USER_ID]);
    }

    public static function isAdmin(): bool
    {
        return self::isLoggedIn() && ($_SESSION[self::SESSION_IS_ADMIN] ?? false) === true;
    }

    public static function userId(): ?int
    {
        return $_SESSION[self::SESSION_USER_ID] ?? null;
    }

    public static function username(): ?string
    {
        return $_SESSION[self::SESSION_USERNAME] ?? null;
    }

    public static function hasSection(string $slug): bool
    {
        if (!self::isLoggedIn()) {
            return false;
        }

        if (self::isAdmin()) {
            return true;
        }

        return in_array($slug, $_SESSION[self::SESSION_SECTIONS] ?? [], true);
    }

    /**
     * @return array<int, string>
     */
    public static function sectionSlugs(): array
    {
        return $_SESSION[self::SESSION_SECTIONS] ?? [];
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            $base = defined('BACKEND_BASE') ? BACKEND_BASE : '';
            header('Location: ' . $base . '/login.php');
            exit;
        }
    }

    public static function requireSection(string $slug): void
    {
        self::requireLogin();

        if (!self::hasSection($slug)) {
            http_response_code(403);
            echo '403 - Je hebt geen toegang tot deze sectie.';
            exit;
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();

        if (!self::isAdmin()) {
            http_response_code(403);
            echo '403 - Alleen beheerders hebben hier toegang.';
            exit;
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }
}
