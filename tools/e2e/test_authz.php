<?php
/**
 * tools/e2e/test_authz.php — authorization matrix + CSRF + object-level (IDOR) checks.
 *
 * For each action endpoint we POST as every role (with a valid CSRF token) and assert the guard
 * outcome: a denied role gets HTTP 403; an allowed role gets through the guard (not 403/419).
 * Empty payloads are used so "allowed" requests pass the guard and then no-op on validation — no
 * data is mutated by the matrix sweep. Object-level and CSRF sections create/cleanup their own rows.
 */
require __DIR__ . '/lib.php';

$db = e2e_db();
$roles = ['admin','registrar','consultant','resident','observer'];
foreach ($roles as $r) { if (!e2e_login($r)) { fwrite(STDERR, "login $r failed\n"); exit(2); } }

$pos  = ['admin'=>0,'registrar'=>2,'consultant'=>3,'resident'=>4,'observer'=>5];
$caps = [ // capability flags each test account holds
    'admin'      => ['assign_access','add_new_patient','manage_patient','modify_patient'],
    'registrar'  => ['assign_access','add_new_patient','manage_patient','modify_patient'],
    'consultant' => [], 'resident' => [], 'observer' => [],
];
$has = fn($role,$cap) => $role === 'admin' || in_array($cap, $caps[$role], true);

// endpoint spec: pos=allowed positions; cap=required capability; anycap=any-of; pa=require_patient_access(id=0)
$M = [
    ['/newpatients/dmc-patients-add.php',            [0,2,3,4], 'add_new_patient', null, false],
    ['/newpatients/dmc-patients-icu-transfer.php',   [0,2,3,4], 'add_new_patient', null, false],
    ['/newpatients/dmc-new-patients-update.php',     [0,2,3,4], null, null, false],
    ['/newpatients/dmc-patients-shuffle.php',        [0,2,3,4], 'assign_access', null, false],
    ['/newpatients/dmc-assign-to-primary.php',       [0,2,3,4], 'assign_access', null, false],
    ['/patients/dmc-patients-modify.php',            [0,2,3,4], 'modify_patient', null, false],
    ['/patients/dmc-patients-details.php',           [0,2,3,4], 'modify_patient', null, false],
    ['/patients/dmc-patients-update.php',            [0,2,3,4], null, null, false],
    ['/patients/dmc-patients-discharge-submit.php',  [0,2,3,4], null, null, true],
    ['/patients/dmc-patients-complete-discharge-submit.php', [0,2,3,4], null, null, true],
    ['/patients/dmc-patients-icu-discharge-submit.php',      [0,2,3,4], null, null, true],
    ['/patients/dmc-patients-submit-changeconsultant.php',   [0,2,3,4], null, ['assign_access','manage_patient'], false],
    ['/patients/dmc-patients-changeconsultant.php',  [0,2,3,4], null, ['assign_access','manage_patient'], false],
    ['/patients/dmc-specialty-transfer-dropdown.php',[0,2,3,4], null, null, false],
    ['/patients/dmc-patient-delete.php',             [0],       null, null, false],
    ['/consultations/dmc-consultation-add.php',      [0,2,3,4], null, null, false],
    ['/consultations/dmc-consultation-modify.php',   [0,2,3,4], null, null, false],
    ['/consultations/dmc-consultation-details.php',  [0,2,3,4], null, null, false],
    ['/consultations/dmc-consultation-delete.php',   [0],       null, null, false],
    ['/registry/search-results.php',                 [0],       null, null, false],
    ['/registry/search-results-consultations.php',   [0],       null, null, false],
    ['/registry/search-results-diagnosis.php',       [0],       null, null, false],
    ['/registry/dmc-search-patient-details.php',     [0],       null, null, false],
    ['/registry/dmc-search-patients-modify.php',     [0],       null, null, false],
    ['/statistics/kpis.php',                         [0],       null, null, false],
    ['/statistics/charts.php',                       [0],       null, null, false],
    ['/statistics/charts1.php',                      [0],       null, null, false],
    ['/statistics/time1.php',                        [0],       null, null, false],
    ['/dashboard/1.php',                             [0,2,3,4,5], null, null, false], // require_login only
    ['/dashboard/3.php',                             [0,2,3,4,5], null, null, false],
];

$expected = function($spec, $role) use ($pos, $has) {
    [$path, $allowedPos, $cap, $anycap, $pa] = $spec;
    if (!in_array($pos[$role], $allowedPos, true)) return 'deny';
    if ($role === 'admin') return 'allow';
    if ($cap !== null)    return $has($role, $cap) ? 'allow' : 'deny';
    if ($anycap !== null) { foreach ($anycap as $c) if ($has($role,$c)) return 'allow'; return 'deny'; }
    if ($pa)              return $has($role, 'manage_patient') ? 'allow' : 'deny'; // id=0 => owner path can't match
    return 'allow';
};

e2e_section('Authorization matrix (guard outcome per role)');
foreach ($M as $spec) {
    $path = $spec[0];
    foreach ($roles as $role) {
        $exp = $expected($spec, $role);
        $r = e2e_post($path, [], $role); // valid CSRF token auto-attached; empty body => allowed reqs no-op
        $code = $r['code'];
        if ($exp === 'deny') {
            t_ok("DENY  $role -> $path", $code === 403, "code=$code");
        } else {
            t_ok("ALLOW $role -> $path", $code !== 403 && $code !== 419, "code=$code");
        }
    }
}

/* ---------------------------------------------------------------- CSRF enforcement -------- */
e2e_section('CSRF enforcement on action endpoints (missing token => 419)');
// these roles pass the role/cap gate, so the next check (csrf_verify) is what fires
$r = e2e_post('/newpatients/dmc-patients-add.php', ['mrn_new'=>'1'], 'registrar', ['no_csrf'=>true]);
t_ok('add-patient without CSRF token -> 419', $r['code'] === 419, 'code=' . $r['code']);
$r = e2e_post('/consultations/dmc-consultation-add.php', ['mrn_new'=>'1'], 'admin', ['no_csrf'=>true]);
t_ok('consultation-add without CSRF token -> 419', $r['code'] === 419, 'code=' . $r['code']);
$r = e2e_post('/patients/dmc-patient-delete.php', ['id'=>'1'], 'admin', ['no_csrf'=>true]);
t_ok('patient-delete without CSRF token -> 419', $r['code'] === 419, 'code=' . $r['code']);

/* --------------------------------------------- CSRF GAP probe: page-handler assign writes - */
e2e_section('CSRF on assign page-handlers (dmc-new-admissions.php)');
$db->query("DELETE FROM picupatients WHERE MRN LIKE '999000%'");
e2e_post('/newpatients/dmc-patients-add.php', [
    'bed_new'=>'Z1','mrn_new'=>'999000200','gender_new'=>'Male','pname_new'=>'E2E_TEST 999000200',
    'nationality_new'=>'Saudi Arabia','age_new'=>40,'current_location_new'=>'Ward','admdate_new'=>date('Y-m-d'),
    'admfrom_new'=>'ER','admissiondiagnosis_new'=>['A000    '],
], 'admin');
$pid = (int)($db->query("SELECT ID FROM picupatients WHERE MRN='999000200' ORDER BY ID DESC LIMIT 1")->fetch_assoc()['ID'] ?? 0);
$consultantId = e2e_ctx('consultant')['member_id'];
// attempt assign-to-me WITHOUT a CSRF token
e2e_post('/dmc-new-admissions.php', ['assign_me_btn'=>'1','patientid'=>$pid], 'consultant', ['no_csrf'=>true]);
$assigned = (int)($db->query("SELECT consultant_id FROM picupatients WHERE ID=$pid")->fetch_assoc()['consultant_id'] ?? 0);
t_ok('assign-to-me REQUIRES CSRF token (write blocked without one)', $assigned === 0,
     $assigned === 0 ? 'blocked (good)' : "GAP: assigned to $assigned without a token");

/* ---------------------------------------------------------- object-level (IDOR) checks ---- */
e2e_section('Object-level authorization (consultant: own patient vs others)');
// patient owned by the consultant
e2e_post('/dmc-new-admissions.php', ['assign_me_btn'=>'1','patientid'=>$pid], 'consultant'); // with token now
$ownPid = $pid;
// patient owned by admin (assign to admin)
e2e_post('/newpatients/dmc-patients-add.php', [
    'bed_new'=>'Z2','mrn_new'=>'999000201','gender_new'=>'Female','pname_new'=>'E2E_TEST 999000201',
    'nationality_new'=>'Saudi Arabia','age_new'=>41,'current_location_new'=>'Ward','admdate_new'=>date('Y-m-d'),
    'admfrom_new'=>'ER','admissiondiagnosis_new'=>['A000    '],
], 'admin');
$otherPid = (int)$db->query("SELECT ID FROM picupatients WHERE MRN='999000201' ORDER BY ID DESC LIMIT 1")->fetch_assoc()['ID'];
$db->query("UPDATE picupatients SET consultant_id=" . e2e_ctx('admin')['member_id'] . " WHERE ID=$otherPid");

// consultant discharging OWN patient -> guard passes (not 403)
$r = e2e_post('/patients/dmc-patients-discharge-submit.php', ['id_modify'=>$ownPid], 'consultant');
t_ok('consultant CAN reach discharge for OWN patient', $r['code'] !== 403, 'code=' . $r['code']);
// consultant discharging someone ELSE's patient -> 403
$r = e2e_post('/patients/dmc-patients-discharge-submit.php', ['id_modify'=>$otherPid], 'consultant');
t_ok('consultant CANNOT discharge another consultant\'s patient (IDOR blocked)', $r['code'] === 403, 'code=' . $r['code']);

/* ---------------------------------------------------------- Observer read-only board ------ */
e2e_section('Observer read-only access');
$r = e2e_get('/dmc-patients.php', 'observer');
t_ok('observer CAN view the patient board (read-only via $access_PICU_view)',
     $r['code'] === 200 && stripos($r['body'], 'permission') === false, 'code=' . $r['code']);
$r = e2e_get('/statistics.php', 'observer');
t_ok('observer sees access-denied on admin statistics page', stripos($r['body'], 'permission') !== false || $r['code'] === 403);

/* cleanup */
$db->query("DELETE FROM picupatients WHERE MRN LIKE '999000%'");

exit(e2e_summary());
