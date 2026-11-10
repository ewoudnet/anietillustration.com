<?php

declare(strict_types=1);

namespace App;

/**
 * Key/value-instellingen (tabel app_settings). Leesfouten (bijv. tabel nog niet gemigreerd)
 * vallen terug op de default, zodat een ontbrekende migratie nooit het bestelproces breekt.
 */
final class SettingsRepository
{
    public const NOTIFY_ENABLED = 'notify_new_order_enabled';
    public const NOTIFY_EMAIL = 'notify_new_order_email';

    public static function get(string $key, ?string $default = null): ?string
    {
        try {
            $stmt = SpecialsDatabase::connection()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();

            return $value === false ? $default : ($value === null ? $default : (string) $value);
        } catch (\Throwable $e) {
            return $default;
        }
    }

    public static function set(string $key, string $value): void
    {
        $stmt = SpecialsDatabase::connection()->prepare(
            'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute([$key, $value]);
    }

    public static function newOrderNotificationEnabled(): bool
    {
        return self::get(self::NOTIFY_ENABLED, '1') === '1';
    }

    /**
     * Ontvanger van de nieuwe-bestelling-mail; valt terug op het afzenderadres uit de .env.
     */
    public static function newOrderNotificationEmail(): string
    {
        $email = trim((string) self::get(self::NOTIFY_EMAIL, ''));

        return $email !== '' ? $email : (Config::get('MAIL_FROM_ADDRESS', '') ?? '');
    }
}
