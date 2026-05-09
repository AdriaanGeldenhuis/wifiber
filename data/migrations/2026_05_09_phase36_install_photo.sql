-- Phase 36 — Install photo upload
--
-- Adds a path column on install_jobs so the technician can attach a
-- photo at sign-off (mounted bracket / aimed dish / proof of work).
-- Files live under DATA_DIR/install-photos/ — same pattern used by
-- ticket attachments. Idempotent + nullable so existing rows don't
-- need a backfill.

ALTER TABLE install_jobs
  ADD COLUMN IF NOT EXISTS photo_path VARCHAR(255) NOT NULL DEFAULT '';
