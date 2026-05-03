-- Phase 9 — Outage engine.
--
-- Auto-detected outages, scoped to a sector (AP-device offline) for
-- now. Tower / core outages are derivable from sector outages and
-- can be added later without a schema change. Customer notifications
-- and tower-level rollup are deferred to a later phase.
--
-- One row per (scope, scope_id, started_at). Resolution updates the
-- existing row with a resolved_at timestamp; we never delete history.
--
-- Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_03_phase9_outages.sql

CREATE TABLE IF NOT EXISTS outages (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  scope           ENUM('device','sector','tower','core') NOT NULL,
  scope_id        INT UNSIGNED  DEFAULT NULL,
  scope_label     VARCHAR(160)  NOT NULL DEFAULT '',
  status          ENUM('active','resolved') NOT NULL DEFAULT 'active',
  affected_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  cause           VARCHAR(255)  DEFAULT NULL,
  notes           TEXT          DEFAULT NULL,
  started_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at     DATETIME      DEFAULT NULL,
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_outage_status_started (status, started_at),
  KEY idx_outage_scope (scope, scope_id),
  KEY idx_outage_resolved (resolved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
