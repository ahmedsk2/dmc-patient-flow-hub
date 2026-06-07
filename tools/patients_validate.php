<?php
/**
 * tools/patients_validate.php — proves the canonical `patients` table (migration 09) is a faithful
 * derivation of picupatients (one row per distinct trimmed, non-blank MRN).
 *
 *   php tools/patients_validate.php                 # validates `dmc`
 *   DMC_PD_DB=dmc_prod php tools/patients_validate.php
 *
 * Checks: (1) row count == distinct trimmed non-blank MRNs; (2) SUM(admission_count) == count of
 * non-blank-MRN admissions; (3) no duplicate mrn. (Dirty/long MRNs intentionally become their own
 * rows — that's the documented pre-cleanup state, see migration 09.)
 */
$dbName = getenv('DMC_PD_DB') ?: 'dmc';
$d = new mysqli('127.0.0.1', 'root', '', $dbName);
if ($d->connect_errno) { fwrite(STDERR, "DB($dbName): {$d->connect_error}\n"); exit(2); }
echo "validating patients in `$dbName`\n";

$fail = 0;
function ok($label, $cond, $extra = '') {
    global $fail;
    echo ($cond ? "  \u{2713} " : "  \u{2717} ") . $label . ($extra ? "  ($extra)" : "") . "\n";
    if (!$cond) { $fail++; }
}

$rows = (int) $d->query("SELECT COUNT(*) c FROM patients")->fetch_assoc()['c'];
$mrns = (int) $d->query("SELECT COUNT(DISTINCT TRIM(MRN)) c FROM picupatients WHERE MRN IS NOT NULL AND TRIM(MRN) <> ''")->fetch_assoc()['c'];
ok('rows == distinct trimmed non-blank MRNs', $rows === $mrns, "patients=$rows mrns=$mrns");

$sumc = (int) $d->query("SELECT COALESCE(SUM(admission_count),0) c FROM patients")->fetch_assoc()['c'];
$adm  = (int) $d->query("SELECT COUNT(*) c FROM picupatients WHERE MRN IS NOT NULL AND TRIM(MRN) <> ''")->fetch_assoc()['c'];
ok('SUM(admission_count) == non-blank-MRN admissions', $sumc === $adm, "sum=$sumc admissions=$adm");

$dups = (int) $d->query("SELECT COUNT(*) c FROM (SELECT mrn FROM patients GROUP BY mrn HAVING COUNT(*) > 1) x")->fetch_assoc()['c'];
ok('no duplicate mrn', $dups === 0, "dups=$dups");

echo "\n" . ($fail === 0 ? "\u{2713} patients is a faithful canonical derivation of picupatients\n" : "\u{2717} $fail check(s) failed\n");
exit($fail === 0 ? 0 : 1);
