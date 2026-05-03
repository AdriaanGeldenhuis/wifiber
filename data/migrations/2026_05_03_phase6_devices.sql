-- Phase 6 — Native device model. Replaces the deleted UISP cache with
-- first-class records for routers, APs, CPEs, switches and PoP gear.
--
-- Two tables:
--   devices        — one row per managed device (manual entry for now;
--                    Phase 3 will add live polling against this table).
--   device_health  — fact table, one row per poll cycle. Empty until
--                    Phase 3 plugs in the worker — schema lands now so
--                    the worker has nothing to invent later.
--
-- Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_03_phase6_devices.sql

CREATE TABLE IF NOT EXISTS devices (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  site_id       INT UNSIGNED  DEFAULT NULL,
  name          VARCHAR(120)  NOT NULL,
  vendor        ENUM('mikrotik','ubiquiti','cambium','mimosa','other') NOT NULL DEFAULT 'other',
  model         VARCHAR(80)   NOT NULL DEFAULT '',
  role          ENUM('ap','cpe','router','switch','backhaul','ups','other') NOT NULL DEFAULT 'other',
  serial        VARCHAR(80)   NOT NULL DEFAULT '',
  mac           VARCHAR(20)   NOT NULL DEFAULT '',
  mgmt_ip       VARCHAR(45)   NOT NULL DEFAULT '',
  mgmt_port     SMALLINT UNSIGNED DEFAULT NULL,
  firmware      VARCHAR(60)   NOT NULL DEFAULT '',
  status        ENUM('online','offline','unknown','retired') NOT NULL DEFAULT 'unknown',
  last_seen_at  DATETIME      DEFAULT NULL,
  notes         TEXT          DEFAULT NULL,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_dev_site   (site_id),
  KEY idx_dev_status (status),
  KEY idx_dev_role   (role),
  KEY idx_dev_vendor (vendor),
  KEY idx_dev_mac    (mac),
  KEY idx_dev_serial (serial),
  CONSTRAINT fk_devices_site
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_health (
  id              INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  device_id       INT UNSIGNED   NOT NULL,
  polled_at       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status          ENUM('online','offline','unknown') NOT NULL DEFAULT 'unknown',
  uptime_seconds  INT UNSIGNED   DEFAULT NULL,
  cpu_pct         DECIMAL(5,2)   DEFAULT NULL,
  mem_pct         DECIMAL(5,2)   DEFAULT NULL,
  rtt_ms          DECIMAL(7,2)   DEFAULT NULL,
  signal_dbm      SMALLINT       DEFAULT NULL,
  noise_dbm       SMALLINT       DEFAULT NULL,
  ccq_pct         DECIMAL(5,2)   DEFAULT NULL,
  tx_rate_mbps    DECIMAL(8,2)   DEFAULT NULL,
  rx_rate_mbps    DECIMAL(8,2)   DEFAULT NULL,
  client_count    SMALLINT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_dh_device (device_id, polled_at),
  KEY idx_dh_polled (polled_at),
  CONSTRAINT fk_dh_device
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
