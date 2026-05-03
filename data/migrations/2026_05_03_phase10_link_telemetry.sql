-- Phase 10 — Link telemetry columns.
--
-- Phase 7 landed the wireless_links / sectors / devices tables but only
-- with the bare minimum of "current state" columns. This phase fleshes
-- them out with everything the UISP-equivalent link-view dashboard
-- needs: per-side TX power, per-side airtime, per-side throughput vs.
-- capacity, wireless mode, security, SSID, MAC pair, modulation,
-- ethernet cable diagnostics. No worker changes — Phase 11/12 wire up
-- the credential store and history tables, Phase 2's vendor adapters
-- populate everything.
--
-- Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_03_phase10_link_telemetry.sql

ALTER TABLE wireless_links
  ADD COLUMN IF NOT EXISTS frequency_mhz         SMALLINT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS channel_width_mhz     SMALLINT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS tx_power_dbm_local    TINYINT       DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS tx_power_dbm_remote   TINYINT       DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS signal_dbm_remote     SMALLINT      DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS noise_dbm_remote      SMALLINT      DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS snr_db_remote         SMALLINT      DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS airtime_local_pct     DECIMAL(5,2)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS airtime_remote_pct    DECIMAL(5,2)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS capacity_local_mbps   DECIMAL(8,2)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS capacity_remote_mbps  DECIMAL(8,2)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS throughput_local_mbps  DECIMAL(8,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS throughput_remote_mbps DECIMAL(8,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS wireless_mode         ENUM('802.11n','802.11ac','802.11ax','airmax_ac','airmax_n','other') NOT NULL DEFAULT 'other',
  ADD COLUMN IF NOT EXISTS security              ENUM('open','wep','wpa','wpa2','wpa3','other') NOT NULL DEFAULT 'wpa2',
  ADD COLUMN IF NOT EXISTS ssid                  VARCHAR(64)   NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS ap_mac                VARCHAR(20)   NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS station_mac           VARCHAR(20)   NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS expected_rate_mbps    DECIMAL(8,2)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS modulation            VARCHAR(20)   NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS uptime_seconds        INT UNSIGNED  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS tx_bytes              BIGINT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS rx_bytes              BIGINT UNSIGNED DEFAULT NULL;

ALTER TABLE wireless_links ADD KEY IF NOT EXISTS idx_wl_freq (frequency_mhz);
ALTER TABLE wireless_links ADD KEY IF NOT EXISTS idx_wl_ssid (ssid);

ALTER TABLE sectors
  ADD COLUMN IF NOT EXISTS ssid                VARCHAR(64)  NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS security            ENUM('open','wep','wpa','wpa2','wpa3','other') NOT NULL DEFAULT 'wpa2',
  ADD COLUMN IF NOT EXISTS wpa_key_enc         BLOB         DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS wireless_mode       ENUM('802.11n','802.11ac','802.11ax','airmax_ac','airmax_n','other') NOT NULL DEFAULT 'airmax_ac',
  ADD COLUMN IF NOT EXISTS tdd_framing         VARCHAR(20)  NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS airmax_ac_priority  ENUM('low','medium','high','none') NOT NULL DEFAULT 'medium',
  ADD COLUMN IF NOT EXISTS dfs_enabled         TINYINT(1)   NOT NULL DEFAULT 1;

ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS lan_speed_mbps    SMALLINT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS cable_length_m    SMALLINT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS cable_snr_db      DECIMAL(5,2)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS firmware_channel  ENUM('release','beta','custom') NOT NULL DEFAULT 'release',
  ADD COLUMN IF NOT EXISTS expected_firmware VARCHAR(60)   NOT NULL DEFAULT '';

ALTER TABLE device_health
  ADD COLUMN IF NOT EXISTS airtime_pct      DECIMAL(5,2)   DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS capacity_mbps    DECIMAL(8,2)   DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS throughput_mbps  DECIMAL(8,2)   DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS firmware         VARCHAR(60)    NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS tx_bytes         BIGINT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS rx_bytes         BIGINT UNSIGNED DEFAULT NULL;
