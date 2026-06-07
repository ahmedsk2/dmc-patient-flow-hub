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

// 0) Global uncaught-exception / fatal safety net (registered as early as possible).
require_once __DIR__ . '/error-handler.php';

// 0b) Error-reporting policy — MIRRORS php.ini exactly (defense-in-depth, so the policy
// also holds on a SAPI/host that doesn't load the repo php.ini, e.g. CLI / `php -S` / a
// mis-set PHPRC). Report everything EXCEPT E_DEPRECATED + E_NOTICE: PHP 8.1+ raises
// "Passing null to htmlspecialchars()" deprecations across ~540 legacy output sinks; the
// calls are benign (null coerces to '') but would flood the error log (log_errors=On).
// Real warnings/errors and the uncaught/fatal net above are unaffected; display_errors
// stays Off in prod so end users never saw these. Keep this in sync with php.ini line 16.
// Prefer the null-safe h() helper (view-helpers.php) in new code; revisit at re-platform.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

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
