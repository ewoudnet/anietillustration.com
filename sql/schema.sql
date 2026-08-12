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
    traffic_source VARCHAR(100) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    mollie_payment_id VARCHAR(50) DEFAULT NULL,
    confirmation_email_sent_at DATETIME DEFAULT NULL,
    deleted_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_order_reference (order_reference),
    UNIQUE KEY uq_mollie_payment_id (mollie_payment_id),
    KEY idx_special_id (special_id),
    KEY idx_order_type (order_type),
    CONSTRAINT fk_order_special FOREIGN KEY (special_id) REFERENCES specials (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_views (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    visited_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    path VARCHAR(255) NOT NULL,
    special_id INT UNSIGNED DEFAULT NULL,
    referrer VARCHAR(255) DEFAULT NULL,
    utm_source VARCHAR(100) DEFAULT NULL,
    utm_medium VARCHAR(100) DEFAULT NULL,
    utm_campaign VARCHAR(100) DEFAULT NULL,
    source VARCHAR(100) NOT NULL,
    session_id VARCHAR(64) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_visited_at (visited_at),
    KEY idx_source (source),
    KEY idx_session_id (session_id),
    KEY idx_special_id (special_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Wholesale (Faire + Orderchamp) - zie sql/migrations/005_wholesale_tables.sql
-- voor de uitleg per tabel. Verwijst naar producten.id (products-tabel leeft in
-- de aniet.nl/backoffice-core-schema, niet in dit bestand, zie Database.php).
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS wholesale_platforms (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(60) NOT NULL,
    color VARCHAR(20) NOT NULL,
    icon VARCHAR(10) DEFAULT NULL,
    sync_enabled TINYINT(1) NOT NULL DEFAULT 0,
    -- Hoogwatermerk voor de Faire-cronpoller (fase E) - created_at_min bij de
    -- volgende run, vastgelegd bij de START van elke run.
    last_synced_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wholesale_platforms_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_platform_listings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Precies één van product_id/card_id is gezet (nooit beide, nooit geen van
    -- beide) - "producten" en "kaarten" hebben allebei een eigen SKU-ruimte,
    -- zie CardRepository/ProductRepository en de bestaande Faire-sync die ze
    -- ook al allebei op SKU matcht.
    product_id INT UNSIGNED DEFAULT NULL,
    card_id INT UNSIGNED DEFAULT NULL,
    platform_id INT UNSIGNED NOT NULL,
    external_sku VARCHAR(50) DEFAULT NULL,
    external_product_id VARCHAR(100) DEFAULT NULL,
    is_listed TINYINT(1) NOT NULL DEFAULT 0,
    last_verified_at DATETIME DEFAULT NULL,
    last_seen_stock INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ppl_product_platform (product_id, platform_id),
    UNIQUE KEY uq_ppl_card_platform (card_id, platform_id),
    KEY idx_ppl_platform_id (platform_id),
    CONSTRAINT fk_ppl_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
    CONSTRAINT fk_ppl_card FOREIGN KEY (card_id) REFERENCES cards (id) ON DELETE CASCADE,
    CONSTRAINT fk_ppl_platform FOREIGN KEY (platform_id) REFERENCES wholesale_platforms (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shops (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    platform_id INT UNSIGNED NOT NULL,
    external_shop_id VARCHAR(100) NOT NULL,
    name VARCHAR(190) NOT NULL,
    street VARCHAR(150) DEFAULT NULL,
    city VARCHAR(100) DEFAULT NULL,
    postal_code VARCHAR(20) DEFAULT NULL,
    -- VARCHAR(3), niet CHAR(2): Faire levert ISO alpha-3 (bv. "CAN"),
    -- Orderchamp levert ISO alpha-2 (bv. "NL") - bewust ongewijzigd per
    -- platform opgeslagen, geen normalisatie (zie docs/wholesale.md).
    country_code VARCHAR(3) DEFAULT NULL,
    lat DECIMAL(10,7) DEFAULT NULL,
    lng DECIMAL(10,7) DEFAULT NULL,
    geocoded_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shops_platform_external (platform_id, external_shop_id),
    CONSTRAINT fk_shops_platform FOREIGN KEY (platform_id) REFERENCES wholesale_platforms (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wholesale_orders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    platform_id INT UNSIGNED NOT NULL,
    external_order_id VARCHAR(100) NOT NULL,
    shop_id INT UNSIGNED DEFAULT NULL,
    status ENUM('open', 'confirmed', 'shipped', 'delivered', 'canceled') NOT NULL DEFAULT 'open',
    placed_at DATETIME NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    total_amount_cents INT UNSIGNED NOT NULL DEFAULT 0,
    raw_payload JSON DEFAULT NULL,
    imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    canceled_at DATETIME DEFAULT NULL,
    -- NULL = nog niet afgeschreven van products.current_stock/cards.current_stock
    -- (fase E, zie WholesaleStockDeductionService).
    stock_deducted_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wo_platform_external (platform_id, external_order_id),
    KEY idx_wo_status (status),
    KEY idx_wo_placed_at (placed_at),
    KEY idx_wo_shop_id (shop_id),
    CONSTRAINT fk_wo_platform FOREIGN KEY (platform_id) REFERENCES wholesale_platforms (id),
    CONSTRAINT fk_wo_shop FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wholesale_order_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    wholesale_order_id INT UNSIGNED NOT NULL,
    -- NULL/NULL (geen van beide) als de SKU niet matcht met een lokaal
    -- product of kaart - dat is zelf een signaal voor het
    -- vergelijkingsoverzicht, geen foutsituatie.
    product_id INT UNSIGNED DEFAULT NULL,
    card_id INT UNSIGNED DEFAULT NULL,
    sku VARCHAR(50) NOT NULL,
    title_snapshot VARCHAR(190) NOT NULL,
    quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    unit_price_cents INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_woi_wholesale_order_id (wholesale_order_id),
    KEY idx_woi_product_id (product_id),
    KEY idx_woi_card_id (card_id),
    KEY idx_woi_sku (sku),
    CONSTRAINT fk_woi_order FOREIGN KEY (wholesale_order_id) REFERENCES wholesale_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_woi_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL,
    CONSTRAINT fk_woi_card FOREIGN KEY (card_id) REFERENCES cards (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Geen FK's op product_id/card_id/platform_id - dit is een append-only auditlog,
-- net als page_views.special_id hierboven: een verwijderd product/kaart/platform
-- mag de geschiedenis niet blokkeren of wegvagen.
CREATE TABLE IF NOT EXISTS stock_sync_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED DEFAULT NULL,
    card_id INT UNSIGNED DEFAULT NULL,
    platform_id INT UNSIGNED DEFAULT NULL,
    direction ENUM('inbound', 'outbound') NOT NULL,
    trigger_type ENUM('manual_edit', 'faire_webhook', 'orderchamp_webhook', 'order_placed', 'order_canceled', 'reconciliation', 'initial_import') NOT NULL,
    old_stock INT DEFAULT NULL,
    new_stock INT DEFAULT NULL,
    success TINYINT(1) NOT NULL DEFAULT 1,
    -- 1 = proefdraai (fase D): zou verstuurd zijn, maar sync_enabled stond op 0
    -- voor dit platform, dus is er niets echt naar de API gepost.
    dry_run TINYINT(1) NOT NULL DEFAULT 0,
    error_message TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ssl_product_id (product_id),
    KEY idx_ssl_card_id (card_id),
    KEY idx_ssl_platform_id (platform_id),
    KEY idx_ssl_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO wholesale_platforms (code, name, color, icon, sync_enabled)
SELECT 'faire', 'Faire', '#4a3aff', '🟣', 0 WHERE NOT EXISTS (SELECT 1 FROM wholesale_platforms WHERE code = 'faire');

INSERT INTO wholesale_platforms (code, name, color, icon, sync_enabled)
SELECT 'orderchamp', 'Orderchamp', '#ff5a5f', '🟠', 0 WHERE NOT EXISTS (SELECT 1 FROM wholesale_platforms WHERE code = 'orderchamp');
