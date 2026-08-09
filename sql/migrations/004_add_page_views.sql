-- Paginaweergave-/bronherkenning-logging voor specials, analoog aan
-- adventskaarten-bestellen/sql/migrations/006_add_stats_tracking.sql (advent_page_views),
-- met special_id i.p.v. product_type. Voer dit handmatig uit op elke bestaande database
-- (schema.sql volstaat voor nieuwe installaties).

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
