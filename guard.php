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
 * CSRF helpers live in csrf.php (required below) and ARE enforced: csrf_verify() guards
 * state-changing endpoints; the token rides the X-CSRF-Token header (footer ajaxSend) for
 * AJAX and csrf_field() hidden inputs for real forms.
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

if (!function_exists('require_patient_access')) {
    // Object-level authorization for patient-state actions (discharge / transfer / etc.).
    // Mirrors the UI gate exactly: allow Admin (position 0), anyone with the manage_patient
    // capability, or the patient's own primary consultant. Anyone else is forbidden — this
    // makes the previously UI-only ownership check real (closes the IDOR, S1).
    function require_patient_access($patient_id) {
        global $mysqli;
        require_login();
        $u = current_user();
        if ($u && (int) $u['position'] === 0) { return; }                 // Admin
        if ($u && (string) ($u['manage_patient'] ?? '') === '1') { return; } // Can-Manage
        $pid = (int) $patient_id;
        if ($pid > 0 && $u && isset($mysqli) && ($stmt = $mysqli->prepare('SELECT consultant_id FROM picupatients WHERE ID = ?'))) {
            $stmt->bind_param('i', $pid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && (int) $row['consultant_id'] === (int) $u['member_id']) { return; } // primary consultant
        }
        deny(403, 'Forbidden: you are not the primary consultant for this patient.');
    }
}

if (!function_exists('require_consultation_access')) {
    // Object-level authorization for consultation actions (sign-off). Mirrors the UI gate:
    // Admin, anyone with manage_patient, or the consultation's own consultant.
    function require_consultation_access($consult_id) {
        global $mysqli;
        require_login();
        $u = current_user();
        if ($u && (int) $u['position'] === 0) { return; }
        if ($u && (string) ($u['manage_patient'] ?? '') === '1') { return; }
        $cid = (int) $consult_id;
        if ($cid > 0 && $u && isset($mysqli) && ($stmt = $mysqli->prepare('SELECT consultant_id FROM consultations WHERE id = ?'))) {
            $stmt->bind_param('i', $cid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && (int) $row['consultant_id'] === (int) $u['member_id']) { return; }
        }
        deny(403, 'Forbidden: you are not the consultant for this consultation.');
    }
}

// --- CSRF helpers (csrf_token / csrf_field / csrf_verify) ---
require_once __DIR__ . '/csrf.php';

// --- Input validation helpers (v_required / v_int_range / v_date_ymd / v_len / v_in / v_first) ---
require_once __DIR__ . '/validate.php';
