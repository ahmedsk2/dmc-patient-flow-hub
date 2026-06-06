<?php
/**
 * Central authentication / authorization guard.
 *
 * Include at the TOP of every non-public endpoint, then call one of:
 *   require_login();                 // any authenticated member
 *   require_role([0, 2, 3, 4]);      // members whose `position` is in the list
 *   require_capability('manage_patient');  // per-user capability flag (admins implicit)
 *
 * Authentication state lives in the PHP session ($_SESSION['member_id']), which is
 * established by index.php / sidebar.php at page load. Action endpoints only need to
 * confirm that session — they do not re-run the remember-me cookie bridge.
 *
 * NOTE: CSRF helpers below are defined but not yet enforced app-wide; csrf_verify()
 * will be wired into state-changing endpoints once tokens are present in every form
 * (plan item S6).
 */

require_once __DIR__ . '/dbconnect.php'; // provides $mysqli (idempotent)
require_once __DIR__ . '/audit.php';     // provides audit_log() (fail-safe)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('is_ajax_request')) {
    function is_ajax_request() {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}

if (!function_exists('deny')) {
    function deny($code, $message) {
        http_response_code($code);
        // For a normal browser navigation, send the user to the login page.
        if ($code === 401 && !is_ajax_request()) {
            header('Location: /');
        }
        exit($message);
    }
}

if (!function_exists('require_login')) {
    function require_login() {
        if (empty($_SESSION['member_id'])) {
            deny(401, 'Authentication required.');
        }
    }
}

if (!function_exists('current_user')) {
    function current_user() {
        global $mysqli;
        static $cached = false;
        if ($cached !== false) {
            return $cached;
        }
        $cached = null;
        $id = (int) ($_SESSION['member_id'] ?? 0);
        if ($id > 0 && isset($mysqli) && $mysqli instanceof mysqli) {
            if ($stmt = $mysqli->prepare('SELECT * FROM members WHERE member_id = ?')) {
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $cached = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }
        }
        return $cached;
    }
}

if (!function_exists('require_role')) {
    function require_role(array $allowed) {
        require_login();
        $u = current_user();
        if (!$u || !in_array((int) $u['position'], $allowed, true)) {
            deny(403, 'Forbidden.');
        }
    }
}

if (!function_exists('require_capability')) {
    function require_capability($flag) {
        require_login();
        $u = current_user();
        // Admin (position 0) implicitly holds every capability.
        if ($u && (int) $u['position'] === 0) {
            return;
        }
        if (!$u || empty($u[$flag]) || (string) $u[$flag] !== '1') {
            deny(403, 'Forbidden.');
        }
    }
}

// --- CSRF helpers (csrf_token / csrf_field / csrf_verify) ---
require_once __DIR__ . '/csrf.php';
