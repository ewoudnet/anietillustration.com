-- Eenvoudige key/value-instellingen die in de backend (Settings) aan/uit te zetten zijn,
-- o.a. de e-mailmelding bij een nieuwe bestelling.
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(64) NOT NULL,
    setting_value TEXT DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
