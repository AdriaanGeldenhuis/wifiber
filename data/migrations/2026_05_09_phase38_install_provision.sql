-- Phase 38 — Install provisioning audit trail
--
-- When a tech signs off an install, install_job_complete() now provisions
-- the CPE: creates the devices row, wires up the wireless_links row, and
-- flips users.status from 'lead' to 'active'. These columns let the
-- install_job carry the audit trail of what got auto-created so the
-- install-view page can link straight through, and so re-completing a
-- job (rare, but possible after a cancel/reopen) is idempotent.
--
-- cpe_vendor lets the admin override the vendor that gets inferred from
-- the model string ("LiteBeam" -> ubiquiti, "mAP" -> mikrotik). We keep
-- the column nullable + defaulted to '' so existing rows don't need a
-- backfill — the orchestrator falls back to model-based inference when
-- it's blank.

ALTER TABLE install_jobs
  ADD COLUMN IF NOT EXISTS cpe_vendor VARCHAR(16)     NOT NULL DEFAULT '' AFTER cpe_model,
  ADD COLUMN IF NOT EXISTS device_id  BIGINT UNSIGNED DEFAULT NULL        AFTER photo_path,
  ADD COLUMN IF NOT EXISTS link_id    BIGINT UNSIGNED DEFAULT NULL        AFTER device_id;

ALTER TABLE install_jobs
  ADD KEY IF NOT EXISTS install_jobs_device (device_id),
  ADD KEY IF NOT EXISTS install_jobs_link   (link_id);
