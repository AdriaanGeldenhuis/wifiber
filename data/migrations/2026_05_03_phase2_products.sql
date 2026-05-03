-- Phase 2 — products catalogue tied to invoicing.
--
-- One-time migration. Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_03_phase2_products.sql
--
-- The seed reflects data/pricing.json at the time of the migration.
-- INSERT IGNORE + UNIQUE (name) makes re-runs idempotent. After this,
-- pricing.json still drives the marketing /pricing page; the products
-- table drives client packages and invoice line items. Edit them in
-- two places until Phase X unifies them.

CREATE TABLE IF NOT EXISTS products (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  tier_key      VARCHAR(40)   NOT NULL DEFAULT '',
  name          VARCHAR(120)  NOT NULL,
  down_mbps     DECIMAL(8,2)  NOT NULL DEFAULT 0,
  up_mbps       DECIMAL(8,2)  NOT NULL DEFAULT 0,
  monthly_price DECIMAL(10,2) NOT NULL DEFAULT 0,
  install_24mo  DECIMAL(10,2) NOT NULL DEFAULT 0,
  install_mtm   DECIMAL(10,2) NOT NULL DEFAULT 2799,
  contention    VARCHAR(20)   NOT NULL DEFAULT '',
  description   TEXT          DEFAULT NULL,
  is_active     TINYINT(1)    NOT NULL DEFAULT 1,
  sort_order    INT           NOT NULL DEFAULT 0,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_name (name),
  KEY idx_tier (tier_key),
  KEY idx_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS product_id INT UNSIGNED DEFAULT NULL,
  ADD KEY IF NOT EXISTS idx_user_product (product_id);

INSERT IGNORE INTO products
  (tier_key, name, down_mbps, up_mbps, monthly_price, install_24mo, install_mtm, contention, sort_order)
VALUES
  ('home',     'Home 2/1 Mbps',         2,    1,    199.00, 0, 2799, '5:1', 100),
  ('home',     'Home 4/2 Mbps',         4,    2,    299.00, 0, 2799, '5:1', 110),
  ('home',     'Home 6/3 Mbps',         6,    3,    459.00, 0, 2799, '5:1', 120),
  ('home',     'Home 8/4 Mbps',         8,    4,    559.00, 0, 2799, '5:1', 130),
  ('home',     'Home 10/5 Mbps',       10,    5,    679.00, 0, 2799, '5:1', 140),
  ('home',     'Home 15/7.5 Mbps',     15,    7.5,  959.00, 0, 2799, '5:1', 150),
  ('home',     'Home 20/10 Mbps',      20,   10,   1399.00, 0, 2799, '5:1', 160),
  ('home',     'Home 40/20 Mbps',      40,   20,   2799.00, 0, 2799, '5:1', 170),
  ('business', 'Business 2/2 Mbps',     2,    2,    479.00, 0, 2799, '2:1', 200),
  ('business', 'Business 4/4 Mbps',     4,    4,    599.00, 0, 2799, '2:1', 210),
  ('business', 'Business 6/6 Mbps',     6,    6,    799.00, 0, 2799, '2:1', 220),
  ('business', 'Business 8/8 Mbps',     8,    8,    999.00, 0, 2799, '2:1', 230),
  ('business', 'Business 10/10 Mbps',  10,   10,   1299.00, 0, 2799, '2:1', 240),
  ('business', 'Business 15/15 Mbps',  15,   15,   1799.00, 0, 2799, '2:1', 250),
  ('business', 'Business 20/20 Mbps',  20,   20,   2499.00, 0, 2799, '2:1', 260),
  ('business', 'Business 40/40 Mbps',  40,   40,   3899.00, 0, 2799, '2:1', 270),
  ('gaming',   'Gaming 2/2 Mbps',       2,    2,    599.00, 0, 2799, '1:1', 300),
  ('gaming',   'Gaming 4/4 Mbps',       4,    4,    749.00, 0, 2799, '1:1', 310),
  ('gaming',   'Gaming 6/6 Mbps',       6,    6,    949.00, 0, 2799, '1:1', 320),
  ('gaming',   'Gaming 8/8 Mbps',       8,    8,   1149.00, 0, 2799, '1:1', 330),
  ('gaming',   'Gaming 10/10 Mbps',    10,   10,   1499.00, 0, 2799, '1:1', 340),
  ('gaming',   'Gaming 15/15 Mbps',    15,   15,   1999.00, 0, 2799, '1:1', 350),
  ('gaming',   'Gaming 20/20 Mbps',    20,   20,   2799.00, 0, 2799, '1:1', 360),
  ('gaming',   'Gaming 40/40 Mbps',    40,   40,   4499.00, 0, 2799, '1:1', 370);
