<?php
/**
 * Lightweight, dependency-free server-side input validation for clinical write endpoints
 * (W3 / UX-03). Client-side JS validation is bypassable, so the server re-checks types and
 * ranges before persisting. Each helper returns '' when the value is acceptable, or a short
 * human-readable error string when it is not.
 *
 * Design intent: reject only *clearly* invalid input (non-numeric age, malformed date, empty
 * required field, absurd length). It deliberately does NOT enforce clinical business rules
 * (length-of-stay, readmission windows, discharge >= admission) — those are open clinical
 * questions (CLIN-01..09) — and it is permissive about MRN/name characters so it never blocks
 * a legitimate save of already-stored data.
 */

if (!function_exists('v_required')) {

    // Non-empty after trimming.
    function v_required($value, $label) {
        if ($value === null || trim((string) $value) === '') {
            return "$label is required.";
        }
        return '';
    }

    // Whole number within [$min, $max] inclusive (e.g. age 0–150).
    function v_int_range($value, $label, $min, $max) {
        $v = trim((string) $value);
        if ($v === '') {
            return "$label is required.";
        }
        if (!preg_match('/^-?\d+$/', $v)) {
            return "$label must be a whole number.";
        }
        $n = (int) $v;
        if ($n < $min || $n > $max) {
            return "$label must be between $min and $max.";
        }
        return '';
    }

    // Calendar date in Y-m-d (the format the UI submits). Rejects impossible dates
    // (e.g. 2024-02-31) and years outside a sane clinical range.
    function v_date_ymd($value, $label) {
        $v = trim((string) $value);
        if ($v === '') {
            return "$label is required.";
        }
        $d = DateTime::createFromFormat('Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) {
            return "$label must be a valid date (YYYY-MM-DD).";
        }
        $y = (int) $d->format('Y');
        if ($y < 2000 || $y > 2100) {
            return "$label year is out of range.";
        }
        return '';
    }

    // Length bound + reject HTML angle brackets / control characters. Intentionally permissive
    // about the rest, because the canonical MRN/name format is not yet confirmed (CLIN-09).
    function v_len($value, $label, $max) {
        $v = trim((string) $value);
        if ($v === '') {
            return "$label is required.";
        }
        if (mb_strlen($v) > $max) {
            return "$label is too long (max $max characters).";
        }
        if (preg_match('/[<>]/', $v) || preg_match('/[\x00-\x1F\x7F]/', $v)) {
            return "$label contains invalid characters.";
        }
        return '';
    }

    // MRN (picupatients): digits only, max 11 (confirmed canonical format). NOTE: consultations.MRN
    // is a different identifier and is NOT validated with this.
    function v_mrn($value, $label = 'MRN') {
        $v = trim((string) $value);
        if ($v === '') {
            return "$label is required.";
        }
        if (!preg_match('/^\d{1,11}$/', $v)) {
            return "$label must be digits only (max 11).";
        }
        return '';
    }

    // Membership in a fixed allow-list (e.g. gender, discharge status).
    function v_in($value, $label, array $allowed) {
        $v = trim((string) $value);
        if ($v === '') {
            return "$label is required.";
        }
        if (!in_array($v, $allowed, true)) {
            return "$label is not a valid option.";
        }
        return '';
    }

    // Reduce a list of per-field results to the first non-empty message ('' = all valid).
    function v_first(array $checks) {
        foreach ($checks as $c) {
            if ($c !== '') {
                return $c;
            }
        }
        return '';
    }

    // Consultant/member reference must be a positive integer that EXISTS in `members`.
    // Closes the legacy data-quality hole where consultant_id=0 (or any non-existent id)
    // could be written and the patient then vanished under a ghost consultant.
    function v_consultant_exists(mysqli $mysqli, $value, $label = 'Consultant') {
        $v = trim((string) $value);
        if ($v === '' || !preg_match('/^\d+$/', $v) || (int) $v <= 0) {
            return "$label is required.";
        }
        $stmt = $mysqli->prepare('SELECT 1 FROM members WHERE member_id = ? LIMIT 1');
        if (!$stmt) {
            return "$label could not be verified.";
        }
        $id = (int) $v;
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists ? '' : "$label does not exist.";
    }

    // Normalize a submitted diagnosis list into a clean JSON array string:
    // trims every code, drops blanks, removes duplicates (keeps first occurrence, preserves
    // order). Prevents duplicate/blank ICD codes from ever being stored in admissiondiagnosis.
    function dx_normalize($raw) {
        $list = is_array($raw) ? $raw : ($raw === null || $raw === '' ? [] : [$raw]);
        $out = [];
        foreach ($list as $code) {
            $code = trim((string) $code);
            if ($code === '' || in_array($code, $out, true)) {
                continue;
            }
            $out[] = $code;
        }
        return json_encode(array_values($out));
    }
}
