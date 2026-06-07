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
}
