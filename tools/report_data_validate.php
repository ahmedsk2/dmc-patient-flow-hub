<?php
/**
 * tools/report_data_validate.php — proves statistics/report_data.php produces the SAME numbers as
 * the HTML A4 report (a4.php) for the metrics a4.php exposes as JSON. Run after touching either.
 *
 *   php tools/report_data_validate.php          # exit 0 = all match
 *
 * Logs in as the local admin over HTTP (like stats_validate.php), fetches a4.php?y=YEAR, extracts
 * its chart-data arrays, and diffs them against dmc_yearly_report_data($mysqli, YEAR).
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/../statistics/report_data.php';

$BASE = getenv('DMC_BASE') ?: 'http://127.0.0.1:8765';
$USER = getenv('DMC_USER') ?: 'localtest';
$PASS = getenv('DMC_PASS') ?: 'LocalTest123!';
$YEARS = [2023, 2024];
$COOKIE = tempnam(sys_get_temp_dir(), 'rdv');

function curl_get($url, $cookie, $post = null) {
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookie, CURLOPT_COOKIEFILE => $cookie, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 300]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
    $b = curl_exec($ch); curl_close($ch); return (string) $b;
}

// login
$login = curl_get("$BASE/index.php", $COOKIE);
preg_match('/name="csrf_token" value="([^"]+)"/', $login, $m);
curl_get("$BASE/index.php", $COOKIE, http_build_query(['member_name' => $USER, 'member_password' => $PASS, 'login' => 'Login', 'csrf_token' => $m[1] ?? '']));

$mysqli = new mysqli('127.0.0.1', 'root', '', 'dmc');

function grab($pattern, $html) { return preg_match($pattern, $html, $mm) ? $mm[1] : null; }
function grab_all($pattern, $html) { preg_match_all($pattern, $html, $mm); return $mm[1]; }

$fail = 0; $pass = 0;
function cmp($label, $got, $want) {
    global $fail, $pass;
    // a4's grabbed JSON is already canonical (no internal spaces except inside string labels,
    // which json_encode reproduces exactly); compare directly, only trimming the edges.
    $g = json_encode($got); $w = trim((string) $want);
    if ($g === $w) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label\n      data : $g\n      a4   : $w\n"; }
}

foreach ($YEARS as $y) {
    echo "Year $y:\n";
    $html = curl_get("$BASE/statistics/a4.php?y=$y", $COOKIE);
    $d = dmc_yearly_report_data($mysqli, $y);

    cmp('admissions',  $d['admissions'],  grab('/var admissions = (\[[^\]]*\]);/', $html));
    cmp('discharges',  $d['discharges'],  grab('/var discharges = (\[[^\]]*\]);/', $html));
    cmp('newconsults', $d['newconsults'], grab('/var newconsults = (\[[^\]]*\]);/', $html));
    cmp('signedoff',   $d['signedoff'],   grab('/var signedoff = (\[[^\]]*\]);/', $html));

    // the 4 "var LOS = [...]" assignments, in document order: m_LOS, physical LOS, icuLOS, consultantLOS
    $losAll = grab_all('/var LOS = (\[[^\]]*\]);/', $html);
    cmp('m_LOS',         $d['m_LOS'],          $losAll[0] ?? 'MISSING');
    cmp('LOS (physical)', $d['LOS'],           $losAll[1] ?? 'MISSING');
    cmp('icuLOS',        $d['icuLOS'],         $losAll[2] ?? 'MISSING');
    cmp('consultantLOS', $d['consultantLOS'],  $losAll[3] ?? 'MISSING');

    // discharge destinations: a4 echoes array_keys() and array_values() of $dishtransnumbers
    cmp('destination labels', array_keys($d['destinations']),   grab('/var label1 = (\[[^\]]*\]);/', $html));
    cmp('destination values', array_values($d['destinations']), grab('/var all_counts = (\[[^\]]*\]);/', $html));
}

@unlink($COOKIE);
echo "\n" . ($fail === 0 ? "✓ report_data MATCHES a4.php for all metrics ($pass checks)\n" : "✗ $fail mismatch(es), $pass ok\n");
exit($fail === 0 ? 0 : 1);
