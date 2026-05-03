-- Phase 7 — Sectors and wireless links.
--
-- A sector is the AP-on-a-tower configuration: where it's pointed
-- (azimuth + beamwidth), what frequency it's on, and how much power it
-- pushes. One tower has many sectors; one sector is driven by one AP
-- device. Live metrics (noise floor, current client count, utilisation)
-- are NOT stored here — those go into rf_samples in Phase 8 once the
-- polling worker is wired up.
--
-- A wireless_link is the AP-to-CPE relationship: who's on whom, with
-- the most-recent signal/SNR/health values. Schema lands now; the
-- Phase 3 polling worker will fill it. Time-series history will live
-- in a separate wireless_link_samples table later.
--
-- Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_03_phase7_sectors.sql

CREATE TABLE IF NOT EXISTS sectors (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  tower_id          INT UNSIGNED  NOT NULL,
  ap_device_id      INT UNSIGNED  DEFAULT NULL,
  name              VARCHAR(120)  NOT NULL,
  azimuth_deg       SMALLINT UNSIGNED DEFAULT NULL,
  beamwidth_deg     SMALLINT UNSIGNED DEFAULT NULL,
  band              ENUM('2.4GHz','5GHz','6GHz','60GHz','other') NOT NULL DEFAULT '5GHz',
  frequency_mhz     SMALLINT UNSIGNED DEFAULT NULL,
  channel_width_mhz SMALLINT UNSIGNED DEFAULT NULL,
  tx_power_dbm      TINYINT       DEFAULT NULL,
  max_clients       SMALLINT UNSIGNED DEFAULT NULL,
  notes             TEXT          DEFAULT NULL,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sec_tower  (tower_id),
  KEY idx_sec_device (ap_device_id),
  KEY idx_sec_band   (band),
  KEY idx_sec_freq   (frequency_mhz),
  CONSTRAINT fk_sec_tower
    FOREIGN KEY (tower_id) REFERENCES sites(id) ON DELETE CASCADE,
  CONSTRAINT fk_sec_device
    FOREIGN KEY (ap_device_id) REFERENCES devices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wireless_links (
  id                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  ap_device_id       INT UNSIGNED  NOT NULL,
  cpe_device_id      INT UNSIGNED  DEFAULT NULL,
  sector_id          INT UNSIGNED  DEFAULT NULL,
  customer_id        INT UNSIGNED  DEFAULT NULL,
  signal_dbm         SMALLINT      DEFAULT NULL,
  noise_dbm          SMALLINT      DEFAULT NULL,
  snr_db             SMALLINT      DEFAULT NULL,
  ccq_pct            DECIMAL(5,2)  DEFAULT NULL,
  tx_rate_mbps       DECIMAL(8,2)  DEFAULT NULL,
  rx_rate_mbps       DECIMAL(8,2)  DEFAULT NULL,
  distance_km        DECIMAL(6,3)  DEFAULT NULL,
  health_score       TINYINT UNSIGNED DEFAULT NULL,
  last_evaluated_at  DATETIME      DEFAULT NULL,
  created_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_link_ap_cpe (ap_device_id, cpe_device_id),
  KEY idx_wl_ap     (ap_device_id),
  KEY idx_wl_cpe    (cpe_device_id),
  KEY idx_wl_sector (sector_id),
  KEY idx_wl_user   (customer_id),
  CONSTRAINT fk_wl_ap
    FOREIGN KEY (ap_device_id)  REFERENCES devices(id) ON DELETE CASCADE,
  CONSTRAINT fk_wl_cpe
    FOREIGN KEY (cpe_device_id) REFERENCES devices(id) ON DELETE SET NULL,
  CONSTRAINT fk_wl_sector
    FOREIGN KEY (sector_id)     REFERENCES sectors(id) ON DELETE SET NULL,
  CONSTRAINT fk_wl_user
    FOREIGN KEY (customer_id)   REFERENCES users(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
