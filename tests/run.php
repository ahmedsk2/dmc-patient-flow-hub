<?php
/**
 * tests/run.php — self-contained test runner (no Composer / no PHPUnit).
 *
 *   php tests/run.php            # run the whole suite; exit 0 = all green
 *   php tests/run.php --unit     # only the offline unit tests (no dev server needed)
 *
 * It runs each registered check as its own PHP process (via PHP_BINARY) and aggregates the
 * exit codes — so the existing, already-validated check scripts ARE the test suite, with no
 * duplication. Integration checks that need the local dev server are auto-SKIPPED (not failed)
 * when it isn't reachable, so the unit suite is always CI-runnable.
 *
 * To add a test: drop a script under tests/ (or tools/) that exits 0 on pass / non-0 on fail,
 * and register it below.
 */

$root = dirname(__DIR__);
$unitOnly = in_array('--unit', $argv, true);

$base = getenv('DMC_BASE') ?: 'http://127.0.0.1:8765';
$serverUp = false;
if (preg_match('#://([^:/]+):(\d+)#', $base, $m)) {
    $fp = @fsockopen($m[1], (int) $m[2], $e, $s, 1);
    if ($fp) { $serverUp = true; fclose($fp); }
}

// Registry: [label, script (relative to repo root), 'unit'|'integration']
$suites = [
    ['TOTP / MFA crypto (RFC-6238 vectors)', 'tools/mfa_test.php',                  'unit'],
    ['src/ YearlyReport class structure',    'tests/yearly_report_test.php',         'integration'],
    ['Stats: report_data == a4.php',         'tools/report_data_validate.php',       'integration'],
    ['Schema: patient_diagnosis lossless',   'tools/patient_diagnosis_validate.php', 'integration'],
    ['Schema: patients entity faithful',     'tools/patients_validate.php',          'integration'],
];

echo "DMC test runner  (server " . ($serverUp ? "UP @ $base" : "down") . ($unitOnly ? ", --unit" : "") . ")\n";
echo str_repeat('-', 64) . "\n";

$pass = $fail = $skip = 0;
$failed = [];
foreach ($suites as [$label, $script, $type]) {
    if ($type === 'integration' && ($unitOnly || !$serverUp)) {
        printf("  SKIP  %s  (%s)\n", $label, $unitOnly ? 'unit-only' : 'dev server not reachable');
        $skip++;
        continue;
    }
    $path = $root . '/' . $script;
    if (!is_file($path)) {
        printf("  FAIL  %s  (missing: %s)\n", $label, $script);
        $fail++; $failed[] = $label;
        continue;
    }
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path) . ' 2>&1';
    exec($cmd, $out, $code);
    if ($code === 0) {
        printf("  PASS  %s\n", $label);
        $pass++;
    } else {
        printf("  FAIL  %s  (exit %d)\n", $label, $code);
        foreach (array_slice($out, -4) as $line) { echo "          | $line\n"; }
        $fail++; $failed[] = $label;
    }
    $out = [];
}

echo str_repeat('-', 64) . "\n";
printf("%d passed, %d failed, %d skipped\n", $pass, $fail, $skip);
if ($failed) { echo "FAILED: " . implode('; ', $failed) . "\n"; }
exit($fail === 0 ? 0 : 1);
