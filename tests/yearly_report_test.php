<?php
/**
 * tests/yearly_report_test.php — structural unit test for the DMC\Reports\YearlyReport src/ class
 * (the Phase-4 layer slice). Exercises the autoloader and the class shape directly (no HTTP).
 * Numeric equivalence to a4.php is covered separately by tools/report_data_validate.php.
 */
require_once __DIR__ . '/../autoload.php';

$db = new mysqli('127.0.0.1', 'root', '', 'dmc');
if ($db->connect_errno) { fwrite(STDERR, "DB: {$db->connect_error}\n"); exit(2); }

$fail = 0;
function ck($label, $cond) { global $fail; echo ($cond ? "  ✓ " : "  ✗ ") . $label . "\n"; if (!$cond) { $fail++; } }

$r = (new \DMC\Reports\YearlyReport($db))->data(2024);

$expectedKeys = ['year', 'months', 'admissions', 'discharges', 'toicu', 'newconsults', 'signedoff',
                 'readmission', 'LOS', 'm_LOS', 'icuLOS', 'consultantName', 'consultantLOS', 'destinations'];
ck('autoloader resolves DMC\\Reports\\YearlyReport', class_exists('\\DMC\\Reports\\YearlyReport'));
ck('data() returns an array', is_array($r));
ck('all expected keys present', count(array_diff($expectedKeys, array_keys($r))) === 0);
ck('months has 12 entries', is_array($r['months']) && count($r['months']) === 12);
foreach (['admissions', 'discharges', 'toicu', 'newconsults', 'signedoff', 'readmission', 'LOS', 'm_LOS', 'icuLOS'] as $k) {
    ck("$k has 12 monthly values", is_array($r[$k]) && count($r[$k]) === 12);
}
ck('consultantName / consultantLOS aligned', count($r['consultantName']) === count($r['consultantLOS']));
ck('destinations non-empty', is_array($r['destinations']) && count($r['destinations']) >= 1);

echo "\n" . ($fail === 0 ? "✓ YearlyReport class structure OK\n" : "✗ $fail failed\n");
exit($fail === 0 ? 0 : 1);
