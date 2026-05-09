-- Phase 35 — Install alignment liveness
--
-- Adds a single column to install_jobs so the alignment endpoint can
-- stamp "last seen aligning" on the open job. The signal_dbm / snr_db
-- columns already exist and now double as the rolling current reading
-- during alignment (admins watching install-view.php see them update),
-- then get locked in at sign-off.
--
-- Idempotent: ADD COLUMN IF NOT EXISTS, safe to apply on a live system.

ALTER TABLE install_jobs
  ADD COLUMN IF NOT EXISTS last_alignment_at DATETIME DEFAULT NULL;
