-- Migration 10: index the ICD-10 lookup key (PERF).
--
-- icd10 has 72,750 rows and its only key is PRIMARY(autoid). But every diagnosis lookup in the
-- app joins/filters on `id` (the ICD-10 CODE), not autoid — e.g. longterm.php, dmc-old-patients.php,
-- the discharge/ICU-discharge modals, patient-details, the registry Excel export, dashboard/3.php
-- and statistics/charts.php. Without an index on `id` each lookup is a full scan (~1.3 s on this
-- table); pages that resolve diagnoses in a loop (longterm.php nests 41 consultants x ~175 patients
-- x per-dx) degrade to minutes and time out.
--
-- A COVERING index on (id, name) turns both the per-code lookup (`WHERE id=?` / `WHERE id IN (...)`)
-- and the whole-table preload (`SELECT id,name FROM icd10`) into index-only operations.
--
-- Additive and safe: indexes never change query RESULTS, only speed. id/name are varchar(100)
-- utf8mb4 => key length 400+400 = 800 bytes, within InnoDB/MyISAM limits. Run ONCE; re-running
-- errors on the duplicate key name (no portable ADD INDEX IF NOT EXISTS), which is expected.

ALTER TABLE `icd10`
  ADD KEY `idx_icd10_id` (`id`, `name`);
