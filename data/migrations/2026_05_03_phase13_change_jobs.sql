-- Phase 13 — Wireless config-change jobs + audit log.
--
-- When an operator changes a frequency, channel width, TX power, SSID
-- or security on /admin/sector-edit.php (or /admin/link-view.php),
-- the request is enqueued here rather than pushed inline. Phase 4's
-- bin/apply-wireless-changes.php picks queued jobs, snapshots the
-- current radio config, applies the new one (CPE first then AP for
-- coordinated freq moves), and rolls back if the link doesn't recover.
--
-- wireless_change_log is the immutable audit trail — one row per
-- successful, failed or rolled-back attempt. Surfaced in /admin/audit.php.
--
-- Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_03_phase13_change_jobs.sql

CREATE TABLE IF NOT EXISTS wireless_change_jobs (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  scope           ENUM('sector','link','device') NOT NULL DEFAULT 'sector',
  scope_id        INT UNSIGNED  NOT NULL,
  requested_by    INT UNSIGNED  DEFAULT NULL,
  payload_json    JSON          NOT NULL,
  snapshot_json   JSON          DEFAULT NULL,
  status          ENUM('queued','applying','applied','failed','rolled_back','cancelled') NOT NULL DEFAULT 'queued',
  attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  started_at      DATETIME      DEFAULT NULL,
  finished_at     DATETIME      DEFAULT NULL,
  error           VARCHAR(500)  NOT NULL DEFAULT '',
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wcj_status_created (status, created_at),
  KEY idx_wcj_scope (scope, scope_id),
  KEY idx_wcj_requester (requested_by),
  CONSTRAINT fk_wcj_user
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wireless_change_log (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  job_id        INT UNSIGNED  DEFAULT NULL,
  scope         ENUM('sector','link','device') NOT NULL DEFAULT 'sector',
  scope_id      INT UNSIGNED  NOT NULL,
  device_id     INT UNSIGNED  DEFAULT NULL,
  actor_user_id INT UNSIGNED  DEFAULT NULL,
  action        VARCHAR(40)   NOT NULL,
  before_json   JSON          DEFAULT NULL,
  after_json    JSON          DEFAULT NULL,
  success       TINYINT(1)    NOT NULL DEFAULT 0,
  error         VARCHAR(500)  NOT NULL DEFAULT '',
  occurred_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wcl_job (job_id),
  KEY idx_wcl_scope (scope, scope_id),
  KEY idx_wcl_device (device_id),
  KEY idx_wcl_occurred (occurred_at),
  CONSTRAINT fk_wcl_job
    FOREIGN KEY (job_id) REFERENCES wireless_change_jobs(id) ON DELETE SET NULL,
  CONSTRAINT fk_wcl_device
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL,
  CONSTRAINT fk_wcl_user
    FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
