-- Phase 18 — Scheduled config changes + maintenance windows.
--
-- wireless_change_jobs.scheduled_for: when set, bin/apply-wireless-changes.php
-- skips the row until that time. Lets operators queue 'move VDB-South
-- from 5180 to 5200 MHz at Sunday 03:00' without standing watch.
--
-- maintenance_windows: outage_create() and notify_send() consult this
-- table; outages opened during a covering window get suppressed=1 and
-- skip customer notifications. Useful for planned pole-swaps where the
-- outage is intentional.
--
-- Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_03_phase18_scheduling.sql

ALTER TABLE wireless_change_jobs
  ADD COLUMN IF NOT EXISTS scheduled_for DATETIME DEFAULT NULL,
  ADD KEY IF NOT EXISTS idx_wcj_scheduled (scheduled_for, status);

CREATE TABLE IF NOT EXISTS maintenance_windows (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  scope       ENUM('site','sector','device','tower','core') NOT NULL DEFAULT 'sector',
  scope_id    INT UNSIGNED  NOT NULL,
  starts_at   DATETIME      NOT NULL,
  ends_at     DATETIME      NOT NULL,
  reason      VARCHAR(255)  NOT NULL DEFAULT '',
  notify_customers TINYINT(1) NOT NULL DEFAULT 1,
  created_by  INT UNSIGNED  DEFAULT NULL,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mw_window (starts_at, ends_at),
  KEY idx_mw_scope  (scope, scope_id),
  CONSTRAINT fk_mw_user
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE outages
  ADD COLUMN IF NOT EXISTS suppressed TINYINT(1) NOT NULL DEFAULT 0;
