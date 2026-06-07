-- ============================================================================
-- Migration 06 — Multi-factor auth (TOTP) columns on members          [SEC-MFA]
-- ============================================================================
-- Additive and SAFE: three nullable columns, all defaulting to NULL, used only by the
-- new MFA code paths (mfa.php, mfa-setup.php, the login verification step). Until a user
-- enrolls, mfa_secret stays NULL and behaviour is unchanged — so this migration can be
-- applied at any time, ahead of the application code, with zero impact on existing logins.
--
-- Rollout policy (chosen): OPT-IN. Enforcement is OFF by default and is gated by a
-- Control-Page setting (see migration note below / settings table), so applying this
-- migration does NOT force MFA on anyone and cannot lock out clinicians.
--
-- Columns:
--   mfa_secret         the user's TOTP shared secret, AES-256-GCM-encrypted at rest
--                      (base64 of iv|tag|ciphertext) by mfa.php using MFA_KEY. NULL = not
--                      enrolled. The DB never holds a plaintext secret; the pending secret
--                      during enrollment lives only in the PHP session until confirmed.
--   mfa_recovery_codes JSON array of bcrypt hashes of single-use recovery codes (the
--                      plaintext codes are shown to the user once, at enrollment).
--   mfa_enrolled_at    timestamp enrollment completed (audit / "MFA active since").
--
-- RUN ONCE in a maintenance window (MySQL has no ADD COLUMN IF NOT EXISTS; re-running after a
-- successful apply errors harmlessly with "Duplicate column name" and changes nothing). Pre-check:
--   SELECT COLUMN_NAME FROM information_schema.COLUMNS
--     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND COLUMN_NAME LIKE 'mfa%';
-- ============================================================================

ALTER TABLE members
  ADD COLUMN mfa_secret         VARCHAR(255) NULL DEFAULT NULL AFTER member_password,
  ADD COLUMN mfa_recovery_codes TEXT         NULL DEFAULT NULL AFTER mfa_secret,
  ADD COLUMN mfa_enrolled_at    DATETIME     NULL DEFAULT NULL AFTER mfa_recovery_codes;

-- Optional enforcement flag (read by the login flow; default 0 = opt-in, no enforcement).
-- Stored in the single-row settings table alongside the other operational thresholds.
-- Values: 0 = optional (opt-in), 1 = required for admins (position=0), 2 = required for all.
ALTER TABLE settings
  ADD COLUMN mfa_enforcement TINYINT NOT NULL DEFAULT 0;
