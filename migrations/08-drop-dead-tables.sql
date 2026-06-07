-- ============================================================================
-- Migration 08 — drop dead/unused schema: consultation_details, Notes   [SIMP-04,05]
-- ============================================================================
-- Both tables are never read or written by ANY code — verified by a repo-wide grep (the only
-- "Notes" match is the CSS icon class `fa-notes-medical`, not the table). They are remnants of a
-- planned-but-unbuilt notes / daily-consult-check feature (see CLAUDE.md "Dead schema").
--   consultation_details — may hold a few inert sample rows in some environments (3 in the demo
--                          dump; 0 in the current production schema).
--   Notes                — empty in the demo; ABSENT from the current production schema.
--
-- RUN IN A MAINTENANCE WINDOW, AFTER A VERIFIED BACKUP. `IF EXISTS` makes this safe where a table
-- is already absent (so it's a no-op on production for `Notes`). Pre-check (should both be 0/absent
-- and unreferenced) before running:
--   SELECT table_name, table_rows FROM information_schema.tables
--     WHERE table_schema = DATABASE() AND table_name IN ('consultation_details','Notes');
-- ============================================================================

DROP TABLE IF EXISTS consultation_details;
DROP TABLE IF EXISTS `Notes`;
