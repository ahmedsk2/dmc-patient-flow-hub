<?php
require_once __DIR__ . '/config.php';

// PHP 8.1+ defaults mysqli to THROWING exceptions on error (MYSQLI_REPORT_ERROR|STRICT).
// This legacy codebase is written for the classic "return false / check ->error" style and
// relies on it for graceful error messages AND for the transaction rollback guards added in
// the renovation. Restore that behaviour explicitly so those checks actually run on PHP 8.3.
// (A future modernization can migrate to try/catch + exceptions — tracked for a later phase.)
mysqli_report(MYSQLI_REPORT_OFF);

// Shared mysqli connection. Guarded so repeated includes don't reconnect.
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    $mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($mysqli->connect_errno) {
        // Log the real error server-side; never leak connection details to the client.
        error_log('DMC DB connect error: ' . $mysqli->connect_error);
        http_response_code(500);
        die('Service temporarily unavailable. Please try again later.');
    }
}
