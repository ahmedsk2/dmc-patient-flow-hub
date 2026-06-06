<?php
/**
 * TEMPLATE — copy this file to `config.local.php` (which is git-ignored) and fill in
 * the real values, OR set the equivalent DMC_* environment variables instead.
 *
 * config.local.php must NEVER be committed to version control.
 */

// --- Database ---
define('DB_HOST', 'localhost');
define('DB_USER', 'CHANGE_ME');
define('DB_PASS', 'CHANGE_ME');
define('DB_NAME', 'CHANGE_ME');

// --- SMTP ---
define('SMTP_HOST', 'mail.example.com');
define('SMTP_PORT', '587');
define('SMTP_USER', 'CHANGE_ME');
define('SMTP_PASS', 'CHANGE_ME');
define('SMTP_FROM', 'no-reply@example.com');
define('SMTP_FROM_NAME', 'DMC Help Desk');
define('SMTP_SECURE', 'tls');
