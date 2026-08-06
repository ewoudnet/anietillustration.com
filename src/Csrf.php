<?php

declare(strict_types=1);

namespace App;

final class Csrf
{
    private const SESSION_KEY = 'form_csrf';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function verify(?string $submittedToken): bool
    {
        return is_string($submittedToken) && $submittedToken !== '' && hash_equals(self::token(), $submittedToken);
    }
}
