-- ============================================================================
-- Migration 04 — Foreign keys (referential integrity)   [D2/D4/D5]
-- ============================================================================
-- Adds the member<-record relationships that were previously enforced only in PHP.
-- Safe to run ONLY after migration 03 (InnoDB + indexes) — MyISAM cannot hold FKs.
--
-- ⚠️ RUN IN A MAINTENANCE WINDOW, AFTER A VERIFIED BACKUP.
--
-- Drafted + validated 2026-06-06 against the full local data dump: ZERO orphan rows
-- (non-null ids pointing at a missing member) for every relationship below, so the
-- constraints add cleanly once the `= 0` sentinels are normalized (step 1).
--
-- Two design decisions:
--  * ON DELETE SET NULL — deleting a member must NEVER delete clinical/consultation rows;
--    it just clears the reference. (Members are normally *deactivated* via members.active,
--    not deleted. If you prefer to BLOCK deleting a referenced member, change SET NULL ->
--    RESTRICT.) ON UPDATE CASCADE is harmless (member_id is an immutable PK).
--  * members.specialty_id is deliberately NOT given a FK. `0` is an active "no specialty"
--    sentinel on ~214 members AND the shuffle distinguishes specialty_id = 1 (hospitalist)
--    vs != 1 (subspecialist); NULL-ing those 0s (required for a FK) would change shuffle
--    behaviour. Likewise members.position is left FK-free (Admin = position 0 is intentionally
--    not a row in `position`). Both stay application-level.
--
-- ---- STEP 1: normalize the "none" sentinel 0 -> NULL on the member-reference columns -----
-- (`0` means "no member"; the FK needs NULL. Few rows; semantically a fix — a consultant_id
--  of 0 is neither a real assignment nor in the IS NULL unassigned queue.)
UPDATE picupatients  SET consultant_id      = NULL WHERE consultant_id      = 0;
UPDATE picupatients  SET admitted_by        = NULL WHERE admitted_by        = 0;
UPDATE picupatients  SET trans_discharge_by = NULL WHERE trans_discharge_by = 0;
UPDATE consultations SET consultant_id      = NULL WHERE consultant_id      = 0;
UPDATE consultations SET entered_by_id      = NULL WHERE entered_by_id      = 0;

-- ---- STEP 1b: PRE-FLIGHT orphan check (run on PROD first; each MUST return 0) ------------
-- SELECT COUNT(*) FROM picupatients p   WHERE p.consultant_id      IS NOT NULL AND NOT EXISTS (SELECT 1 FROM members m WHERE m.member_id=p.consultant_id);
-- SELECT COUNT(*) FROM picupatients p   WHERE p.admitted_by        IS NOT NULL AND NOT EXISTS (SELECT 1 FROM members m WHERE m.member_id=p.admitted_by);
-- SELECT COUNT(*) FROM picupatients p   WHERE p.trans_discharge_by IS NOT NULL AND NOT EXISTS (SELECT 1 FROM members m WHERE m.member_id=p.trans_discharge_by);
-- SELECT COUNT(*) FROM consultations c  WHERE c.consultant_id      IS NOT NULL AND NOT EXISTS (SELECT 1 FROM members m WHERE m.member_id=c.consultant_id);
-- SELECT COUNT(*) FROM consultations c  WHERE c.entered_by_id      IS NOT NULL AND NOT EXISTS (SELECT 1 FROM members m WHERE m.member_id=c.entered_by_id);
-- If any is non-zero, clean those rows (set the bad id to NULL) before STEP 2.

-- ---- STEP 2: add the foreign keys --------------------------------------------------------
ALTER TABLE picupatients
  ADD CONSTRAINT fk_picupatients_consultant
    FOREIGN KEY (consultant_id)      REFERENCES members(member_id) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_picupatients_admitted_by
    FOREIGN KEY (admitted_by)        REFERENCES members(member_id) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_picupatients_trans_discharge_by
    FOREIGN KEY (trans_discharge_by) REFERENCES members(member_id) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE consultations
  ADD CONSTRAINT fk_consultations_consultant
    FOREIGN KEY (consultant_id) REFERENCES members(member_id) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_consultations_entered_by
    FOREIGN KEY (entered_by_id) REFERENCES members(member_id) ON DELETE SET NULL ON UPDATE CASCADE;

-- ---- VERIFY (after running) --------------------------------------------------------------
-- SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
--   FROM information_schema.KEY_COLUMN_USAGE
--  WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
--  ORDER BY TABLE_NAME, CONSTRAINT_NAME;   -- expect the 5 fk_* constraints above
-- ------------------------------------------------------------------------------------------
