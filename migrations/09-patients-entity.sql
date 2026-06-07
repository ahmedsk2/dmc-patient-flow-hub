-- ============================================================================
-- Migration 09 — canonical `patients` entity (one row per MRN)          [DB-NORM-2 / D4]
-- ============================================================================
-- ADDITIVE and SAFE. picupatients stays row-per-admission and authoritative; this adds a derived,
-- rebuildable canonical-patient table (one row per distinct, trimmed, non-blank MRN) with the
-- demographics from that patient's LATEST admission + admission span/count. No code reads it yet —
-- it is forward-looking infrastructure for patient-centric queries / a future re-platform, exactly
-- like patient_diagnosis (migration 07). Drop or rebuild any time with no data loss.
--
-- ⚠️ Two known limitations (documented, not silent):
--   1. DIRTY MRNs: on the current production data ~112 distinct MRN values are non-numeric or
--      >11 chars (names/beds/placeholders entered as an MRN). Each becomes its own spurious
--      "patient" row until the maintainer's prod MRN clean-up (tracked separately). MRNs are
--      grouped by TRIM(MRN) so pure whitespace variants merge; blank/NULL MRN admissions (≈4) are
--      excluded (they cannot form a patient identity).
--   2. NOT YET WIRED IN: no application code uses this table; adopting it (linking
--      picupatients.MRN -> patients) is a separate, larger change best done behind the src/ layer.
--
-- RUN ONCE in a maintenance window after a backup. To REBUILD after data changes / an MRN cleanup:
--   TRUNCATE patients;  then re-run the INSERT below.
-- ============================================================================

CREATE TABLE IF NOT EXISTS patients (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  mrn             VARCHAR(255) NOT NULL,                 -- trimmed MRN (the patient key)
  pname           MEDIUMTEXT   NULL,                     -- name from the latest admission
  gender          VARCHAR(50)  NULL,
  age             VARCHAR(50)  NULL,                     -- picupatients.age is free-text
  nationality     VARCHAR(255) NULL,
  admission_count INT          NOT NULL DEFAULT 0,
  first_admission DATE         NULL,
  last_admission  DATE         NULL,
  UNIQUE KEY uq_patients_mrn (mrn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Demographics taken from the latest admission (MAX(ID)) of each trimmed, non-blank MRN.
INSERT INTO patients (mrn, pname, gender, age, nationality, admission_count, first_admission, last_admission)
SELECT TRIM(p.MRN), p.PNAME, p.gender, p.age, p.nationality, agg.c, agg.first_adm, agg.last_adm
FROM picupatients p
JOIN (
    SELECT TRIM(MRN) AS tm, COUNT(*) AS c, MIN(ADMDATE) AS first_adm, MAX(ADMDATE) AS last_adm, MAX(ID) AS max_id
    FROM picupatients
    WHERE MRN IS NOT NULL AND TRIM(MRN) <> ''
    GROUP BY tm
) agg ON p.ID = agg.max_id;
