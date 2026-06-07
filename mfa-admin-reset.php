<?php
/**
 * mfa-admin-reset.php — admin lockout recovery.
 *
 * Clears a user's MFA (secret + recovery codes), so a clinician who lost BOTH their
 * authenticator device AND their recovery codes can sign in again with their password and
 * re-enroll. Admin-only, CSRF-protected, audited. The user does NOT get a new secret here —
 * they re-enroll themselves via mfa-setup.php on next login.
 */
require_once __DIR__ . '/guard.php';
require_role([0]); // Admin only
require_once __DIR__ . '/csrf.php';
require __DIR__ . '/dbconnect.php';

csrf_verify();

$id = (int) ($_POST['member_id'] ?? 0);
if ($id > 0) {
    $stmt = $mysqli->prepare('UPDATE members SET mfa_secret = NULL, mfa_recovery_codes = NULL, mfa_enrolled_at = NULL WHERE member_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    if ($stmt->affected_rows > 0) {
        audit_log('mfa.admin_reset', 'members', $id);
    }
}

header('Location: control.php');
exit;
