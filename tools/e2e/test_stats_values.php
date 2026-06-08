<?php
/**
 * tools/e2e/test_stats_values.php — VALUE validation for every statistic/chart.
 *
 * For each stats endpoint we fetch the rendered output, extract the numbers it actually displays
 * (chart-data JS arrays / KPI table cells), and compare them to an INDEPENDENT ground-truth query
 * written from the metric's definition (not copied from the endpoint). Focus: consultations.
 *
 * A mismatch means the displayed statistic is WRONG. Run as admin against the configured DB.
 */
require __DIR__ . '/lib.php';
$db = e2e_db();
e2e_login('admin');

/* ---------- helpers ---------- */
// extract a flat JS number array:  let NAME = [1,2,3];  /  var NAME = [...]  /  const NAME = [...]
function js_arr($body, $name) {
    if (preg_match('/(?:let|var|const)\s+' . preg_quote($name, '/') . '\s*=\s*(\[[^\]]*\])\s*;/', $body, $m)) {
        $a = json_decode($m[1], true);
        return is_array($a) ? $a : null;
    }
    return null;
}
// last matching array (charts.php emits empty admissions=[] for its doughnut/discharge charts
// BEFORE the real series chart, so the series we want is the LAST occurrence of the name).
function js_arr_last($body, $name) {
    if (preg_match_all('/(?:let|var|const)\s+' . preg_quote($name, '/') . '\s*=\s*(\[[^\]]*\])\s*;/', $body, $m)) {
        $a = json_decode(end($m[1]), true);
        return is_array($a) ? $a : null;
    }
    return null;
}
// longest matching array — charts.php emits empty NAME=[] arrays for its doughnut/discharge charts
// both BEFORE and AFTER the real per-period series chart, so neither "first" nor "last" is reliable;
// the actual data series is the longest (n = days/months/quarters), empties are n=0.
function js_arr_longest($body, $name) {
    if (preg_match_all('/(?:let|var|const)\s+' . preg_quote($name, '/') . '\s*=\s*(\[[^\]]*\])\s*;/', $body, $m)) {
        $best = null;
        foreach ($m[1] as $lit) { $a = json_decode($lit, true); if (is_array($a) && ($best === null || count($a) > count($best))) { $best = $a; } }
        return $best;
    }
    return null;
}
function arr_sum($a) { return $a === null ? null : array_sum(array_map('intval', $a)); }
function gtv($sql, $types = '', $params = []) { // ground-truth scalar
    $db = e2e_db();
    $st = $db->prepare($sql);
    if ($types !== '') { $st->bind_param($types, ...$params); }
    $st->execute();
    $row = $st->get_result()->fetch_row();
    return (int) ($row[0] ?? 0);
}

/* ---------- pick a test window with real consultation data ---------- */
$yr = $db->query("SELECT YEAR(consultation_date) y, COUNT(*) c FROM consultations
                  WHERE consultation_date IS NOT NULL GROUP BY y ORDER BY c DESC LIMIT 1")->fetch_assoc();
$Y = (int) $yr['y'];
$mo = $db->query("SELECT MONTH(consultation_date) m, COUNT(*) c FROM consultations
                  WHERE YEAR(consultation_date)=$Y GROUP BY m ORDER BY c DESC LIMIT 1")->fetch_assoc();
$M = (int) $mo['m'];
$DATE = sprintf('%04d-%02d-15', $Y, $M);
$con = $db->query("SELECT consultant_id, COUNT(*) c FROM consultations
                   WHERE YEAR(consultation_date)=$Y AND consultant_id IS NOT NULL
                   GROUP BY consultant_id ORDER BY c DESC LIMIT 1")->fetch_assoc();
$CID = (int) $con['consultant_id'];
$cpat = gtv("SELECT COUNT(*) FROM picupatients WHERE consultant_id=? AND (current_location!='ICU' OR current_location IS NULL)", 'i', [$CID]);
echo "Test window: year=$Y month=$M date=$DATE | consultant=$CID (has {$con['c']} consultations in $Y, $cpat non-ICU patients)\n";
$mStart = sprintf('%04d-%02d-01', $Y, $M);
$mEnd   = date('Y-m-d', strtotime($mStart . ' +1 month'));
$yStart = "$Y-01-01"; $yEnd = ($Y + 1) . "-01-01";

/* ================================================================= kpis.php (table) ===== */
e2e_section('kpis.php — Consultations & Sign-Offs (monthly table totals)');
$body = e2e_get('/statistics/kpis.php?' . http_build_query(['date2'=>$DATE,'timing2'=>'monthly']), 'admin')['body'];
// parse the Consultations / Sign Offs rows: <th>Consultations</th><td>..</td>... -> sum the cells
function kpi_row_sum($body, $label) {
    if (!preg_match('#<th>' . preg_quote($label,'#') . '</th>(.*?)</tr>#s', $body, $m)) return null;
    preg_match_all('#<td>(\d+)</td>#', $m[1], $cells);
    return array_sum(array_map('intval', $cells[1]));
}
$kCons = kpi_row_sum($body, 'Consultations');
$kSign = kpi_row_sum($body, 'Sign Offs');
$gtCons = gtv("SELECT COUNT(*) FROM consultations WHERE consultation_date>=? AND consultation_date<?", 'ss', [$yStart,$yEnd]);
$gtSign = gtv("SELECT COUNT(*) FROM consultations WHERE signoff_date>=? AND signoff_date<?", 'ss', [$yStart,$yEnd]);
t_ok("kpis Consultations total ($Y)", $kCons === $gtCons, "displayed=$kCons truth=$gtCons");
t_ok("kpis Sign-Offs total ($Y)",    $kSign === $gtSign, "displayed=$kSign truth=$gtSign");

// ---- the rest of the KPI table (all metrics), summed over the year vs independent ground truth ----
$P = "(current_location != 'ICU' OR current_location IS NULL)"; // non-ICU
$kpiChecks = [
    ['Admissions',        kpi_row_sum($body,'Admissions'),        gtv("SELECT COUNT(*) FROM picupatients WHERE ADMDATE>=? AND ADMDATE<? AND $P",'ss',[$yStart,$yEnd])],
    ['Discharges',        kpi_row_sum($body,'Discharges'),        gtv("SELECT COUNT(*) FROM picupatients WHERE DISDATE>=? AND DISDATE<? AND $P",'ss',[$yStart,$yEnd])],
    ['Trans To ICU',      kpi_row_sum($body,'Trans To ICU'),      gtv("SELECT COUNT(*) FROM picupatients WHERE DISDATE>=? AND DISDATE<? AND DISTO='Intensive Care (ICU)'",'ss',[$yStart,$yEnd])],
    ['ICU Mortality',     kpi_row_sum($body,'ICU Mortality'),     gtv("SELECT COUNT(*) FROM picupatients WHERE DISDATE>=? AND DISDATE<? AND current_location='ICU' AND MORTALITY='Dead'",'ss',[$yStart,$yEnd])],
    ['Out ICU Mortality', kpi_row_sum($body,'Out ICU Mortality'), gtv("SELECT COUNT(*) FROM picupatients WHERE DISDATE>=? AND DISDATE<? AND $P AND MORTALITY='Dead'",'ss',[$yStart,$yEnd])],
    // 72h readmissions — independent EXISTS formulation (kpis.php uses a JOIN+DISTINCT CTE)
    ['72hr Readmissions', kpi_row_sum($body,'72hr Readmissions'), gtv(
        "SELECT COUNT(*) FROM picupatients p WHERE p.ADMDATE>=? AND p.ADMDATE<? AND EXISTS (
            SELECT 1 FROM picupatients q WHERE q.MRN=p.MRN AND q.ID<p.ID
              AND q.trans_discharge IN ('discharge from ICU','discharge from ward') AND q.DISDATE IS NOT NULL
              AND p.ADMDATE <= DATE_ADD(q.DISDATE, INTERVAL 3 DAY))", 'ss', [$yStart,$yEnd])],
];
foreach ($kpiChecks as [$lbl,$disp,$truth]) {
    t_ok("kpis $lbl total ($Y)", $disp === $truth, "displayed=".var_export($disp,true)." truth=$truth");
}

/* ================================================================= time1.php (global) === */
e2e_section('time1.php — New Consultations & Sign-Offs (monthly, last 12 months)');
$body = e2e_get('/statistics/time1.php?' . http_build_query(['timing1'=>'monthly']), 'admin')['body'];
$tCons = arr_sum(js_arr($body, 'newconsults'));
$tSign = arr_sum(js_arr($body, 'signedoff'));
// window = first of (this month - 11) .. first of next month
$w1s = date('Y-m-01', strtotime('-11 months'));
$w1e = date('Y-m-01', strtotime('+1 month'));
$gtC = gtv("SELECT COUNT(*) FROM consultations WHERE consultation_date>=? AND consultation_date<?", 'ss', [$w1s,$w1e]);
$gtS = gtv("SELECT COUNT(*) FROM consultations WHERE signoff_date>=? AND signoff_date<?", 'ss', [$w1s,$w1e]);
t_ok("time1 New Consultations total (12mo)", $tCons === $gtC, "displayed=".var_export($tCons,true)." truth=$gtC");
t_ok("time1 Sign-Offs total (12mo)",        $tSign === $gtS, "displayed=".var_export($tSign,true)." truth=$gtS");
$nonIcu = "(current_location != 'ICU' OR current_location IS NULL)";
$tAdm = arr_sum(js_arr_longest($body,'admissions'));
$tDis = arr_sum(js_arr_longest($body,'discharges'));
$gtA = gtv("SELECT COUNT(*) FROM picupatients WHERE ADMDATE>=? AND ADMDATE<? AND $nonIcu",'ss',[$w1s,$w1e]);
$gtD = gtv("SELECT COUNT(*) FROM picupatients WHERE DISDATE>=? AND DISDATE<? AND $nonIcu",'ss',[$w1s,$w1e]);
t_ok("time1 Admissions total (12mo)", $tAdm === $gtA, "displayed=".var_export($tAdm,true)." truth=$gtA");
t_ok("time1 Discharges total (12mo)", $tDis === $gtD, "displayed=".var_export($tDis,true)." truth=$gtD");

/* ============================================== charts1.php — per-consultant for a month == */
e2e_section('charts1.php (admission KPI) — per-consultant consultations for the month');
$body = e2e_get('/statistics/charts1.php?' . http_build_query(['kpi'=>'admission','date'=>$DATE,'interval'=>'monthly']), 'admin')['body'];
$c1Cons = arr_sum(js_arr($body, 'newconsults'));   // summed over all consultants = global month total
$c1Sign = arr_sum(js_arr($body, 'signedoff'));
$gtC = gtv("SELECT COUNT(*) FROM consultations WHERE consultation_date>=? AND consultation_date< ? AND consultant_id IS NOT NULL", 'ss', [$mStart,$mEnd]);
$gtS = gtv("SELECT COUNT(*) FROM consultations WHERE signoff_date>=? AND signoff_date< ? AND consultant_id IS NOT NULL", 'ss', [$mStart,$mEnd]);
t_ok("charts1 New Consultations (month, all consultants)", $c1Cons === $gtC, "displayed=".var_export($c1Cons,true)." truth=$gtC");
t_ok("charts1 Sign-Offs (month, all consultants)",        $c1Sign === $gtS, "displayed=".var_export($c1Sign,true)." truth=$gtS");
// charts1 charts only ACTIVE position-3 consultants (SELECT ... WHERE position='3' AND active=1),
// so the GT must restrict to that same consultant set (admissions assigned to inactive/non-3
// members are excluded by design — that is the chart's documented scope, not a miscount).
$ni = "(current_location != 'ICU' OR current_location IS NULL)";
$inActiveCon = "consultant_id IN (SELECT member_id FROM members WHERE position='3' AND active=1)";
$c1Adm = arr_sum(js_arr_longest($body,'admissions'));
$c1Dis = arr_sum(js_arr_longest($body,'discharges'));
$gtA = gtv("SELECT COUNT(*) FROM picupatients WHERE ADMDATE>=? AND ADMDATE<? AND $inActiveCon AND $ni",'ss',[$mStart,$mEnd]);
$gtD = gtv("SELECT COUNT(*) FROM picupatients WHERE DISDATE>=? AND DISDATE<? AND $inActiveCon AND $ni",'ss',[$mStart,$mEnd]);
t_ok("charts1 Admissions (month, active position-3 consultants)", $c1Adm === $gtA, "displayed=".var_export($c1Adm,true)." truth=$gtA");
t_ok("charts1 Discharges (month, active position-3 consultants)", $c1Dis === $gtD, "displayed=".var_export($c1Dis,true)." truth=$gtD");

/* ============================================== charts.php — per-physician (the suspect) == */
foreach (['monthly'=>$yStart, 'quarterly'=>$yStart, 'daily'=>$mStart] as $iv => $_s) {
    e2e_section("charts.php (per-physician) — consultant $CID, $iv");
    $body = e2e_get('/statistics/charts.php?' . http_build_query(['consultant'=>$CID,'date'=>$DATE,'interval'=>$iv]), 'admin')['body'];
    $cn = arr_sum(js_arr_longest($body, 'newconsults'));
    $sg = arr_sum(js_arr_longest($body, 'signedoff'));
    $ad = arr_sum(js_arr_longest($body, 'admissions'));   // longest = the per-period series (not the empty doughnut arrays)
    $di = arr_sum(js_arr_longest($body, 'discharges'));
    if ($iv === 'daily') { $ws=$mStart; $we=$mEnd; } else { $ws=$yStart; $we=$yEnd; }
    $gtCn = gtv("SELECT COUNT(*) FROM consultations WHERE consultation_date>=? AND consultation_date<? AND consultant_id=?", 'ssi', [$ws,$we,$CID]);
    $gtSg = gtv("SELECT COUNT(*) FROM consultations WHERE signoff_date>=? AND signoff_date<? AND consultant_id=?", 'ssi', [$ws,$we,$CID]);
    $gtAd = gtv("SELECT COUNT(*) FROM picupatients WHERE ADMDATE>=? AND ADMDATE<? AND consultant_id=? AND (current_location!='ICU' OR current_location IS NULL)", 'ssi', [$ws,$we,$CID]);
    $gtDi = gtv("SELECT COUNT(*) FROM picupatients WHERE DISDATE>=? AND DISDATE<? AND consultant_id=? AND (current_location!='ICU' OR current_location IS NULL)", 'ssi', [$ws,$we,$CID]);
    t_ok("charts.php $iv New Consultations (consultant $CID)", $cn === $gtCn, "displayed=".var_export($cn,true)." truth=$gtCn");
    t_ok("charts.php $iv Sign-Offs (consultant $CID)",         $sg === $gtSg, "displayed=".var_export($sg,true)." truth=$gtSg");
    t_ok("charts.php $iv Admissions (consultant $CID)",        $ad === $gtAd, "displayed=".var_export($ad,true)." truth=$gtAd");
    t_ok("charts.php $iv Discharges (consultant $CID)",        $di === $gtDi, "displayed=".var_export($di,true)." truth=$gtDi");
}

/* ===================================== charts.php monthly — per-MONTH correctness ======== */
e2e_section("charts.php monthly — per-MONTH consultations correct for ALL 12 months (consultant $CID)");
$body = e2e_get('/statistics/charts.php?' . http_build_query(['consultant'=>$CID,'date'=>$DATE,'interval'=>'monthly']), 'admin')['body'];
$labels = js_arr_longest($body, 'label');
$series = js_arr_longest($body, 'newconsults');
$mism = [];
$okShape = is_array($labels) && is_array($series) && count($labels) === count($series) && count($series) === 12;
if ($okShape) {
    foreach ($labels as $i => $mname) {
        $mnum = (int) date('n', strtotime($mname . ' 1 ' . $Y));
        $gt = gtv("SELECT COUNT(*) FROM consultations WHERE YEAR(consultation_date)=? AND MONTH(consultation_date)=? AND consultant_id=?", 'iii', [$Y, $mnum, $CID]);
        if ((int) $series[$i] !== $gt) { $mism[] = "$mname disp={$series[$i]} gt=$gt"; }
    }
}
t_ok("charts.php monthly newconsults correct in EVERY month", $okShape && !$mism, $mism ? implode('; ', $mism) : ($okShape ? 'all 12 months match ground truth' : 'extract failed'));

/* ================================================================= dashboard/1.php ======= */
e2e_section('dashboard/1.php — Active Consultations doughnut [signed-off (last day), active]');
$body = e2e_get('/dashboard/1.php', 'admin')['body'];
$d1 = js_arr($body, 'all_count2');
$gtSO = gtv("SELECT COUNT(*) FROM consultations WHERE signoff_date + INTERVAL 1 DAY >= CURDATE()");
$gtAC = gtv("SELECT COUNT(*) FROM consultations WHERE signoff_date IS NULL");
t_ok("dashboard/1 signed-off (since last day)", $d1 && (int)$d1[0] === $gtSO, "displayed=".($d1[0]??'?')." truth=$gtSO");
t_ok("dashboard/1 active consultations",        $d1 && (int)$d1[1] === $gtAC, "displayed=".($d1[1]??'?')." truth=$gtAC");

/* ================================================================= dashboard/3.php ======= */
e2e_section('dashboard/3.php — YTD Total Consultations & Total Sign Offs');
$body = e2e_get('/dashboard/3.php', 'admin')['body'];
$thisYear = (int) date('Y');
preg_match('#Total Consultations for\s*' . $thisYear . '</th>\s*<th[^>]*>\s*(\d+)#s', $body, $mc);
preg_match('#Total Sign Offs</th>\s*<th[^>]*>\s*(\d+)#s', $body, $ms);
$dCons = isset($mc[1]) ? (int)$mc[1] : null;
$dSign = isset($ms[1]) ? (int)$ms[1] : null;
$gtDCons = gtv("SELECT COUNT(*) FROM consultations WHERE YEAR(consultation_date)=$thisYear");
$gtDSign = gtv("SELECT COUNT(*) FROM consultations WHERE YEAR(signoff_date)=$thisYear"); // independent of consultation_date year
t_ok("dashboard/3 Total Consultations ($thisYear)", $dCons === $gtDCons, "displayed=".var_export($dCons,true)." truth=$gtDCons");
t_ok("dashboard/3 Total Sign Offs ($thisYear) = sign-offs done this year", $dSign === $gtDSign, "displayed=".var_export($dSign,true)." truth=$gtDSign");

/* ================================================================= a4.php (yearly) ======= */
e2e_section("a4.php — yearly New Consultations & Sign-Offs ($Y)");
$body = e2e_get('/statistics/a4.php?y=' . $Y, 'admin')['body'];
$aCons = arr_sum(js_arr($body, 'newconsults'));
$aSign = arr_sum(js_arr($body, 'signedoff'));
$gtAC = gtv("SELECT COUNT(*) FROM consultations WHERE consultation_date>=? AND consultation_date<?", 'ss', [$yStart,$yEnd]);
$gtAS = gtv("SELECT COUNT(*) FROM consultations WHERE signoff_date>=? AND signoff_date<?", 'ss', [$yStart,$yEnd]);
t_ok("a4 New Consultations total ($Y)", $aCons === $gtAC, "displayed=".var_export($aCons,true)." truth=$gtAC");
t_ok("a4 Sign-Offs total ($Y)",         $aSign === $gtAS, "displayed=".var_export($aSign,true)." truth=$gtAS");

exit(e2e_summary());
