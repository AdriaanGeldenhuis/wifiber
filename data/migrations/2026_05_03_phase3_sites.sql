-- Phase 3 — network map: sites (towers / APs / PTP endpoints / PoPs),
-- links between sites, and a per-client site assignment.
--
-- One-time migration. Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_03_phase3_sites.sql

CREATE TABLE IF NOT EXISTS sites (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  parent_id         INT UNSIGNED  DEFAULT NULL,
  type              ENUM('tower','ap','ptp_endpoint','pop','other') NOT NULL DEFAULT 'tower',
  name              VARCHAR(120)  NOT NULL,
  lat               DECIMAL(10,7) NOT NULL,
  lng               DECIMAL(10,7) NOT NULL,
  height_m          DECIMAL(6,2)  DEFAULT NULL,
  coverage_radius_m INT UNSIGNED  DEFAULT NULL,
  color             VARCHAR(20)   DEFAULT NULL,
  notes             TEXT          DEFAULT NULL,
  is_active         TINYINT(1)    NOT NULL DEFAULT 1,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_parent (parent_id),
  KEY idx_type   (type),
  KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_links (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  from_site_id  INT UNSIGNED NOT NULL,
  to_site_id    INT UNSIGNED NOT NULL,
  type          ENUM('ptp','ptmp','fiber','backhaul') NOT NULL DEFAULT 'ptp',
  label         VARCHAR(120) NOT NULL DEFAULT '',
  capacity_mbps DECIMAL(8,2) DEFAULT NULL,
  frequency     VARCHAR(20)  DEFAULT NULL,
  color         VARCHAR(20)  DEFAULT NULL,
  notes         TEXT         DEFAULT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_from (from_site_id),
  KEY idx_to   (to_site_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS site_id INT UNSIGNED DEFAULT NULL,
  ADD KEY IF NOT EXISTS idx_user_site (site_id);
