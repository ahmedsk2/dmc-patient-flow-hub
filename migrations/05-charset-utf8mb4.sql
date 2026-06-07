-- ============================================================================
-- Migration 05 — Charset normalization to utf8mb4 (whole schema)   [DB-02]
-- ============================================================================
-- ⚠️ RUN IN A MAINTENANCE WINDOW, AFTER A VERIFIED BACKUP.
--
-- Makes every table utf8mb4 (full Unicode incl. 4-byte / proper Arabic & accented text)
-- with a single consistent collation, so cross-table text comparisons can't hit an
-- "illegal mix of collations". The big clinical tables (picupatients, consultations,
-- icd10, speciality, consultation_reason) were already utf8mb4 after the migration-03
-- InnoDB pass. This converts the four stragglers:
--   members          latin1_swedish_ci   -> utf8mb4_unicode_ci
--   settings         latin1_swedish_ci   -> utf8mb4_unicode_ci   (single ASCII config row)
--   tbl_token_auth   latin1_swedish_ci   -> utf8mb4_unicode_ci   (ASCII usernames + hashes)
--   countries        utf8mb3_general_ci  -> utf8mb4_unicode_ci   (utf8mb3 -> utf8mb4 is lossless)
--
-- SAFETY — verified on the full data dump (2026-06-06): settings and tbl_token_auth contain
-- ZERO non-ASCII bytes; countries is utf8mb3 (a strict subset of utf8mb4, so widening never
-- loses data); members is TRUE latin1 (its only non-ASCII rows are spam — see below — whose
-- bytes are genuine latin1, NOT utf8-in-latin1). So a plain CONVERT TO CHARACTER SET is
-- correct for all four (do NOT use the binary-intermediate trick — that is only for the
-- utf8-stored-in-latin1 case and would corrupt true-latin1 data).
--
-- Re-confirm on production before running:
--   -- every remaining non-utf8mb4 table should be exactly these four:
--   SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES
--     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_COLLATION NOT LIKE 'utf8mb4%';
--   -- and any non-ASCII members row must be genuine latin1 (readable as-is, NOT only when
--   --  reinterpreted as utf8) — otherwise STOP and ask (utf8-in-latin1 needs a different path):
--   SELECT member_id, full_name,
--          CONVERT(CAST(CONVERT(full_name USING latin1) AS BINARY) USING utf8mb4) AS reinterpret
--     FROM members WHERE full_name REGEXP '[^ -~]' OR member_name REGEXP '[^ -~]';
--
-- SPAM-ACCOUNT CLEANUP (separate finding — see SECURITY-VERIFICATION.md / tracker): the public
-- self-registration was open before the renovation and created spam member rows (Turkish casino
-- text + a bit.ly link). Review and delete them BEFORE converting (then members is pure ASCII):
--   SELECT member_id, member_name, full_name, member_email, active
--     FROM members
--    WHERE full_name LIKE '%bit.ly%' OR full_name LIKE '%bonus%' OR full_name LIKE '%spin%'
--       OR member_name REGEXP '[^ -~]';
--   -- DELETE FROM members WHERE member_id IN (<confirmed spam ids>);
-- (The convert is safe whether or not you delete them first.)
-- ============================================================================

ALTER TABLE members        CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE settings       CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE tbl_token_auth CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE countries      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---- VERIFY (after running) --------------------------------------------------
-- SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES
--   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_COLLATION NOT LIKE 'utf8mb4%';   -- expect ZERO rows
-- Login + remember-me still work (member_password/token hashes are ASCII; charset is unaffected).
-- NOTE: utf8mb4 uses up to 4 bytes/char — if an ALTER errors on an index that is now too long,
-- shorten that index's prefix. These four tables' indexed columns are short enough that this is
-- not expected (validated locally).
-- ------------------------------------------------------------------------------
