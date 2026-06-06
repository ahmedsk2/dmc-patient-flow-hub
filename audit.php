<?php
/**
 * Append-only audit logging for clinical/account changes (REL-02).
 *
 * FAIL-SAFE: never breaks the calling action. If the audit_log table is missing
 * (migration 01 not yet applied) or the insert fails, it logs to the PHP error
 * log and returns. Call AFTER a successful write, with the actor taken implicitly
 * from the server session (never from client input).
 *
 *   audit_log('patient.discharge', 'picupatients', $id, ['type' => 'ward']);
 */

require_once __DIR__ . '/dbconnect.php'; // $mysqli (idempotent)

if (!function_exists('audit_log')) {
    function audit_log($action, $entity_type = null, $entity_id = null, array $details = null) {
        global $mysqli;
        try {
            if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
                return;
            }
            $actor_id   = isset($_SESSION['member_id']) ? (int) $_SESSION['member_id'] : null;
            $actor_name = isset($_SESSION['name']) ? (string) $_SESSION['name'] : null;
            $ip         = $_SERVER['REMOTE_ADDR'] ?? null;
            $json       = ($details !== null) ? json_encode($details) : null;
            $entity_id  = ($entity_id !== null) ? (string) $entity_id : null;

            $stmt = @$mysqli->prepare(
                "INSERT INTO audit_log (actor_id, actor_name, action, entity_type, entity_id, details, ip)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            if ($stmt === false) {
                error_log('DMC audit (table missing?): ' . $action . ' ' . $entity_type . '#' . $entity_id);
                return;
            }
            $stmt->bind_param('issssss', $actor_id, $actor_name, $action, $entity_type, $entity_id, $json, $ip);
            @$stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('DMC audit_log failed: ' . $e->getMessage());
        }
    }
}
