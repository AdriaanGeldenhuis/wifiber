-- Phase 19 — Topology auto-discovery + configuration drift detection.
--
-- bin/discover-topology.php SNMP-walks LLDP / CDP MIBs on every device
-- with credentials, emits site_link_candidates rows for an admin to
-- approve in /admin/topology-review.php. Approved candidates become
-- regular site_links rows.
--
-- bin/check-config-drift.php compares vendor_snapshot_config() output
-- against the DB-of-record (sectors.frequency_mhz, ssid, security,
-- tx_power_dbm). Mismatches → config_drift_alerts row.
--
-- Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_03_phase19_topology.sql

CREATE TABLE IF NOT EXISTS site_link_candidates (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  from_device_id  INT UNSIGNED  NOT NULL,
  to_device_id    INT UNSIGNED  DEFAULT NULL,
  to_mac          VARCHAR(20)   NOT NULL DEFAULT '',
  to_name         VARCHAR(120)  NOT NULL DEFAULT '',
  source          ENUM('lldp','cdp','arp','manual') NOT NULL DEFAULT 'lldp',
  confidence      DECIMAL(3,2)  NOT NULL DEFAULT 0.5,
  raw_json        JSON          DEFAULT NULL,
  observed_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at     DATETIME      DEFAULT NULL,
  reviewed_by     INT UNSIGNED  DEFAULT NULL,
  decision        ENUM('pending','approved','ignored') NOT NULL DEFAULT 'pending',
  PRIMARY KEY (id),
  UNIQUE KEY uniq_pair (from_device_id, to_device_id, to_mac),
  KEY idx_slc_decision (decision, observed_at),
  CONSTRAINT fk_slc_from
    FOREIGN KEY (from_device_id) REFERENCES devices(id) ON DELETE CASCADE,
  CONSTRAINT fk_slc_to
    FOREIGN KEY (to_device_id)   REFERENCES devices(id) ON DELETE SET NULL,
  CONSTRAINT fk_slc_reviewer
    FOREIGN KEY (reviewed_by)    REFERENCES users(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS config_drift_alerts (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  device_id     INT UNSIGNED  NOT NULL,
  sector_id     INT UNSIGNED  DEFAULT NULL,
  field         VARCHAR(60)   NOT NULL,
  expected      VARCHAR(160)  NOT NULL DEFAULT '',
  observed      VARCHAR(160)  NOT NULL DEFAULT '',
  detected_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at   DATETIME      DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_active (device_id, field, resolved_at),
  KEY idx_cda_open (resolved_at, detected_at),
  CONSTRAINT fk_cda_device
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
  CONSTRAINT fk_cda_sector
    FOREIGN KEY (sector_id) REFERENCES sectors(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
