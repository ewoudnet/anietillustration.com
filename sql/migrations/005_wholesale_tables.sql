-- Wholesale-sectie (Faire + Orderchamp): datamodel voor gesynchroniseerde orders,
-- shoplocaties, SKU/platform-vergelijking en de voorraad-synchronisatielog. Zie
-- docs/wholesale.md voor de volledige uitleg. Voer dit handmatig uit op elke
-- bestaande database (schema.sql volstaat voor nieuwe installaties).
--
-- Losstaand van de bestaande `orders`-tabel (Mollie-checkout van specials): Faire/
-- Orderchamp-orders zijn multi-regel marktplaatsorders zonder eigen Mollie-betaling,
-- dus een eigen model i.p.v. hergebruik van orders.order_type='wholesale'.
--
-- Verwijst naar products.id (products-tabel leeft in de aniet.nl/backoffice-core-
-- schema, dezelfde live database, zie Database.php/ProductRepository.php).
--
-- Idempotent: veilig opnieuw uit te voeren.

CREATE TABLE IF NOT EXISTS wholesale_platforms (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(60) NOT NULL,
    color VARCHAR(20) NOT NULL,
    icon VARCHAR(10) DEFAULT NULL,
    -- Kill switch: zolang 0 wordt er alleen gelezen/gelogd, nooit teruggeschreven
    -- naar dit platform. Pas aanzetten zodra alle producten hier correct op staan.
    sync_enabled TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wholesale_platforms_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Voedt het SKU/sales-channel-vergelijkingsoverzicht: welk product staat (nog)
-- niet op welk platform, en klopt de laatst geziene voorraad.
CREATE TABLE IF NOT EXISTS product_platform_listings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED NOT NULL,
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
    KEY idx_ppl_platform_id (platform_id),
    CONSTRAINT fk_ppl_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
    CONSTRAINT fk_ppl_platform FOREIGN KEY (platform_id) REFERENCES wholesale_platforms (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Voedt de shoplocatie-kaart. lat/lng worden eenmalig gevuld via geocoding
-- (OpenStreetMap Nominatim) van het adres en daarna gecached.
CREATE TABLE IF NOT EXISTS shops (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    platform_id INT UNSIGNED NOT NULL,
    external_shop_id VARCHAR(100) NOT NULL,
    name VARCHAR(190) NOT NULL,
    street VARCHAR(150) DEFAULT NULL,
    city VARCHAR(100) DEFAULT NULL,
    postal_code VARCHAR(20) DEFAULT NULL,
    country_code CHAR(2) DEFAULT NULL,
    lat DECIMAL(10,7) DEFAULT NULL,
    lng DECIMAL(10,7) DEFAULT NULL,
    geocoded_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shops_platform_external (platform_id, external_shop_id),
    CONSTRAINT fk_shops_platform FOREIGN KEY (platform_id) REFERENCES wholesale_platforms (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- UNIQUE(platform_id, external_order_id) maakt herhaalde webhook-delivery
-- (Orderchamp retryt tot 19x/48u) veilig idempotent.
CREATE TABLE IF NOT EXISTS wholesale_orders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    platform_id INT UNSIGNED NOT NULL,
    external_order_id VARCHAR(100) NOT NULL,
    shop_id INT UNSIGNED DEFAULT NULL,
    status ENUM('open', 'confirmed', 'shipped', 'delivered', 'canceled') NOT NULL DEFAULT 'open',
    placed_at DATETIME NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    total_amount_cents INT UNSIGNED NOT NULL DEFAULT 0,
    -- Ruwe platform-payload bewaren, zodat later zonder herimport extra velden
    -- alsnog uit te lezen zijn als blijkt dat de eerste kolommenset iets miste.
    raw_payload JSON DEFAULT NULL,
    imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    canceled_at DATETIME DEFAULT NULL,
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
    -- NULL als de SKU niet matcht met een lokaal product - dat is zelf een
    -- signaal voor het vergelijkingsoverzicht, geen foutsituatie.
    product_id INT UNSIGNED DEFAULT NULL,
    sku VARCHAR(50) NOT NULL,
    title_snapshot VARCHAR(190) NOT NULL,
    quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    unit_price_cents INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_woi_wholesale_order_id (wholesale_order_id),
    KEY idx_woi_product_id (product_id),
    KEY idx_woi_sku (sku),
    CONSTRAINT fk_woi_order FOREIGN KEY (wholesale_order_id) REFERENCES wholesale_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_woi_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Geen FK's op product_id/platform_id - dit is een append-only auditlog, net als
-- page_views.special_id: een verwijderd product/platform mag de geschiedenis
-- niet blokkeren of wegvagen.
CREATE TABLE IF NOT EXISTS stock_sync_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED DEFAULT NULL,
    platform_id INT UNSIGNED DEFAULT NULL,
    direction ENUM('inbound', 'outbound') NOT NULL,
    trigger_type ENUM('manual_edit', 'faire_webhook', 'orderchamp_webhook', 'order_placed', 'order_canceled', 'reconciliation', 'initial_import') NOT NULL,
    old_stock INT DEFAULT NULL,
    new_stock INT DEFAULT NULL,
    success TINYINT(1) NOT NULL DEFAULT 1,
    error_message TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ssl_product_id (product_id),
    KEY idx_ssl_platform_id (platform_id),
    KEY idx_ssl_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO wholesale_platforms (code, name, color, icon, sync_enabled)
SELECT 'faire', 'Faire', '#4a3aff', '🟣', 0 WHERE NOT EXISTS (SELECT 1 FROM wholesale_platforms WHERE code = 'faire');

INSERT INTO wholesale_platforms (code, name, color, icon, sync_enabled)
SELECT 'orderchamp', 'Orderchamp', '#ff5a5f', '🟠', 0 WHERE NOT EXISTS (SELECT 1 FROM wholesale_platforms WHERE code = 'orderchamp');
