-- Phase 17 — Active diagnostics jobs.
--
-- Same shape as wireless_change_jobs so admin/link-view.php and
-- /admin/devices.php can poll the status of a queued speed test /
-- traceroute / sustained ping with the same UI pattern.
--
-- bin/run-diagnostic.php picks queued rows, executes via the device's
-- existing SSH credentials (phpseclib3) or REST adapter, parses the
-- output into result_json, then marks status='done'.
--
-- link_speedtests is the analytics fact table — link-view.php charts
-- a Mbps trend per link from this.
--
-- Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_03_phase17_diagnostics.sql

CREATE TABLE IF NOT EXISTS diagnostic_jobs (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  kind            ENUM('iperf3','traceroute','ping_n','bgp_lookup') NOT NULL,
  scope           ENUM('link','device') NOT NULL DEFAULT 'device',
  scope_id        INT UNSIGNED  NOT NULL,
  payload_json    JSON          DEFAULT NULL,
  result_json     JSON          DEFAULT NULL,
  status          ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
  requested_by    INT UNSIGNED  DEFAULT NULL,
  started_at      DATETIME      DEFAULT NULL,
  finished_at     DATETIME      DEFAULT NULL,
  error           VARCHAR(500)  NOT NULL DEFAULT '',
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_dj_status_created (status, created_at),
  KEY idx_dj_scope (scope, scope_id),
  CONSTRAINT fk_dj_user
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS link_speedtests (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  link_id       INT UNSIGNED  NOT NULL,
  job_id        INT UNSIGNED  DEFAULT NULL,
  mbps_down     DECIMAL(8,2)  DEFAULT NULL,
  mbps_up       DECIMAL(8,2)  DEFAULT NULL,
  jitter_ms     DECIMAL(7,2)  DEFAULT NULL,
  loss_pct      DECIMAL(5,2)  DEFAULT NULL,
  polled_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ls_link (link_id, polled_at),
  CONSTRAINT fk_ls_link
    FOREIGN KEY (link_id) REFERENCES wireless_links(id) ON DELETE CASCADE,
  CONSTRAINT fk_ls_job
    FOREIGN KEY (job_id)  REFERENCES diagnostic_jobs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
