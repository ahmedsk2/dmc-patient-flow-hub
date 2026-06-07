-- ============================================================================
-- Migration 07 — patient_diagnosis join table (normalize the diagnosis JSON)  [DB-NORM-1]
-- ============================================================================
-- ADDITIVE and SAFE. picupatients.admissiondiagnosis (a JSON array of icd10 codes) REMAINS the
-- source of truth and is not touched. This creates an additional, fully-derived, rebuildable
-- index table — one row per (admission, diagnosis) element — so diagnosis lookups can finally use
-- an index instead of JSON_CONTAINS (which can't). Nothing in the app depends on it yet; it can be
-- dropped or rebuilt at any time with no data loss.
--
-- Faithful (lossless): every JSON element is preserved with its 1-based position (`seq`), so the
-- original array reconstructs exactly (proven by tools/patient_diagnosis_validate.php — count +
-- per-row round-trip + autoid resolution). On the data dump there are 16,067 elements across
-- 14,827 admissions, 1,418 distinct codes, all matching icd10 (100%).
--
-- REBUILD (after a bulk import, or to refresh): the two DML statements below are idempotent if you
-- first `TRUNCATE patient_diagnosis;` — they re-derive everything from the JSON.
-- Requires MySQL 8.0+ (JSON_TABLE); this schema is InnoDB/8.x after migration 03.
-- ============================================================================

CREATE TABLE IF NOT EXISTS patient_diagnosis (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  picupatient_id INT          NOT NULL,                 -- -> picupatients.ID (one admission episode)
  seq            SMALLINT      NOT NULL,                 -- 1-based position within the JSON array
  icd10_code     VARCHAR(100) NOT NULL,                 -- the code exactly as stored in the JSON
  icd10_autoid   INT          NULL,                     -- resolved icd10.autoid where the code matches
  KEY idx_pd_patient (picupatient_id),
  KEY idx_pd_code    (icd10_code),
  KEY idx_pd_autoid  (icd10_autoid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: expand each admission's JSON array into rows (preserving order via FOR ORDINALITY).
INSERT INTO patient_diagnosis (picupatient_id, seq, icd10_code)
SELECT p.ID, jt.seq, jt.code
FROM picupatients p,
     JSON_TABLE(p.admissiondiagnosis, '$[*]'
        COLUMNS (seq FOR ORDINALITY, code VARCHAR(100) PATH '$')) jt
WHERE p.admissiondiagnosis IS NOT NULL;

-- Resolve the integer icd10 key where the code matches (VARCHAR '=' ignores trailing padding).
UPDATE patient_diagnosis pd
  JOIN icd10 i ON i.id = pd.icd10_code
   SET pd.icd10_autoid = i.autoid;
