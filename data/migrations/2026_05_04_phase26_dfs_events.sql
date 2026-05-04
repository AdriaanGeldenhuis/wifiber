-- Phase 26 — DFS radar-event tracking for the frequency planner.
--
-- Most regulatory regimes require dynamic frequency selection (DFS) on
-- the 5 GHz weather/radar bands: when the radio detects radar pulses
-- it has to vacate the channel for ~30 minutes (DFS hold-down) before
-- it can attempt to use it again. AirOS / RouterOS / Cambium all
-- expose this — when they do, a vendor adapter writes a row here so
-- the frequency planner can keep the affected channel out of any
-- recommendation until blocked_until passes.
--
-- This is forward-looking infrastructure. The static IS_DFS check in
-- auth/wireless.php (5260–5320 + 5500–5700 MHz, regulatory) is what
-- the planner uses today; the table provides the live override path
-- when vendor adapters start reporting actual detections.
--
-- Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_04_phase26_dfs_events.sql

CREATE TABLE IF NOT EXISTS dfs_channel_events (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  device_id         INT UNSIGNED  DEFAULT NULL,
  freq_mhz          SMALLINT UNSIGNED NOT NULL,
  channel_width_mhz SMALLINT UNSIGNED NOT NULL DEFAULT 20,
  detected_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  blocked_until     DATETIME      NOT NULL,
  source            ENUM('vendor','manual','rule') NOT NULL DEFAULT 'vendor',
  notes             VARCHAR(255)  NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_dfs_active (freq_mhz, blocked_until),
  KEY idx_dfs_device (device_id, detected_at),
  CONSTRAINT fk_dfs_device
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
