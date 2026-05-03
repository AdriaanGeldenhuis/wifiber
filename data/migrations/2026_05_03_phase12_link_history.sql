-- Phase 12 — Link / RF / ethernet time-series history.
--
-- The link-view dashboard (admin/link-view.php in Phase 3) needs:
--   • A 24-hour signal/noise/interference line chart per link
--     → link_health_samples (one row per link per poll)
--   • The blue RF-environment frequency-bar chart per radio
--     → rf_environment_samples (one row per (device, freq) per scan)
--   • The ethernet cable-diag footer
--     → ethernet_health (one row per device per poll)
--
-- All three follow the same fact-table pattern as device_health: one
-- row per poll, indexed by (parent_id, polled_at). bin/poll-wireless.php
-- writes them; retention is enforced by the worker (--retention-days).
--
-- Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_03_phase12_link_history.sql

CREATE TABLE IF NOT EXISTS link_health_samples (
  id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  link_id             INT UNSIGNED  NOT NULL,
  polled_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  signal_local_dbm    SMALLINT      DEFAULT NULL,
  signal_remote_dbm   SMALLINT      DEFAULT NULL,
  noise_local_dbm     SMALLINT      DEFAULT NULL,
  noise_remote_dbm    SMALLINT      DEFAULT NULL,
  snr_local_db        SMALLINT      DEFAULT NULL,
  snr_remote_db       SMALLINT      DEFAULT NULL,
  ccq_pct             DECIMAL(5,2)  DEFAULT NULL,
  tx_rate_mbps        DECIMAL(8,2)  DEFAULT NULL,
  rx_rate_mbps        DECIMAL(8,2)  DEFAULT NULL,
  airtime_local_pct   DECIMAL(5,2)  DEFAULT NULL,
  airtime_remote_pct  DECIMAL(5,2)  DEFAULT NULL,
  throughput_local_mbps  DECIMAL(8,2) DEFAULT NULL,
  throughput_remote_mbps DECIMAL(8,2) DEFAULT NULL,
  capacity_local_mbps    DECIMAL(8,2) DEFAULT NULL,
  capacity_remote_mbps   DECIMAL(8,2) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_lhs_link (link_id, polled_at),
  KEY idx_lhs_polled (polled_at),
  CONSTRAINT fk_lhs_link
    FOREIGN KEY (link_id) REFERENCES wireless_links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rf_environment_samples (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  device_id   INT UNSIGNED  NOT NULL,
  polled_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  freq_mhz    SMALLINT UNSIGNED NOT NULL,
  rssi_dbm    SMALLINT      NOT NULL,
  PRIMARY KEY (id),
  KEY idx_rfe_device (device_id, polled_at),
  KEY idx_rfe_freq (freq_mhz),
  CONSTRAINT fk_rfe_device
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ethernet_health (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  device_id       INT UNSIGNED  NOT NULL,
  polled_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  lan_port        VARCHAR(20)   NOT NULL DEFAULT 'lan0',
  link_speed_mbps SMALLINT UNSIGNED DEFAULT NULL,
  duplex          ENUM('full','half','unknown') NOT NULL DEFAULT 'unknown',
  cable_length_m  SMALLINT UNSIGNED DEFAULT NULL,
  cable_snr_db    DECIMAL(5,2)  DEFAULT NULL,
  pair_a_status   ENUM('ok','open','short','crosstalk','unknown') NOT NULL DEFAULT 'unknown',
  pair_b_status   ENUM('ok','open','short','crosstalk','unknown') NOT NULL DEFAULT 'unknown',
  pair_c_status   ENUM('ok','open','short','crosstalk','unknown') NOT NULL DEFAULT 'unknown',
  pair_d_status   ENUM('ok','open','short','crosstalk','unknown') NOT NULL DEFAULT 'unknown',
  PRIMARY KEY (id),
  KEY idx_eh_device (device_id, polled_at),
  KEY idx_eh_polled (polled_at),
  CONSTRAINT fk_eh_device
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
