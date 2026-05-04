-- Phase 24 — Device extras: running-config snapshots and firmware
-- end-of-life / end-of-support tracking. Closes the device-side gaps
-- identified in the network-integration audit.
--
-- device_configs: nightly snapshot of every reachable device's running
-- config, written by bin/backup-device-configs.php. Indexed by
-- (device_id, captured_at) so the operator can scrub history; the
-- sha256 column lets us cheaply detect "did this device's config
-- change since the last snapshot?" without diffing the body.
--
-- firmware_eol: small curated table of vendor+model+firmware patterns
-- with their EOL/EOS dates. version_match is a SQL LIKE pattern
-- (e.g. 'v8.7.%') so a single row can cover a release series. Joined
-- against devices.firmware in auth/devices.php to flag at-risk gear.
--
-- Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_04_phase24_device_extras.sql

CREATE TABLE IF NOT EXISTS device_configs (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  device_id     INT UNSIGNED  NOT NULL,
  captured_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  captured_via  ENUM('cron','manual','pre-change','post-change') NOT NULL DEFAULT 'cron',
  vendor        ENUM('mikrotik','ubiquiti','cambium','mimosa','other') NOT NULL DEFAULT 'other',
  config_sha256 CHAR(64)      NOT NULL,
  config_body   MEDIUMTEXT    NOT NULL,
  size_bytes    INT UNSIGNED  NOT NULL DEFAULT 0,
  taken_by      INT UNSIGNED  DEFAULT NULL,
  notes         VARCHAR(255)  NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_dc_device (device_id, captured_at),
  KEY idx_dc_hash   (device_id, config_sha256),
  CONSTRAINT fk_dc_device
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
  CONSTRAINT fk_dc_taker
    FOREIGN KEY (taken_by)  REFERENCES users(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS firmware_eol (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  vendor        ENUM('mikrotik','ubiquiti','cambium','mimosa','other') NOT NULL,
  model_match   VARCHAR(120)  NOT NULL DEFAULT '%',
  version_match VARCHAR(120)  NOT NULL,
  eol_date      DATE          DEFAULT NULL,
  eos_date      DATE          DEFAULT NULL,
  severity      ENUM('info','warn','critical') NOT NULL DEFAULT 'warn',
  notes         VARCHAR(255)  NOT NULL DEFAULT '',
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fe_vendor (vendor, model_match)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed a handful of well-known EOL/EOS rows so the warning surfaces
-- something useful out of the box. Operators can edit / extend via
-- admin/firmware-eol.php (Phase 25 polish).
INSERT IGNORE INTO firmware_eol (vendor, model_match, version_match, eol_date, eos_date, severity, notes) VALUES
  ('ubiquiti', '%', 'v5.5.%',  '2017-12-31', '2018-12-31', 'critical', 'AirOS 5.5 — long EOL, contains WPA flaw'),
  ('ubiquiti', '%', 'v6.0.%',  '2019-06-30', '2020-06-30', 'critical', 'AirOS 6 — replaced by AirOS 8'),
  ('ubiquiti', '%', 'v7.%',    '2020-12-31', '2021-12-31', 'warn',     'AirOS 7 — superseded by AirOS 8.7'),
  ('ubiquiti', '%', 'v8.5.%',  '2022-12-31', '2023-12-31', 'warn',     'AirOS 8.5 — recommended upgrade to 8.7+'),
  ('mikrotik', '%', '6.4%',    '2022-06-30', '2023-06-30', 'warn',     'RouterOS 6.4x — long-term branch but EOL'),
  ('mikrotik', '%', '6.3%',    '2021-06-30', '2022-06-30', 'critical', 'RouterOS 6.3x — multiple CVEs'),
  ('mikrotik', '%', '6.2%',    '2020-06-30', '2021-06-30', 'critical', 'RouterOS 6.2x — CVE-2018-14847 family'),
  ('cambium',  '%', '15.%',    '2022-12-31', '2023-12-31', 'warn',     'Cambium 15.x — replaced by 16+ series'),
  ('mimosa',   '%', '2.%',     '2022-12-31', '2023-12-31', 'warn',     'Mimosa 2.x — older field firmware');
