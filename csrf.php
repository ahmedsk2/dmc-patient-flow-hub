<?php
/**
 * CSRF protection (synchronizer token pattern). Self-contained; needs a session.
 *
 *  - csrf_token()  : the per-session token (generated on first use).
 *  - csrf_field()  : a hidden <input> for HTML <form method="post">.
 *  - csrf_verify() : call at the top of every state-changing POST handler; 419s on mismatch.
 *
 * AJAX requests carry the token automatically via the global jQuery ajaxSend hook in
 * footer.php (X-CSRF-Token header). GET-based actions are out of scope here and should
 * be converted to POST before relying on this.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('csrf_token')) {
    function csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field() {
        return '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('csrf_verify')) {
    function csrf_verify() {
        $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (empty($_SESSION['csrf_token']) || !is_string($sent) || $sent === ''
            || !hash_equals($_SESSION['csrf_token'], $sent)) {
            http_response_code(419);
            exit('Invalid or missing CSRF token. Please reload the page and try again.');
        }
    }
}
