<?php
/**
 * Secure password-reset tokens (replaces the old deterministic md5(hash) scheme, SEC-14).
 *
 * A random 256-bit token is emailed to the user; only its SHA-256 hash is stored, with a
 * 1-hour expiry and single-use semantics. Requires migration 02 (password_resets table).
 */
require_once __DIR__ . '/dbconnect.php'; // $mysqli

if (!function_exists('password_reset_create')) {
    // Create a token for $member_id; returns the RAW token to embed in the emailed link.
    function password_reset_create($member_id) {
        global $mysqli;
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour
        if ($stmt = $mysqli->prepare(
            "INSERT INTO password_resets (member_id, token_hash, expires_at) VALUES (?, ?, ?)")) {
            $mid = (int) $member_id;
            $stmt->bind_param('iss', $mid, $hash, $expires);
            $stmt->execute();
            $stmt->close();
        }
        return $token;
    }

    // Look up a valid (unused, unexpired) token; returns ['id'=>, 'member_id'=>] or null.
    function password_reset_lookup($token) {
        global $mysqli;
        if (!is_string($token) || $token === '') {
            return null;
        }
        $hash = hash('sha256', $token);
        if (!($stmt = $mysqli->prepare(
            "SELECT id, member_id FROM password_resets
             WHERE token_hash = ? AND used_at IS NULL AND expires_at >= NOW() LIMIT 1"))) {
            return null;
        }
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    // Mark a token consumed (single use).
    function password_reset_consume($id) {
        global $mysqli;
        if ($stmt = $mysqli->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?")) {
            $i = (int) $id;
            $stmt->bind_param('i', $i);
            $stmt->execute();
            $stmt->close();
        }
    }
}
