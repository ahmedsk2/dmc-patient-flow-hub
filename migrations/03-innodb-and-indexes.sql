-- Migration 03: data integrity & performance (D1 / D3, REL-01)
-- 1) Convert the remaining MyISAM tables to InnoDB so multi-statement clinical writes can be
--    wrapped in transactions (row-level locking + atomic commit/rollback).
-- 2) Add the missing hot-path indexes.
--
-- Run ONCE, in order, AFTER a verified backup. Engine conversion preserves data and per-table
-- charset; on the ~15k-row picupatients table it is fast but briefly locks the table.
-- NOTE: re-running will error on the ADD KEY lines (MySQL has no portable "ADD INDEX IF NOT
-- EXISTS"); that is expected for a once-only migration.

-- 1) Engine: MyISAM -> InnoDB (the lookup tables are converted too, for consistency).
ALTER TABLE `picupatients`       ENGINE=InnoDB;
ALTER TABLE `picupatients_temp`  ENGINE=InnoDB;
ALTER TABLE `icd10`              ENGINE=InnoDB;
ALTER TABLE `speciality`         ENGINE=InnoDB;
ALTER TABLE `other_specialities` ENGINE=InnoDB;
ALTER TABLE `tb_list`            ENGINE=InnoDB;
ALTER TABLE `settings`           ENGINE=InnoDB;
ALTER TABLE `position`           ENGINE=InnoDB;

-- 2) picupatients hot-path indexes (ADMDATE / DISDATE are already indexed).
--    MRN and current_location are TEXT columns, so a prefix index is required.
ALTER TABLE `picupatients`
  ADD KEY `idx_picupatients_mrn`        (`MRN`(20)),
  ADD KEY `idx_picupatients_consultant` (`consultant_id`),
  ADD KEY `idx_picupatients_location`   (`current_location`(10));

-- 3) consultations hot-path indexes (consultation list + 48-hour views).
ALTER TABLE `consultations`
  ADD KEY `idx_consultations_consultant` (`consultant_id`),
  ADD KEY `idx_consultations_mrn`        (`MRN`),
  ADD KEY `idx_consultations_signoff`    (`signoff_date`),
  ADD KEY `idx_consultations_condate`    (`consultation_date`);
