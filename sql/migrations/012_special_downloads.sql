-- Instant downloads bij specials: prijsvarianten kunnen "digitaal" zijn (geen verzending,
-- geen adres), de bijbehorende bestanden hangen aan de special en worden na betaling
-- via een persoonlijke downloadlink gemaild. Zie docs/specials.md.

ALTER TABLE special_price_variants
    ADD COLUMN is_digital TINYINT(1) NOT NULL DEFAULT 0 AFTER sort_order;

CREATE TABLE IF NOT EXISTS special_downloads (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    special_id INT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(64) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_download_special (special_id),
    CONSTRAINT fk_download_special FOREIGN KEY (special_id) REFERENCES specials (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Snapshot per order + geheim token waarmee de klant zijn downloads opent.
ALTER TABLE orders
    ADD COLUMN is_digital TINYINT(1) NOT NULL DEFAULT 0 AFTER variant_label,
    ADD COLUMN download_token CHAR(64) DEFAULT NULL AFTER mollie_payment_id,
    ADD UNIQUE KEY uq_download_token (download_token);
