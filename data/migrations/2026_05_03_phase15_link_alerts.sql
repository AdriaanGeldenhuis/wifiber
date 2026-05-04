-- Phase 15 — Predictive link-health alerts.
--
-- bin/check-link-health.php (cron, nightly) computes the 7-day signal
-- slope, link-budget vs measured signal, and capacity-saturation forecast
-- for every wireless_link with enough history. Each finding becomes a
-- link_alerts row; resolved automatically on the next nightly run if
-- the regression reverses.
--
-- devices.antenna_gain_dbi feeds the link-budget calc; sane defaults:
-- NanoBeam 5AC ≈ 16 dBi, PowerBeam 5AC ≈ 22 dBi, Rocket sector ≈ 14 dBi.
--
-- Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_03_phase15_link_alerts.sql

CREATE TABLE IF NOT EXISTS link_alerts (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  link_id       INT UNSIGNED  NOT NULL,
  kind          ENUM('signal_drop','link_budget','capacity_saturation') NOT NULL,
  severity      ENUM('info','warn','crit') NOT NULL DEFAULT 'warn',
  observed_db   DECIMAL(6,2)  DEFAULT NULL,
  expected_db   DECIMAL(6,2)  DEFAULT NULL,
  notes         VARCHAR(500)  NOT NULL DEFAULT '',
  ticket_id     INT UNSIGNED  DEFAULT NULL,
  opened_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at   DATETIME      DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_active (link_id, kind, resolved_at),
  KEY idx_la_link (link_id),
  KEY idx_la_open (resolved_at, severity),
  CONSTRAINT fk_la_link
    FOREIGN KEY (link_id) REFERENCES wireless_links(id) ON DELETE CASCADE,
  CONSTRAINT fk_la_ticket
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS antenna_gain_dbi  DECIMAL(4,1) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS antenna_pattern   ENUM('omni','sector90','sector120','dish','panel','other')
                                              NOT NULL DEFAULT 'other';
