-- Phase 34 — Install jobs
--
-- Tracks customer installations as a separate workflow from the user
-- record itself. Lets ops schedule installs ahead, assign technicians,
-- record sign-off and the as-installed signal levels, and keep a per-job
-- history (re-installs, remounts, equipment swaps).
--
-- A customer can have many install_jobs over their lifetime; the most
-- recent non-cancelled row represents "current install state". The user
-- record (status, equipment_mac, sector_id) is left untouched by the
-- workflow — it's a parallel paper trail, not a replacement.
--
-- All columns nullable / defaulted so this is safe to apply on a live
-- system without a backfill: existing customers simply have no jobs.

CREATE TABLE IF NOT EXISTS install_jobs (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id      BIGINT UNSIGNED NOT NULL,
  assigned_to      BIGINT UNSIGNED DEFAULT NULL,
  scheduled_at     DATETIME        DEFAULT NULL,
  status           ENUM('pending','in_progress','completed','cancelled')
                                   NOT NULL DEFAULT 'pending',
  priority         TINYINT UNSIGNED NOT NULL DEFAULT 3,
  notes            TEXT,
  cpe_mac          VARCHAR(17)     NOT NULL DEFAULT '',
  cpe_serial       VARCHAR(64)     NOT NULL DEFAULT '',
  cpe_model        VARCHAR(64)     NOT NULL DEFAULT '',
  signal_dbm       SMALLINT        DEFAULT NULL,
  snr_db           SMALLINT        DEFAULT NULL,
  started_at       DATETIME        DEFAULT NULL,
  completed_at     DATETIME        DEFAULT NULL,
  completed_by     BIGINT UNSIGNED DEFAULT NULL,
  cancelled_at     DATETIME        DEFAULT NULL,
  cancelled_reason VARCHAR(255)    NOT NULL DEFAULT '',
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY install_jobs_customer  (customer_id),
  KEY install_jobs_assigned  (assigned_to),
  KEY install_jobs_status    (status),
  KEY install_jobs_scheduled (scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
