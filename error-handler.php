<?php
/**
 * Global safety net for UNCAUGHT exceptions and FATAL errors (C4 / ARCH-05, defense-in-depth).
 *
 * Goal: never blank-screen or half-render a page on an unexpected failure, and always leave a
 * server-side log line. This is additive and behaviour-preserving:
 *   - It does NOT register a set_error_handler, so ordinary warnings/notices keep flowing through
 *     PHP's normal path (already logged via log_errors=On and hidden via display_errors=Off).
 *     This avoids both log-flooding (e.g. htmlspecialchars(null) deprecations) and any change to
 *     @-suppressed operations.
 *   - It only steps in for truly uncaught exceptions and fatal errors, which would otherwise be
 *     a blank 500 under display_errors=Off.
 *
 * Included as early as possible (from config.php), so it covers the whole request.
 */

if (!defined('DMC_ERROR_HANDLER')) {
    define('DMC_ERROR_HANDLER', 1);

    // Uncaught exceptions: log the detail server-side; show the client a generic message only.
    set_exception_handler(function ($e) {
        error_log('DMC uncaught ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine());
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo 'A server error occurred. Please try again later.';
    });

    // Fatal errors (E_ERROR / parse / out-of-memory, etc.) bypass the exception handler — catch
    // them at shutdown so the response is logged and ends with a generic message, not a blank page.
    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            error_log("DMC fatal [{$err['type']}] {$err['message']} in {$err['file']}:{$err['line']}");
            if (!headers_sent()) {
                http_response_code(500);
            }
            echo 'A server error occurred. Please try again later.';
        }
    });
}
