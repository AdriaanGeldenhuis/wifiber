-- Phase 11 — Per-device credentials store.
--
-- Vendor adapters (auth/vendors/airos.php, routeros.php, cambium.php,
-- mimosa.php) need a username, password / SSH key / SNMP community to
-- talk to each radio. Credentials are encrypted at rest using
-- sodium_crypto_secretbox keyed by WIFIBER_DEVICE_KEY in
-- data/db.local.php (set via auth/helpers.php::encrypt_secret).
--
-- One credential row per (device_id, scheme) tuple — a single device
-- can have e.g. an HTTPS login (for AirOS status.cgi) and an SSH login
-- (for the same box's CLI).
--
-- Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_03_phase11_credentials.sql

CREATE TABLE IF NOT EXISTS device_credentials (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  device_id         INT UNSIGNED  NOT NULL,
  scheme            ENUM('http','https','ssh','snmpv2','snmpv3','api') NOT NULL DEFAULT 'https',
  username          VARCHAR(80)   NOT NULL DEFAULT '',
  password_enc      BLOB          DEFAULT NULL,
  ssh_key_enc       BLOB          DEFAULT NULL,
  snmp_community_enc BLOB         DEFAULT NULL,
  api_token_enc     BLOB          DEFAULT NULL,
  port              SMALLINT UNSIGNED DEFAULT NULL,
  verify_tls        TINYINT(1)    NOT NULL DEFAULT 0,
  last_auth_ok_at   DATETIME      DEFAULT NULL,
  last_auth_error   VARCHAR(255)  NOT NULL DEFAULT '',
  consecutive_fails SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  notes             VARCHAR(255)  NOT NULL DEFAULT '',
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_dev_scheme (device_id, scheme),
  KEY idx_dc_device (device_id),
  KEY idx_dc_last_ok (last_auth_ok_at),
  CONSTRAINT fk_dc_device
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
