-- Phase 33 — Link-view dashboard extras.
--
-- Fills the last gaps in wireless_links / devices that the UISP-style
-- link dashboard surfaces: per-MIMO-chain signal levels (so the SIGNAL
-- card can show "-48 (-51/-52) Δ1 dBm" with min/max delta), MCS / data
-- rate index 1x..8x for the rate-strength bar, the per-link TDD framing
-- label, the remote management IP that the AP sees for the CPE, and the
-- network mode (Bridge / Router / NAT) on devices.
--
-- All columns nullable / defaulted so the migration is rewrite-free and
-- existing vendor adapters keep working unchanged. New adapters can
-- write the extra fields opportunistically.
--
-- Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_08_phase33_link_view_extras.sql

ALTER TABLE wireless_links
  ADD COLUMN IF NOT EXISTS chain0_signal_dbm_local   SMALLINT     DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS chain1_signal_dbm_local   SMALLINT     DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS chain0_signal_dbm_remote  SMALLINT     DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS chain1_signal_dbm_remote  SMALLINT     DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS rx_mcs_index_local        TINYINT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS rx_mcs_index_remote       TINYINT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS max_mcs_index             TINYINT UNSIGNED NOT NULL DEFAULT 8,
  ADD COLUMN IF NOT EXISTS modulation_label          VARCHAR(32)  NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS tdd_framing               VARCHAR(20)  NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS connection_time_seconds   INT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS remote_ip                 VARCHAR(45)  NOT NULL DEFAULT '';

ALTER TABLE link_health_samples
  ADD COLUMN IF NOT EXISTS chain0_signal_dbm_local   SMALLINT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS chain1_signal_dbm_local   SMALLINT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS chain0_signal_dbm_remote  SMALLINT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS chain1_signal_dbm_remote  SMALLINT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS rx_mcs_index_local        TINYINT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS rx_mcs_index_remote       TINYINT UNSIGNED DEFAULT NULL;

ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS network_mode  ENUM('bridge','router','nat','switch','unknown')
                                          NOT NULL DEFAULT 'unknown';
