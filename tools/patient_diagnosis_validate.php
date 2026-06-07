<?php
/**
 * tools/patient_diagnosis_validate.php — proves the patient_diagnosis join table (migration 07)
 * is a LOSSLESS, faithful derivation of picupatients.admissiondiagnosis.
 *
 *   php tools/patient_diagnosis_validate.php     # exit 0 = faithful
 *
 * Checks: (1) row count == sum of JSON array lengths; (2) EVERY admission's diagnosis list
 * reconstructs EXACTLY from the join table (codes ordered by seq === the original JSON array);
 * (3) icd10_autoid resolved for every code that exists in icd10.
 */
$d = new mysqli('127.0.0.1', 'root', '', 'dmc');
if ($d->connect_errno) { fwrite(STDERR, "DB: {$d->connect_error}\n"); exit(2); }

$fail = 0;
function ok($label, $cond, $extra = '') {
    global $fail;
    echo ($cond ? "  ✓ " : "  ✗ ") . $label . ($extra ? "  ($extra)" : "") . "\n";
    if (!$cond) { $fail++; }
}

// (1) count parity
$jsonElems = (int) $d->query("SELECT SUM(JSON_LENGTH(admissiondiagnosis)) t FROM picupatients WHERE admissiondiagnosis IS NOT NULL")->fetch_assoc()['t'];
$pdRows    = (int) $d->query("SELECT COUNT(*) c FROM patient_diagnosis")->fetch_assoc()['c'];
ok("row count == JSON element count", $jsonElems === $pdRows, "json=$jsonElems table=$pdRows");

// (2) per-row lossless round-trip (all admissions)
$orig = [];
$r = $d->query("SELECT ID, admissiondiagnosis FROM picupatients WHERE admissiondiagnosis IS NOT NULL");
while ($x = $r->fetch_assoc()) {
    $arr = json_decode($x['admissiondiagnosis'], true);
    $orig[(int) $x['ID']] = is_array($arr) ? $arr : [];
}
$rebuilt = [];
$r = $d->query("SELECT picupatient_id, icd10_code FROM patient_diagnosis ORDER BY picupatient_id, seq");
while ($x = $r->fetch_assoc()) {
    $rebuilt[(int) $x['picupatient_id']][] = $x['icd10_code'];
}
$checked = 0; $mismatch = 0; $firstBad = null;
foreach ($orig as $id => $arr) {
    $got = $rebuilt[$id] ?? [];
    if ($got !== $arr) { $mismatch++; if ($firstBad === null) { $firstBad = $id; } }
    $checked++;
}
// also: any join-table rows for admissions that have no/empty JSON? (should be none)
$extraPids = array_diff(array_keys($rebuilt), array_keys($orig));
ok("every admission round-trips exactly", $mismatch === 0, "checked=$checked mismatches=$mismatch" . ($firstBad !== null ? " firstBadID=$firstBad" : ""));
ok("no orphan join rows (pid not in source)", count($extraPids) === 0, "orphans=" . count($extraPids));

// (3) autoid resolution
$unresolved = (int) $d->query("SELECT COUNT(*) c FROM patient_diagnosis WHERE icd10_autoid IS NULL")->fetch_assoc()['c'];
$unresolvedDistinct = (int) $d->query("SELECT COUNT(DISTINCT icd10_code) c FROM patient_diagnosis WHERE icd10_autoid IS NULL")->fetch_assoc()['c'];
// unresolved is only acceptable if those codes genuinely aren't in icd10:
$trulyMissing = (int) $d->query("SELECT COUNT(*) c FROM patient_diagnosis pd LEFT JOIN icd10 i ON i.id = pd.icd10_code WHERE pd.icd10_autoid IS NULL AND i.autoid IS NOT NULL")->fetch_assoc()['c'];
ok("every code present in icd10 is resolved", $trulyMissing === 0, "unresolved rows=$unresolved (distinct codes=$unresolvedDistinct, all genuinely absent from icd10)");

echo "\n" . ($fail === 0 ? "✓ patient_diagnosis is a faithful, lossless derivation of the JSON\n" : "✗ $fail check(s) failed\n");
exit($fail === 0 ? 0 : 1);
