<?php
/**
 * tools/e2e/test_stats.php — every statistics / dashboard data endpoint, across the param matrix.
 *
 * Asserts each returns HTTP 200, emits NO PHP error output (Fatal/Warning/Xdebug trace — which would
 * corrupt the eval'd dashboard scripts or the AJAX-injected chart fragments), is non-empty, and (for
 * chart endpoints) actually contains Chart.js data. This is the data-layer half of "are the graphs
 * working"; test_graphs / the browser pass is the render half.
 */
require __DIR__ . '/lib.php';
$db = e2e_db();
e2e_login('admin');

$cons = [];
$r = $db->query("SELECT member_id FROM members WHERE on_service=1 AND position=3 LIMIT 3");
while ($x = $r->fetch_assoc()) { $cons[] = $x['member_id']; }
if (!$cons) { $cons = [e2e_ctx('admin')['member_id']]; }
$DATE = '2024-06-15';
$YEAR = date('Y');

// [label, endpoint, params, isChart]
$M = [];
$M[] = ['dashboard/1', '/dashboard/1.php', [], true];
$M[] = ['dashboard/3', '/dashboard/3.php', [], false]; // tables (top-dx, YTD overview, per-consultant), not Chart.js
foreach (['daily','monthly','quarterly'] as $t) {
    $M[] = ["kpis/$t",  '/statistics/kpis.php',  ['date2'=>$DATE, 'timing2'=>$t], false];
    $M[] = ["time1/$t", '/statistics/time1.php', ['timing1'=>$t], true];
}
foreach (['admission','los','readmission'] as $kpi) {
    foreach (['daily','monthly','quarterly'] as $iv) {
        $M[] = ["charts1/$kpi/$iv", '/statistics/charts1.php', ['kpi'=>$kpi,'date'=>$DATE,'interval'=>$iv], true];
    }
}
foreach ($cons as $c) {
    foreach (['monthly','quarterly'] as $iv) {
        $M[] = ["charts/c$c/$iv", '/statistics/charts.php', ['consultant'=>$c,'date'=>$DATE,'interval'=>$iv], true];
    }
}
$M[] = ["a4/$YEAR",         '/statistics/a4.php',         ['y'=>$YEAR], true];
$M[] = ["a4/2024",          '/statistics/a4.php',         ['y'=>'2024'], true];
$M[] = ["a4-monthly/2024",  '/statistics/a4-monthly.php', ['y'=>'2024'], true];

$ERR = ['Fatal error','Parse error','Warning:','xdebug-error','Uncaught','Stack trace','must be of type'];

e2e_section('Statistics / dashboard data endpoints (clean output + chart data)');
foreach ($M as [$label, $ep, $params, $isChart]) {
    $r = e2e_get($ep . ($params ? ('?' . http_build_query($params)) : ''), 'admin');
    $b = $r['body'];
    $found = [];
    foreach ($ERR as $m) { if (stripos($b, $m) !== false) $found[] = $m; }
    $ok = $r['code'] === 200 && !$found && strlen($b) > 0;
    t_ok("clean  $label", $ok, 'code=' . $r['code'] . ' bytes=' . strlen($b) . ($found ? ' ERR=[' . implode(',', $found) . ']' : ''));
    if ($isChart) {
        $hasChart = stripos($b, 'Chart(') !== false || preg_match('/\bdata\s*:/', $b) || preg_match('/labels\s*:/', $b);
        t_ok("chart  $label has Chart.js data", (bool)$hasChart);
    }
}

exit(e2e_summary());
