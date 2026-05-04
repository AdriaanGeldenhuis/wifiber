-- Phase 27 — FreeRADIUS-compatible AAA backend.
--
-- These tables let an external FreeRADIUS server (rlm_sql / mysql) point
-- straight at this database without us shipping our own RADIUS daemon.
-- Column names and types match FreeRADIUS's stock mysql/schema.sql so
-- any PR pointing at our DB Just Works™.
--
-- Table summary
--   nas             — NAS devices that may talk RADIUS (one row per BNG / hAP)
--   radcheck        — per-user check attributes (e.g. Cleartext-Password)
--   radreply        — per-user reply attributes (e.g. Mikrotik-Rate-Limit)
--   radgroupcheck   — per-group check attributes
--   radgroupreply   — per-group reply attributes (suspended → WISPr-Redirect)
--   radusergroup    — username → group mapping (active|suspended|disconnected)
--   radacct         — accounting sessions (start/stop/interim)
--   radpostauth    — post-auth log (accept/reject for forensics)
--
-- Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_04_phase27_radius.sql

-- -------------------------------------------------------------------------
-- nas — NAS devices we accept Access-Request / Accounting-Request from.
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS nas (
  id          INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  nasname     VARCHAR(128) NOT NULL,
  shortname   VARCHAR(32)  DEFAULT NULL,
  type        VARCHAR(30)  DEFAULT 'other',
  ports       INT(5) DEFAULT NULL,
  secret      VARCHAR(60)  NOT NULL DEFAULT 'secret',
  server      VARCHAR(64)  DEFAULT NULL,
  community   VARCHAR(50)  DEFAULT NULL,
  description VARCHAR(200) DEFAULT NULL,
  pod_port    INT(5) NOT NULL DEFAULT 3799,
  device_id   INT UNSIGNED DEFAULT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY nas_nasname (nasname),
  CONSTRAINT fk_nas_device FOREIGN KEY (device_id)
    REFERENCES devices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- radcheck — per-user check attributes.
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS radcheck (
  id        INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  username  VARCHAR(64) NOT NULL DEFAULT '',
  attribute VARCHAR(64) NOT NULL DEFAULT '',
  op        CHAR(2)     NOT NULL DEFAULT '==',
  value     VARCHAR(253) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY username (username(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- radreply — per-user reply attributes.
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS radreply (
  id        INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  username  VARCHAR(64) NOT NULL DEFAULT '',
  attribute VARCHAR(64) NOT NULL DEFAULT '',
  op        CHAR(2)     NOT NULL DEFAULT '=',
  value     VARCHAR(253) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY username (username(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- radgroupcheck — per-group check attributes (rare; here for completeness).
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS radgroupcheck (
  id        INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  groupname VARCHAR(64) NOT NULL DEFAULT '',
  attribute VARCHAR(64) NOT NULL DEFAULT '',
  op        CHAR(2)     NOT NULL DEFAULT '==',
  value     VARCHAR(253) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY groupname (groupname(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- radgroupreply — per-group reply attributes (e.g. suspended group sets
-- WISPr-Redirect-URL → /account/billing.php).
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS radgroupreply (
  id        INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  groupname VARCHAR(64) NOT NULL DEFAULT '',
  attribute VARCHAR(64) NOT NULL DEFAULT '',
  op        CHAR(2)     NOT NULL DEFAULT '=',
  value     VARCHAR(253) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY groupname (groupname(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- radusergroup — username → group mapping.  We use four canonical groups:
--   active        — full speed (rate-limit comes from radreply)
--   suspended     — captive-portal redirect, no traffic
--   disconnected  — Auth-Type := Reject
--   trial         — reserved (not used yet)
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS radusergroup (
  username  VARCHAR(64) NOT NULL DEFAULT '',
  groupname VARCHAR(64) NOT NULL DEFAULT '',
  priority  INT(11)     NOT NULL DEFAULT 1,
  KEY username (username(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- radacct — accounting sessions.  Open sessions have acctstoptime IS NULL.
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS radacct (
  radacctid          BIGINT(21) NOT NULL AUTO_INCREMENT,
  acctsessionid      VARCHAR(64)  NOT NULL DEFAULT '',
  acctuniqueid       VARCHAR(32)  NOT NULL DEFAULT '',
  username           VARCHAR(64)  NOT NULL DEFAULT '',
  groupname          VARCHAR(64)  NOT NULL DEFAULT '',
  realm              VARCHAR(64)  DEFAULT '',
  nasipaddress       VARCHAR(15)  NOT NULL DEFAULT '',
  nasportid          VARCHAR(32)  DEFAULT NULL,
  nasporttype        VARCHAR(32)  DEFAULT NULL,
  acctstarttime      DATETIME     DEFAULT NULL,
  acctupdatetime     DATETIME     DEFAULT NULL,
  acctstoptime       DATETIME     DEFAULT NULL,
  acctinterval       INT(12)      DEFAULT NULL,
  acctsessiontime    INT(12) UNSIGNED DEFAULT NULL,
  acctauthentic      VARCHAR(32)  DEFAULT NULL,
  connectinfo_start  VARCHAR(50)  DEFAULT NULL,
  connectinfo_stop   VARCHAR(50)  DEFAULT NULL,
  acctinputoctets    BIGINT(20)   DEFAULT NULL,
  acctoutputoctets   BIGINT(20)   DEFAULT NULL,
  calledstationid    VARCHAR(50)  NOT NULL DEFAULT '',
  callingstationid   VARCHAR(50)  NOT NULL DEFAULT '',
  acctterminatecause VARCHAR(32)  NOT NULL DEFAULT '',
  servicetype        VARCHAR(32)  DEFAULT NULL,
  framedprotocol     VARCHAR(32)  DEFAULT NULL,
  framedipaddress    VARCHAR(15)  NOT NULL DEFAULT '',
  PRIMARY KEY (radacctid),
  UNIQUE KEY acctuniqueid (acctuniqueid),
  KEY username (username),
  KEY framedipaddress (framedipaddress),
  KEY acctsessionid (acctsessionid),
  KEY acctsessiontime (acctsessiontime),
  KEY acctstarttime (acctstarttime),
  KEY acctstoptime (acctstoptime),
  KEY nasipaddress (nasipaddress)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- radpostauth — post-auth log (accept/reject events).
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS radpostauth (
  id       INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(64) NOT NULL DEFAULT '',
  pass     VARCHAR(64) NOT NULL DEFAULT '',
  reply    VARCHAR(32) NOT NULL DEFAULT '',
  authdate TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY username (username(32)),
  KEY authdate (authdate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- Seed the canonical groups so a brand-new install has sensible behaviour
-- before an admin touches the RADIUS page.  Suspended users are redirected
-- to /account/billing.php via WISPr; disconnected users are rejected.
-- -------------------------------------------------------------------------
INSERT IGNORE INTO radgroupcheck (groupname, attribute, op, value) VALUES
  ('disconnected', 'Auth-Type', ':=', 'Reject');

INSERT IGNORE INTO radgroupreply (groupname, attribute, op, value) VALUES
  ('suspended', 'WISPr-Redirection-URL', ':=', 'http://billing.local/account/billing.php'),
  ('suspended', 'Mikrotik-Rate-Limit',   ':=', '256k/256k'),
  ('disconnected', 'Reply-Message',      ':=', 'Account disconnected — contact billing.');

-- -------------------------------------------------------------------------
-- Tie our customer rows to a RADIUS username.  We default to the existing
-- users.username column but expose an override so an account can carry a
-- legacy PPPoE login imported from Splynx/UISP without renaming the portal
-- account.  service_password_enc is what gets written to radcheck on
-- provision (encrypted at rest with the same secretbox key the device
-- credentials use).
-- -------------------------------------------------------------------------
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS radius_username       VARCHAR(64)    DEFAULT NULL AFTER username;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS service_password_enc  VARBINARY(255) DEFAULT NULL AFTER password_hash;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS radius_group          VARCHAR(32)    NOT NULL DEFAULT 'active' AFTER status;

ALTER TABLE users
  ADD UNIQUE KEY IF NOT EXISTS uq_users_radius_username (radius_username);
