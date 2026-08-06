<?php

declare(strict_types=1);

namespace App;

final class Auth
{
    private const SESSION_USER_ID = 'user_id';
    private const SESSION_USERNAME = 'username';

    public static function attempt(string $username, string $password): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, username, password_hash, active FROM admin_users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user === false || (int) $user['active'] !== 1 || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_USER_ID] = (int) $user['id'];
        $_SESSION[self::SESSION_USERNAME] = $user['username'];

        return true;
    }

    public static function isLoggedIn(): bool
    {
        return isset($_SESSION[self::SESSION_USER_ID]);
    }

    public static function username(): ?string
    {
        return $_SESSION[self::SESSION_USERNAME] ?? null;
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            $base = defined('BACKEND_BASE') ? BACKEND_BASE : '';
            header('Location: ' . $base . '/login.php');
            exit;
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }
}
