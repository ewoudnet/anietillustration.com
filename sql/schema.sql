CREATE TABLE IF NOT EXISTS admin_users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(60) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS specials (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(150) NOT NULL,
    slug VARCHAR(80) DEFAULT NULL,
    banner_path VARCHAR(255) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 0,
    ship_eu TINYINT(1) NOT NULL DEFAULT 1,
    ship_world TINYINT(1) NOT NULL DEFAULT 0,
    starts_at DATETIME DEFAULT NULL,
    ends_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS special_price_variants (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    special_id INT UNSIGNED NOT NULL,
    label VARCHAR(100) NOT NULL,
    price_nl_cents INT UNSIGNED NOT NULL,
    price_eu_cents INT UNSIGNED DEFAULT NULL,
    price_world_cents INT UNSIGNED DEFAULT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_special_id (special_id),
    CONSTRAINT fk_variant_special FOREIGN KEY (special_id) REFERENCES specials (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_reference VARCHAR(20) NOT NULL,
    order_type ENUM('special', 'wholesale') NOT NULL DEFAULT 'special',
    special_id INT UNSIGNED DEFAULT NULL,
    price_variant_id INT UNSIGNED DEFAULT NULL,
    variant_label VARCHAR(100) DEFAULT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    street VARCHAR(150) NOT NULL,
    house_number VARCHAR(20) NOT NULL,
    postal_code VARCHAR(20) NOT NULL,
    city VARCHAR(100) NOT NULL,
    country_code CHAR(2) NOT NULL,
    email VARCHAR(190) NOT NULL,
    quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    unit_price_cents INT UNSIGNED NOT NULL,
    total_amount_cents INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    status ENUM('open', 'paid', 'failed', 'expired', 'canceled') NOT NULL DEFAULT 'open',
    source ENUM('online', 'manual') NOT NULL DEFAULT 'online',
    notes TEXT DEFAULT NULL,
    mollie_payment_id VARCHAR(50) DEFAULT NULL,
    confirmation_email_sent_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_order_reference (order_reference),
    UNIQUE KEY uq_mollie_payment_id (mollie_payment_id),
    KEY idx_special_id (special_id),
    KEY idx_order_type (order_type),
    CONSTRAINT fk_order_special FOREIGN KEY (special_id) REFERENCES specials (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
