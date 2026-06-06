<?php
/**
 * Centralized configuration for the DMC application.
 *
 * SECRETS DO NOT LIVE IN THIS (tracked) FILE. They are provided by, in order of
 * precedence:
 *   1. config.local.php  — a git-ignored file (copy config.local.sample.php), or
 *   2. environment variables (DMC_DB_*, DMC_SMTP_*), or
 *   3. a safe default.
 *
 * After any credential exposure, ROTATE the real values on the DB / mail server
 * and update config.local.php (or the environment) — never hard-code them here.
 */

// 1) Local overrides (git-ignored). May define the DB_*/SMTP_* constants directly.
$__local = __DIR__ . '/config.local.php';
if (is_file($__local)) {
    require $__local;
}

// 2) Fall back to environment variables, then a default.
if (!function_exists('dmc_env_define')) {
    function dmc_env_define($const, $envVar, $default = '') {
        if (defined($const)) {
            return;
        }
        $v = getenv($envVar);
        define($const, ($v !== false && $v !== '') ? $v : $default);
    }
}

// --- Database ---
dmc_env_define('DB_HOST', 'DMC_DB_HOST', 'localhost');
dmc_env_define('DB_USER', 'DMC_DB_USER', '');
dmc_env_define('DB_PASS', 'DMC_DB_PASS', '');
dmc_env_define('DB_NAME', 'DMC_DB_NAME', '');

// --- SMTP (password reset / notifications) ---
dmc_env_define('SMTP_HOST', 'DMC_SMTP_HOST', 'mail.dmc-im.com');
dmc_env_define('SMTP_PORT', 'DMC_SMTP_PORT', '587');
dmc_env_define('SMTP_USER', 'DMC_SMTP_USER', '');
dmc_env_define('SMTP_PASS', 'DMC_SMTP_PASS', '');
dmc_env_define('SMTP_FROM', 'DMC_SMTP_FROM', 'info@dmc-im.com');
dmc_env_define('SMTP_FROM_NAME', 'DMC_SMTP_FROM_NAME', 'DMC Help Desk');
dmc_env_define('SMTP_SECURE', 'DMC_SMTP_SECURE', 'tls');
