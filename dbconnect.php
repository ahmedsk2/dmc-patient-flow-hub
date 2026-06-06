<?php
require_once __DIR__ . '/config.php';

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
