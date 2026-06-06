-- Migration 01: audit_log (REL-02 / clinical audit trail)
-- Run once. Safe to re-run (IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`          BIGINT       NOT NULL AUTO_INCREMENT,
  `actor_id`    INT          DEFAULT NULL,           -- members.member_id (from server session)
  `actor_name`  VARCHAR(255) DEFAULT NULL,
  `action`      VARCHAR(64)  NOT NULL,               -- e.g. patient.delete, patient.discharge, member.update
  `entity_type` VARCHAR(64)  DEFAULT NULL,           -- e.g. picupatients, members, consultations
  `entity_id`   VARCHAR(64)  DEFAULT NULL,
  `details`     JSON         DEFAULT NULL,           -- small context only (no full PHI dumps)
  `ip`          VARCHAR(45)  DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_entity` (`entity_type`, `entity_id`),
  KEY `idx_audit_actor`  (`actor_id`),
  KEY `idx_audit_time`   (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
