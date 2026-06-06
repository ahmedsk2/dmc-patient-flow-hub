-- Migration 02: secure password-reset tokens (SEC-14) + widen password column (SEC-30)
-- Run once. Safe to re-run (IF NOT EXISTS; the ALTER is idempotent in effect).

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`         INT          NOT NULL AUTO_INCREMENT,
  `member_id`  INT          NOT NULL,
  `token_hash` CHAR(64)     NOT NULL,            -- sha256 hex of the random token (never store the raw token)
  `expires_at` DATETIME     NOT NULL,
  `used_at`    DATETIME     DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pr_token`  (`token_hash`),
  KEY `idx_pr_member` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- bcrypt hashes are 60 chars; widen so a future algorithm (e.g. Argon2id) is not truncated.
ALTER TABLE `members` MODIFY `member_password` VARCHAR(255) NOT NULL;
