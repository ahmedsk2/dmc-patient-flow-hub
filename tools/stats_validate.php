<?php
/**
 * tools/stats_validate.php — golden-master validation harness for the statistics endpoints.
 *
 * Purpose: the statistics rewrite (grouped SQL + caching) MUST NOT change any displayed
 * clinical number. This tool captures each stats endpoint's rendered output for a fixed
 * matrix of parameters, so we can diff BEFORE vs AFTER a rewrite and prove equivalence.
 *
 * Self-contained: uses only ext-curl + ext-hash (no Composer). Talks to the running app
 * over HTTP as an authenticated admin, exactly as the browser does — so the baseline is the
 * real end-to-end output (numbers live inside the returned chart-data / tables).
 *
 * Usage (local dev server must be running):
 *   php tools/stats_validate.php capture before            # snapshot current output
 *   php tools/stats_validate.php capture after  [--with-a4] # snapshot again (after a change)
 *   php tools/stats_validate.php compare before after       # diff -> reports any change
 *   php tools/stats_validate.php list                       # show the param matrix
 *
 * Config via env (defaults target the local WAMP dev server + the local test admin):
 *   DMC_BASE (http://127.0.0.1:8765)  DMC_USER (localtest)  DMC_PASS (LocalTest123!)
 *
 * Baselines are written under tools/stats-baselines/<label>/ (git-ignored).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$BASE = rtrim(getenv('DMC_BASE') ?: 'http://127.0.0.1:8765', '/');
$USER = getenv('DMC_USER') ?: 'localtest';
$PASS = getenv('DMC_PASS') ?: 'LocalTest123!';
$ROOT = __DIR__ . '/stats-baselines';
$COOKIE = tempnam(sys_get_temp_dir(), 'dmcjar');

// A representative date that sits inside the data, plus the recent A4 years.
$DATE = getenv('DMC_STATS_DATE') ?: '2024-06-15';

/** Build the endpoint × parameter matrix that mirrors what statistics.php / allstat.php send. */
function build_matrix($date, $withA4) {
    $m = [];
    foreach (['admission', 'los', 'readmission'] as $kpi) {
        foreach (['daily', 'monthly', 'quarterly'] as $iv) {
            $m["charts1__{$kpi}__{$iv}"] = ['statistics/charts1.php', ['kpi' => $kpi, 'date' => $date, 'interval' => $iv]];
        }
    }
    foreach (['44', '317', '70'] as $c) { // a few on-service consultants
        foreach (['monthly', 'quarterly'] as $iv) {
            $m["charts__c{$c}__{$iv}"] = ['statistics/charts.php', ['consultant' => $c, 'date' => $date, 'interval' => $iv]];
        }
    }
    foreach (['daily', 'monthly', 'quarterly'] as $t) {
        $m["time1__{$t}"]  = ['statistics/time1.php', ['timing1' => $t]];
        $m["kpis__{$t}"]   = ['statistics/kpis.php', ['date2' => $date, 'timing2' => $t]];
    }
    if ($withA4) {
        foreach (['2023', '2024'] as $y) {
            $m["a4__{$y}"]        = ['statistics/a4.php', ['y' => $y]];
            $m["a4monthly__{$y}"] = ['statistics/a4-monthly.php', ['y' => $y]];
        }
    }
    return $m;
}

/** Normalize a response so a same-day re-capture diffs only on real number/content changes. */
function normalize($html) {
    // Drop volatile bits that legitimately vary between runs but aren't "the numbers":
    $html = preg_replace('/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', '<TS>', $html); // timestamps
    $html = preg_replace('/\s+/', ' ', $html);                                       // whitespace
    return trim($html);
}

function curl_base($cookie) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 300,
    ]);
    return $ch;
}

function login($base, $user, $pass, $cookie) {
    $ch = curl_base($cookie);
    curl_setopt($ch, CURLOPT_URL, "$base/index.php");
    $login = curl_exec($ch);
    if ($login === false) { fwrite(STDERR, "Cannot reach $base — is the dev server running?\n"); exit(2); }
    preg_match('/name="csrf_token" value="([^"]+)"/', $login, $mm);
    $token = $mm[1] ?? '';
    curl_setopt($ch, CURLOPT_URL, "$base/index.php");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'member_name' => $user, 'member_password' => $pass, 'login' => 'Login', 'csrf_token' => $token,
    ]));
    $after = curl_exec($ch);
    curl_close($ch);
    if (strpos((string) $after, 'Dashboard') === false && strpos((string) $after, 'sidebar') === false) {
        fwrite(STDERR, "Login failed for '$user' (check DMC_USER/DMC_PASS).\n"); exit(2);
    }
}

function fetch($base, $endpoint, $params, $cookie) {
    $ch = curl_base($cookie);
    curl_setopt($ch, CURLOPT_URL, "$base/$endpoint?" . http_build_query($params));
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (string) $body];
}

// ---- main ----
$cmd = $argv[1] ?? '';
$withA4 = in_array('--with-a4', $argv, true);
$matrix = build_matrix($DATE, $withA4);

if ($cmd === 'list') {
    foreach ($matrix as $k => [$ep, $p]) { echo str_pad($k, 28) . " $ep?" . http_build_query($p) . "\n"; }
    echo "\n" . count($matrix) . " cases (date=$DATE)" . ($withA4 ? " incl. A4" : "") . "\n";
    exit(0);
}

if ($cmd === 'capture') {
    $label = $argv[2] ?? '';
    if ($label === '') { fwrite(STDERR, "usage: capture <label> [--with-a4]\n"); exit(1); }
    $dir = "$ROOT/$label";
    if (!is_dir($dir)) { mkdir($dir, 0700, true); }
    login($BASE, $USER, $PASS, $COOKIE);
    $manifest = [];
    foreach ($matrix as $key => [$ep, $params]) {
        [$code, $body] = fetch($BASE, $ep, $params, $COOKIE);
        $norm = normalize($body);
        file_put_contents("$dir/$key.txt", $norm);
        $manifest[$key] = ['http' => $code, 'bytes' => strlen($norm), 'sha' => substr(hash('sha256', $norm), 0, 16)];
        printf("  %-28s HTTP %s  %6d bytes  %s\n", $key, $code, strlen($norm), $manifest[$key]['sha']);
    }
    file_put_contents("$dir/_manifest.json", json_encode($manifest, JSON_PRETTY_PRINT));
    @unlink($COOKIE);
    echo "captured " . count($matrix) . " cases -> $dir\n";
    exit(0);
}

if ($cmd === 'compare') {
    $a = $argv[2] ?? ''; $b = $argv[3] ?? '';
    $ma = @json_decode(@file_get_contents("$ROOT/$a/_manifest.json"), true);
    $mb = @json_decode(@file_get_contents("$ROOT/$b/_manifest.json"), true);
    if (!$ma || !$mb) { fwrite(STDERR, "missing baseline(s): need $ROOT/$a and $ROOT/$b\n"); exit(1); }
    $keys = array_unique(array_merge(array_keys($ma), array_keys($mb)));
    sort($keys);
    $diffs = 0;
    foreach ($keys as $k) {
        $sa = $ma[$k]['sha'] ?? null; $sb = $mb[$k]['sha'] ?? null;
        if ($sa === $sb && $sa !== null) { continue; }
        $diffs++;
        echo "DIFF  $k   $a=" . ($sa ?? 'missing') . "  $b=" . ($sb ?? 'missing') . "\n";
        // show first differing line for a quick read
        $fa = @file("$ROOT/$a/$k.txt"); $fb = @file("$ROOT/$b/$k.txt");
        if ($fa && $fb) {
            $la = explode(' ', $fa[0]); $lb = explode(' ', $fb[0]);
            for ($i = 0; $i < max(count($la), count($lb)); $i++) {
                if (($la[$i] ?? '') !== ($lb[$i] ?? '')) {
                    echo "      first delta near: ...'" . implode(' ', array_slice($la, max(0, $i - 2), 5)) . "' vs '" . implode(' ', array_slice($lb, max(0, $i - 2), 5)) . "'\n";
                    break;
                }
            }
        }
    }
    echo $diffs === 0 ? "\n✓ IDENTICAL — all " . count($keys) . " cases match ($a vs $b)\n"
                      : "\n✗ $diffs/" . count($keys) . " cases DIFFER ($a vs $b)\n";
    exit($diffs === 0 ? 0 : 1);
}

fwrite(STDERR, "usage: php tools/stats_validate.php {capture <label> [--with-a4] | compare <a> <b> | list}\n");
exit(1);
