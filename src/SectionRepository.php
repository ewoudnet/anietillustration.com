<?php

declare(strict_types=1);

namespace App;

final class SectionRepository
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function findAll(): array
    {
        return Database::fetchAll('SELECT * FROM sections ORDER BY sort_order ASC, name ASC');
    }
}
