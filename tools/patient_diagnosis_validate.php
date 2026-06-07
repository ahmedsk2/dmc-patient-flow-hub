<?php
/**
 * tools/patient_diagnosis_validate.php — proves the patient_diagnosis join table (migration 07)
 * is a LOSSLESS, faithful derivation of picupatients.admissiondiagnosis.
 *
 *   php tools/patient_diagnosis_validate.php              # validates the local `dmc` DB
 *   DMC_PD_DB=dmc_prod php tools/patient_diagnosis_validate.php   # validate any DB
 *
 * Checks: (1) table rows == count of real JSON array elements; (1b) any non-array value is a
 * harmless JSON `null` (no diagnosis), NOT a scalar/object that JSON_TABLE would silently drop;
 * (2) EVERY admission's diagnosis list reconstructs EXACTLY (codes ordered by seq === the original
 * array); (3) icd10_autoid resolved for every code that exists in icd10.
 *
 * Note: a JSON `null` admissiondiagnosis means "no diagnosis recorded" — JSON_LENGTH(null) is 1
 * (a MySQL quirk) but there are genuinely 0 diagnoses, so the join table correctly holds 0 rows for
 * it. The count check therefore measures real array elements, not raw JSON_LENGTH.
 */
$dbName = getenv('DMC_PD_DB') ?: 'dmc';
$d = new mysqli('127.0.0.1', 'root', '', $dbName);
if ($d->connect_errno) { fwrite(STDERR, "DB($dbName): {$d->connect_error}\n"); exit(2); }
echo "validating patient_diagnosis in `$dbName`\n";

$fail = 0;
function ok($label, $cond, $extra = '') {
    global $fail;
    echo ($cond ? "  ✓ " : "  ✗ ") . $label . ($extra ? "  ($extra)" : "") . "\n";
    if (!$cond) { $fail++; }
}

// (1) count parity — real array elements only (JSON_TABLE '$[*]' expands ARRAY values; a JSON
//     null/scalar contributes 0 rows, so don't count it via raw JSON_LENGTH).
$arrayElems = (int) $d->query("SELECT COALESCE(SUM(JSON_LENGTH(admissiondiagnosis)),0) t FROM picupatients WHERE JSON_TYPE(admissiondiagnosis) = 'ARRAY'")->fetch_assoc()['t'];
$pdRows     = (int) $d->query("SELECT COUNT(*) c FROM patient_diagnosis")->fetch_assoc()['c'];
ok("table rows == real JSON array elements", $arrayElems === $pdRows, "elements=$arrayElems table=$pdRows");

// (1b) non-array values: JSON null is fine (no diagnosis); a scalar/object would be a diagnosis
//      that JSON_TABLE '$[*]' silently drops -> that WOULD be data loss, so fail on any.
$nullCnt   = (int) $d->query("SELECT COUNT(*) c FROM picupatients WHERE JSON_TYPE(admissiondiagnosis) = 'NULL'")->fetch_assoc()['c'];
$droppable = (int) $d->query("SELECT COUNT(*) c FROM picupatients WHERE admissiondiagnosis IS NOT NULL AND JSON_TYPE(admissiondiagnosis) NOT IN ('ARRAY','NULL')")->fetch_assoc()['c'];
ok("no scalar/object diagnosis would be dropped", $droppable === 0, "json-null(no-dx)=$nullCnt  scalar/object=$droppable");

// (2) per-row lossless round-trip (all admissions). Non-array originals normalize to [] — which the
//     join table also yields (0 rows) — so this is exact iff the table faithfully represents each row.
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
    if (($rebuilt[$id] ?? []) !== $arr) { $mismatch++; if ($firstBad === null) { $firstBad = $id; } }
    $checked++;
}
$extraPids = array_diff(array_keys($rebuilt), array_keys($orig));
ok("every admission round-trips exactly", $mismatch === 0, "checked=$checked mismatches=$mismatch" . ($firstBad !== null ? " firstBadID=$firstBad" : ""));
ok("no orphan join rows (pid not in source)", count($extraPids) === 0, "orphans=" . count($extraPids));

// (3) autoid resolution — unresolved is only acceptable if the code genuinely isn't in icd10.
$unresolved = (int) $d->query("SELECT COUNT(*) c FROM patient_diagnosis WHERE icd10_autoid IS NULL")->fetch_assoc()['c'];
$trulyMissing = (int) $d->query("SELECT COUNT(*) c FROM patient_diagnosis pd JOIN icd10 i ON i.id = pd.icd10_code WHERE pd.icd10_autoid IS NULL")->fetch_assoc()['c'];
ok("every code present in icd10 is resolved", $trulyMissing === 0, "unresolved rows=$unresolved (all genuinely absent from icd10)");

echo "\n" . ($fail === 0 ? "✓ patient_diagnosis is a faithful, lossless derivation of the JSON\n" : "✗ $fail check(s) failed\n");
exit($fail === 0 ? 0 : 1);
