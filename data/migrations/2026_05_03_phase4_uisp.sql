-- Phase 4 — UISP integration: cache tables for sites, devices, data-links and
-- clients pulled from UISP's NMS + CRM APIs, plus uisp_id link columns on the
-- existing manual records so an admin can "adopt" a UISP entity into a
-- manually-managed site/link/user.
--
-- One-time migration. Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_03_phase4_uisp.sql

CREATE TABLE IF NOT EXISTS uisp_sites (
  uisp_id        VARCHAR(64)   NOT NULL,
  name           VARCHAR(160)  NOT NULL,
  address        VARCHAR(255)  DEFAULT NULL,
  lat            DECIMAL(10,7) DEFAULT NULL,
  lng            DECIMAL(10,7) DEFAULT NULL,
  height_m       DECIMAL(6,2)  DEFAULT NULL,
  status         VARCHAR(32)   DEFAULT NULL,
  raw_json       JSON          DEFAULT NULL,
  last_seen_at   DATETIME      DEFAULT NULL,
  synced_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  is_stale       TINYINT(1)    NOT NULL DEFAULT 0,
  PRIMARY KEY (uisp_id),
  KEY idx_uisp_site_synced (synced_at),
  KEY idx_uisp_site_stale  (is_stale)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uisp_devices (
  uisp_id        VARCHAR(64)   NOT NULL,
  uisp_site_id   VARCHAR(64)   DEFAULT NULL,
  name           VARCHAR(160)  NOT NULL DEFAULT '',
  type           VARCHAR(40)   DEFAULT NULL,
  model          VARCHAR(80)   DEFAULT NULL,
  mac            VARCHAR(20)   DEFAULT NULL,
  ip             VARCHAR(45)   DEFAULT NULL,
  role           VARCHAR(40)   DEFAULT NULL,
  status         ENUM('online','offline','unknown') NOT NULL DEFAULT 'unknown',
  signal_dbm     SMALLINT      DEFAULT NULL,
  lat            DECIMAL(10,7) DEFAULT NULL,
  lng            DECIMAL(10,7) DEFAULT NULL,
  raw_json       JSON          DEFAULT NULL,
  last_seen_at   DATETIME      DEFAULT NULL,
  synced_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  is_stale       TINYINT(1)    NOT NULL DEFAULT 0,
  PRIMARY KEY (uisp_id),
  KEY idx_uisp_dev_site   (uisp_site_id),
  KEY idx_uisp_dev_status (status),
  KEY idx_uisp_dev_stale  (is_stale)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uisp_data_links (
  uisp_id              VARCHAR(64)   NOT NULL,
  from_device_uisp_id  VARCHAR(64)   DEFAULT NULL,
  to_device_uisp_id    VARCHAR(64)   DEFAULT NULL,
  frequency            VARCHAR(40)   DEFAULT NULL,
  capacity_mbps        DECIMAL(8,2)  DEFAULT NULL,
  status               VARCHAR(32)   DEFAULT NULL,
  raw_json             JSON          DEFAULT NULL,
  synced_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  is_stale             TINYINT(1)    NOT NULL DEFAULT 0,
  PRIMARY KEY (uisp_id),
  KEY idx_uisp_dl_from  (from_device_uisp_id),
  KEY idx_uisp_dl_to    (to_device_uisp_id),
  KEY idx_uisp_dl_stale (is_stale)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uisp_clients (
  uisp_id           VARCHAR(64)   NOT NULL,
  account_no        VARCHAR(40)   DEFAULT NULL,
  name              VARCHAR(160)  NOT NULL DEFAULT '',
  email             VARCHAR(160)  DEFAULT NULL,
  address_full      VARCHAR(400)  DEFAULT NULL,
  lat               DECIMAL(10,7) DEFAULT NULL,
  lng               DECIMAL(10,7) DEFAULT NULL,
  status            VARCHAR(32)   DEFAULT NULL,
  services_summary  JSON          DEFAULT NULL,
  raw_json          JSON          DEFAULT NULL,
  synced_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  is_stale          TINYINT(1)    NOT NULL DEFAULT 0,
  PRIMARY KEY (uisp_id),
  KEY idx_uisp_client_status (status),
  KEY idx_uisp_client_stale  (is_stale)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sites
  ADD COLUMN IF NOT EXISTS uisp_id VARCHAR(64) DEFAULT NULL,
  ADD UNIQUE KEY IF NOT EXISTS uniq_sites_uisp_id (uisp_id);

ALTER TABLE site_links
  ADD COLUMN IF NOT EXISTS uisp_id VARCHAR(64) DEFAULT NULL,
  ADD UNIQUE KEY IF NOT EXISTS uniq_site_links_uisp_id (uisp_id);

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS uisp_client_id VARCHAR(64) DEFAULT NULL,
  ADD UNIQUE KEY IF NOT EXISTS uniq_users_uisp_client (uisp_client_id);
