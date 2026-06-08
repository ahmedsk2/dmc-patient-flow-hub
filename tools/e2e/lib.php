<?php
/**
 * tools/e2e/lib.php — shared harness for the A-to-Z end-to-end tests.
 *
 * LOCAL ONLY. Talks to the running dev server (php -S 127.0.0.1:8765 dev-router.php)
 * over real HTTP — so every request goes through guard.php / csrf.php / validate.php
 * exactly as a browser would — and to the dmc_prod DB directly for assertions/cleanup.
 *
 * It is NOT committed-for-prod: it creates throwaway e2e_* accounts and 99-prefixed test
 * MRNs in the (local) prod copy, and cleans them up at the end of the functional run.
 */

error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_OFF);
date_default_timezone_set('Asia/Riyadh');

const E2E_BASE   = 'http://127.0.0.1:8765';
const E2E_DBNAME = 'dmc_prod';
const E2E_PASS   = 'E2eTest!2026';
const E2E_MRN_PREFIX = '99900';          // test patients use MRNs 99900000001.. (11 digits, identifiable)

/** role => account spec. caps: [assign_access, add_new_patient, manage_patient, modify_patient] */
function e2e_accounts() {
    return [
        // role-key        name             pos  active assign add manage modify on_service specialty
        'admin'      => ['e2e_admin',      0, 1, 1,1,1,1, 1, 1],
        'registrar'  => ['e2e_registrar',  2, 1, 1,1,1,1, 0, null],
        'consultant' => ['e2e_consultant', 3, 1, 0,0,0,0, 1, 1],   // no extra caps; tests object-level ownership
        'resident'   => ['e2e_resident',   4, 1, 0,0,0,0, 0, null],
        'observer'   => ['e2e_observer',   5, 1, 0,0,0,0, 0, null],
    ];
}

function e2e_db() {
    static $db = null;
    if ($db === null) {
        // Follow the SAME database the app is configured for (config.local.php), so the harness's
        // direct-DB assertions match what the dev server reads. Falls back to local WAMP defaults.
        $cfg = dirname(__DIR__, 2) . '/config.local.php';
        if (is_file($cfg)) { require_once $cfg; }
        $host = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
        $user = defined('DB_USER') ? DB_USER : 'root';
        $pass = defined('DB_PASS') ? DB_PASS : '';
        $name = defined('DB_NAME') ? DB_NAME : E2E_DBNAME;
        // Safety: this harness creates accounts + test rows and must NEVER touch a remote/prod DB.
        if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            fwrite(STDERR, "E2E refuses to run against non-local DB host '$host'. Local testing only.\n");
            exit(2);
        }
        $db = new mysqli($host, $user, $pass, $name);
        if ($db->connect_errno) { fwrite(STDERR, "DB connect failed: {$db->connect_error}\n"); exit(2); }
    }
    return $db;
}

function e2e_dbname() { return e2e_db()->query('SELECT DATABASE() AS d')->fetch_assoc()['d']; }

function e2e_member_id($name) {
    $db = e2e_db();
    $st = $db->prepare("SELECT member_id FROM members WHERE member_name = ?");
    $st->bind_param('s', $name);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    return $row ? (int) $row['member_id'] : 0;
}

/* ----------------------------------------------------------------------------- HTTP ---- */

function e2e_jar($role) {
    $dir = sys_get_temp_dir() . '/dmc_e2e';
    if (!is_dir($dir)) { mkdir($dir, 0700, true); }
    return $dir . "/cookies_{$role}.txt";
}

/** low-level request. returns ['code'=>int,'body'=>str,'location'=>?str]. */
function e2e_http($method, $path, $fields = null, $role = 'anon', $opts = []) {
    $url = E2E_BASE . $path;
    $ch = curl_init();
    $jar = e2e_jar($role);
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,         // we inspect redirects ourselves
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $headers = [];
    if (!empty($opts['ajax'])) { $headers[] = 'X-Requested-With: XMLHttpRequest'; }

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        // attach CSRF token (field + header) unless explicitly suppressed
        if (is_array($fields) && empty($opts['no_csrf'])) {
            $tok = e2e_ctx($role)['csrf'] ?? '';
            if ($tok !== '' && !isset($fields['csrf_token'])) { $fields['csrf_token'] = $tok; }
            if ($tok !== '') { $headers[] = 'X-CSRF-Token: ' . $tok; }
        }
        // http_build_query gives correct name[]=a&name[]=b encoding for array fields
        $body = is_array($fields) ? http_build_query($fields) : (string) $fields;
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if ($headers) { curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); }

    $raw  = curl_exec($ch);
    if ($raw === false) { $err = curl_error($ch); curl_close($ch); return ['code' => 0, 'body' => "CURL ERROR: $err", 'location' => null]; }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $rawHeaders = substr($raw, 0, $hlen);
    $body = substr($raw, $hlen);
    $loc = null;
    if (preg_match('/^location:\s*(.+)$/mi', $rawHeaders, $mm)) { $loc = trim($mm[1]); }
    return ['code' => (int) $code, 'body' => $body, 'location' => $loc];
}

function e2e_get($path, $role = 'anon', $opts = [])              { return e2e_http('GET',  $path, null, $role, $opts); }
function e2e_post($path, $fields, $role = 'anon', $opts = [])    { return e2e_http('POST', $path, $fields, $role, $opts + ['ajax' => true]); }

/* --------------------------------------------------------------------------- session ---- */

function &e2e_ctx($role) {
    static $ctx = [];
    if (!isset($ctx[$role])) { $ctx[$role] = ['csrf' => '', 'member_id' => 0, 'logged_in' => false]; }
    return $ctx[$role];
}

/** Log in as a role over real HTTP. Returns true on success. */
function e2e_login($role) {
    $accts = e2e_accounts();
    if (!isset($accts[$role])) { fwrite(STDERR, "unknown role $role\n"); return false; }
    $name = $accts[$role][0];
    @unlink(e2e_jar($role));                                  // fresh session

    $g = e2e_get('/index.php', $role);
    if (!preg_match('/name="csrf_token"\s+value="([0-9a-f]+)"/', $g['body'], $m)) {
        fwrite(STDERR, "[$role] could not scrape login CSRF token\n"); return false;
    }
    $loginTok = $m[1];

    $r = e2e_http('POST', '/index.php', [
        'csrf_token'      => $loginTok,
        'member_name'     => $name,
        'member_password' => E2E_PASS,
        'login'           => 'Login',
    ], $role);  // NOT ajax — it's a real form post

    // success = redirect to dashboard.php
    $okRedirect = $r['location'] && stripos($r['location'], 'dashboard.php') !== false;
    if (!$okRedirect) {
        $note = $r['location'] ? "redirect={$r['location']}" : "code={$r['code']}";
        if (stripos($r['body'], 'Invalid Login') !== false) $note .= ' (Invalid Login)';
        if (stripos($r['body'], 'activation') !== false)    $note .= ' (needs activation)';
        fwrite(STDERR, "[$role] login did not reach dashboard: $note\n");
        return false;
    }
    // grab the live session CSRF token from an authenticated page (footer exposes window.DMC_CSRF)
    $d = e2e_get('/dashboard.php', $role);
    $ctx = &e2e_ctx($role);
    if (preg_match('/window\.DMC_CSRF\s*=\s*"([0-9a-f]+)"/', $d['body'], $mm)) {
        $ctx['csrf'] = $mm[1];
    } else {
        $ctx['csrf'] = $loginTok;   // fallback: token survives session_regenerate_id
    }
    $ctx['member_id'] = e2e_member_id($name);
    $ctx['logged_in'] = true;
    return true;
}

/* ------------------------------------------------------------------------ assertions ---- */

$GLOBALS['E2E'] = ['pass' => 0, 'fail' => 0, 'fails' => [], 'section' => ''];

function e2e_section($title) {
    $GLOBALS['E2E']['section'] = $title;
    echo "\n=== $title ===\n";
}

function t_ok($label, $cond, $detail = '') {
    if ($cond) {
        $GLOBALS['E2E']['pass']++;
        printf("  PASS  %s%s\n", $label, $detail !== '' ? "  ($detail)" : '');
    } else {
        $GLOBALS['E2E']['fail']++;
        $GLOBALS['E2E']['fails'][] = ($GLOBALS['E2E']['section'] ? $GLOBALS['E2E']['section'] . ' :: ' : '') . $label . ($detail !== '' ? "  ($detail)" : '');
        printf("  FAIL  %s%s\n", $label, $detail !== '' ? "  ($detail)" : '');
    }
    return (bool) $cond;
}

function e2e_summary() {
    $E = $GLOBALS['E2E'];
    echo "\n" . str_repeat('=', 70) . "\n";
    printf("E2E RESULT: %d passed, %d failed\n", $E['pass'], $E['fail']);
    if ($E['fails']) {
        echo "FAILURES:\n";
        foreach ($E['fails'] as $f) { echo "  - $f\n"; }
    }
    echo str_repeat('=', 70) . "\n";
    return $E['fail'] === 0 ? 0 : 1;
}

/** convenience: does an action response indicate success (matches footer.php window.dmcOk) */
function e2e_says_success($body) { return stripos((string) $body, 'successfully') !== false; }
