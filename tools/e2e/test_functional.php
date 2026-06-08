<?php
/**
 * tools/e2e/test_functional.php — A-to-Z patient + consultation lifecycle over real HTTP.
 *
 * Exercises every state transition through the actual endpoints (guards/CSRF/validation included),
 * asserting the resulting DB state. Creates 99900-prefixed test patients in dmc_prod and DELETES
 * them at the end. Run setup_accounts.php first.
 */
require __DIR__ . '/lib.php';

$db = e2e_db();
foreach (['admin','registrar','consultant','resident','observer'] as $r) {
    if (!e2e_login($r)) { fwrite(STDERR, "cannot login $r — run setup_accounts.php\n"); exit(2); }
}
$consultantId = e2e_ctx('consultant')['member_id'];
$registrarId  = e2e_ctx('registrar')['member_id'];
$adminId      = e2e_ctx('admin')['member_id'];

// ---- reference data: a TB dx code and a normal dx code -------------------------------------
$tbCode = ($row = $db->query("SELECT dx_id FROM tb_list WHERE dx_id IS NOT NULL AND dx_id<>'' LIMIT 1")->fetch_assoc()) ? $row['dx_id'] : null;
$normalRow = $db->query("SELECT id FROM icd10 WHERE id NOT IN (SELECT dx_id FROM tb_list) LIMIT 1")->fetch_assoc();
$normalCode = $normalRow ? $normalRow['id'] : 'I10';
echo "Using tbCode=" . var_export($tbCode, true) . " normalCode=" . var_export($normalCode, true) . "\n";

// ---- helpers -------------------------------------------------------------------------------
function d($daysAgo) { return date('Y-m-d', strtotime("-$daysAgo days")); }
function p_latest($mrn) { // latest episode row for an MRN
    $db = e2e_db(); $st = $db->prepare("SELECT * FROM picupatients WHERE MRN=? ORDER BY ID DESC LIMIT 1");
    $st->bind_param('s', $mrn); $st->execute(); return $st->get_result()->fetch_assoc();
}
function p_by_id($id) {
    $db = e2e_db(); $st = $db->prepare("SELECT * FROM picupatients WHERE ID=?");
    $st->bind_param('i', $id); $st->execute(); return $st->get_result()->fetch_assoc();
}
function p_count($mrn) {
    $db = e2e_db(); $st = $db->prepare("SELECT COUNT(*) c FROM picupatients WHERE MRN=?");
    $st->bind_param('s', $mrn); $st->execute(); return (int)$st->get_result()->fetch_assoc()['c'];
}
function admit_ok($r) { // dmc-patients-add.php signals success with a JS redirect, not "successfully"
    return stripos($r['body'], 'window.location') !== false;
}
function admit($role, $mrn, $gender, $loc, $dx, $admdate, $opts = []) {
    return e2e_post('/newpatients/dmc-patients-add.php', [
        'bed_new' => $opts['bed'] ?? 'TB1',
        'mrn_new' => $mrn,
        'gender_new' => $gender,
        'pname_new' => $opts['name'] ?? ('E2E_TEST ' . $mrn),
        'nationality_new' => $opts['nat'] ?? 'Saudi Arabia',
        'age_new' => $opts['age'] ?? 49,
        'current_location_new' => $loc,
        'admdate_new' => $admdate,
        'admfrom_new' => $opts['admfrom'] ?? 'ER',
        'admissiondiagnosis_new' => $dx,        // array
    ], $role);
}

// ---- clean slate ---------------------------------------------------------------------------
$db->query("DELETE FROM picupatients WHERE MRN LIKE '99900%'");
$db->query("DELETE FROM consultations WHERE MRN LIKE '99900%'");

/* ============================================================ A. ADMISSIONS ============== */
e2e_section('A. Admissions (variants + validation)');

$r = admit('registrar', '999000001', 'Male', 'Ward', [$normalCode], d(5));
t_ok('A1 admit Male/Ward as registrar', admit_ok($r), 'code=' . $r['code']);
$A1 = p_latest('999000001');
t_ok('A1 row persisted, location=Ward', $A1 && $A1['current_location'] === 'Ward');
t_ok('A1 unassigned (consultant_id NULL)', $A1 && $A1['consultant_id'] === null);
t_ok('A1 attribution admitted_by = registrar (session, not spoofed)', $A1 && (int)$A1['admitted_by'] === $registrarId, 'admitted_by=' . ($A1['admitted_by'] ?? 'null'));

$r = admit('admin', '999000002', 'Female', 'ICU', $tbCode ? [$tbCode] : [$normalCode], d(3));
t_ok('A2 admit Female/ICU (TB dx) as admin', admit_ok($r));
$A2 = p_latest('999000002');
t_ok('A2 location=ICU', $A2 && $A2['current_location'] === 'ICU');
t_ok('A2 diagnosis stored as JSON array', $A2 && is_array(json_decode($A2['admissiondiagnosis'], true)));

$r = admit('registrar', '999000003', 'Male', 'ER', [$normalCode], d(2));
t_ok('A3 admit Male/ER', admit_ok($r));

// duplicate active MRN must be rejected
$r = admit('registrar', '999000001', 'Male', 'Ward', [$normalCode], d(1));
t_ok('A4 duplicate active MRN rejected', !e2e_says_success($r['body']) && stripos($r['body'], 'already') !== false, 'body=' . trim(strip_tags($r['body'])));
t_ok('A4 no second active row created', p_count('999000001') === 1);

// server-side validation: absurd age, bad MRN
$r = admit('registrar', '999000050', 'Male', 'Ward', [$normalCode], d(1), ['age' => 999]);
t_ok('A5 invalid age rejected by server validation', !e2e_says_success($r['body']) && p_latest('999000050') === null, 'body=' . trim(strip_tags($r['body'])));
$r = admit('registrar', 'NOT-A-MRN', 'Male', 'Ward', [$normalCode], d(1));
t_ok('A6 non-numeric MRN rejected', !e2e_says_success($r['body']) && p_latest('NOT-A-MRN') === null);

/* ============================================================ B. ASSIGNMENT ============== */
e2e_section('B. Assignment (to-me / to-consultant / capability gate)');

// assign-to-me as consultant (page handler in dmc-new-admissions.php; uses session id)
e2e_post('/dmc-new-admissions.php', ['assign_me_btn' => '1', 'patientid' => $A1['ID']], 'consultant');
$A1 = p_latest('999000001');
t_ok('B1 assign-to-me sets consultant_id to logged-in user', (int)$A1['consultant_id'] === $consultantId, 'consultant_id=' . $A1['consultant_id']);
t_ok('B1 newassign flagged', (int)$A1['newassign'] === 1);

// assign-to-consultant as admin (has assign_access implicitly)
e2e_post('/dmc-new-admissions.php', ['assign_to_consultant_btn' => 'Submit', 'patientid1' => $A2['ID'], 'consultantid' => $consultantId, 'newpt' => '1'], 'admin');
$A2 = p_latest('999000002');
t_ok('B2 assign-to-consultant sets chosen consultant', (int)$A2['consultant_id'] === $consultantId, 'consultant_id=' . $A2['consultant_id']);

// assign-to-consultant as resident (no assign_access) must NOT change the DB (page-handler denial = HTTP 200 but write blocked)
$before = (int)p_latest('999000003')['consultant_id'] ?: 0;
e2e_post('/dmc-new-admissions.php', ['assign_to_consultant_btn' => 'Submit', 'patientid1' => p_latest('999000003')['ID'], 'consultantid' => $consultantId], 'resident');
$after = (int)(p_latest('999000003')['consultant_id'] ?? 0);
t_ok('B3 resident WITHOUT Can-Assign cannot assign (write blocked)', $after === $before, "before=$before after=$after");

/* ============================================================ C. CONSULTATIONS =========== */
e2e_section('C. Consultation lifecycle (add/modify/signoff/delete)');

$r = e2e_post('/consultations/dmc-consultation-add.php', [
    'bed_new' => 'CB1', 'mrn_new' => '999000001', 'pname_new' => 'E2E_TEST 999000001',
    'age_new' => 49, 'current_location_new' => 'Ward', 'consultdate_new' => d(1),
    'other_indication' => 'e2e test', 'consultfrom_new' => 'Hospitalist',
    'indication_new' => ['1'], 'consultant_new' => $consultantId, 'consultation_to_service' => '2',
], 'admin');
t_ok('C1 add consultation', e2e_says_success($r['body']));
$cons = $db->query("SELECT * FROM consultations WHERE MRN LIKE '99900%' ORDER BY id DESC LIMIT 1")->fetch_assoc();
t_ok('C1 consultation row persisted', (bool)$cons);
t_ok('C1 entered_by_id = admin (session attribution)', $cons && (int)$cons['entered_by_id'] === $adminId, 'entered_by=' . ($cons['entered_by_id'] ?? 'null'));
t_ok('C1 signoff_date NULL (active)', $cons && $cons['signoff_date'] === null);

$r = e2e_post('/consultations/dmc-consultation-modify.php', [
    'id' => $cons['id'], 'bed_modify' => 'CB2', 'mrn_modify' => '999000001', 'pname_modify' => 'E2E_TEST 999000001',
    'age_modify' => 50, 'current_location_modify' => 'ICU', 'other_indication_modify' => 'edited',
    'indication_modify' => ['1','2'],
], 'admin');
t_ok('C2 modify consultation', e2e_says_success($r['body']));
$cons2 = $db->query("SELECT * FROM consultations WHERE id=" . (int)$cons['id'])->fetch_assoc();
t_ok('C2 bed updated to CB2', $cons2 && $cons2['BED'] === 'CB2');

// sign-off (page handler in dmc-new-consultation.php; admin allowed by require_consultation_access)
e2e_post('/dmc-new-consultation.php', ['signoff_btn' => '1', 'consultid' => $cons['id']], 'admin');
$cons3 = $db->query("SELECT * FROM consultations WHERE id=" . (int)$cons['id'])->fetch_assoc();
t_ok('C3 sign-off sets signoff_date', $cons3 && $cons3['signoff_date'] !== null, 'signoff_date=' . ($cons3['signoff_date'] ?? 'null'));

$r = e2e_post('/consultations/dmc-consultation-delete.php', ['id' => $cons['id']], 'admin');
t_ok('C4 delete consultation (admin)', e2e_says_success($r['body']));
t_ok('C4 consultation row gone', !$db->query("SELECT 1 FROM consultations WHERE id=" . (int)$cons['id'])->fetch_assoc());

/* ============================================================ D. DISCHARGE flow ========== */
e2e_section('D. Discharge medical -> complete -> reverse (patient A1)');

$dischargeFields = function($id, $type, $disto, $status = 'Alive', $disdate = null) {
    $p = p_by_id($id);
    return [
        'id_modify' => $id, 'bed_modify' => $p['BED'], 'mrn_modify' => $p['MRN'], 'gender_modify' => $p['gender'],
        'pname_modify' => $p['PNAME'], 'age' => $p['age'], 'nationality_modify' => $p['nationality'],
        'admdate' => $p['ADMDATE'], 'admfrom_modify' => $p['ADMFROM'],
        'admissiondiagnosis_modify' => json_decode($p['admissiondiagnosis'], true) ?: [],
        'disdate' => $disdate ?? date('Y-m-d'), 'discahrge_type' => $type, 'disstatus' => $status, 'disto' => $disto,
    ];
};
// D1 medical discharge (still-in): disto carries the delay reason
e2e_post('/patients/dmc-patients-discharge-submit.php', $dischargeFields($A1['ID'], 'medical', 'Awaiting placement'), 'admin');
$A1 = p_by_id($A1['ID']);
t_ok('D1 medical discharge sets med_DISDATE, DISDATE still NULL', $A1['med_DISDATE'] !== null && $A1['DISDATE'] === null);
t_ok('D1 delay reason stored', $A1['delay'] === 'Awaiting placement', 'delay=' . ($A1['delay'] ?? 'null'));

// D2 complete the discharge (close file)
e2e_post('/patients/dmc-patients-complete-discharge-submit.php', ['id_modify' => $A1['ID'], 'disdate' => date('Y-m-d'), 'disstatus' => 'Alive', 'disto' => 'Home'], 'admin');
$A1 = p_by_id($A1['ID']);
t_ok('D2 complete discharge sets DISDATE + trans_discharge', $A1['DISDATE'] !== null && $A1['trans_discharge'] === 'discharge from ward');

// D3 reverse discharge (admin = owner/Can-Manage path)
e2e_post('/dmc-patients.php', ['reverse_discharge_btn' => '1', 'reverse_id' => $A1['ID']], 'admin');
$A1 = p_by_id($A1['ID']);
t_ok('D3 reverse discharge clears DISDATE/med_DISDATE/delay', $A1['DISDATE'] === null && $A1['med_DISDATE'] === null && $A1['delay'] === null);

/* ============================================================ E. one-shot 'both' ======== */
e2e_section("E. One-shot complete discharge + mortality (patient A3)");
$A3 = p_latest('999000003');
e2e_post('/patients/dmc-patients-discharge-submit.php', $dischargeFields($A3['ID'], 'both', 'Home', 'Dead'), 'admin');
$A3 = p_by_id($A3['ID']);
t_ok('E1 both-discharge sets DISDATE & med_DISDATE', $A3['DISDATE'] !== null && $A3['med_DISDATE'] !== null);
t_ok('E1 mortality recorded = Dead', $A3['MORTALITY'] === 'Dead');
t_ok('E1 trans_discharge = discharge from ward', $A3['trans_discharge'] === 'discharge from ward');

/* ============================================================ F. ICU discharge =========== */
e2e_section('F. ICU discharge (patient A2)');
e2e_post('/patients/dmc-patients-icu-discharge-submit.php', $dischargeFields($A2['ID'], 'icu', 'Home', 'Alive'), 'admin');
$A2 = p_by_id($A2['ID']);
t_ok('F1 ICU discharge sets trans_discharge = discharge from ICU', $A2['trans_discharge'] === 'discharge from ICU' && $A2['DISDATE'] !== null);

/* ============================================================ G. Transfers =============== */
e2e_section('G. Transfers (to-specialty / to-ICU / ICU-back-to-ward)');

// G1 ward patient transferred to another speciality (new row under chosen consultant)
admit('admin', '999000004', 'Male', 'Ward', [$normalCode], d(4));
$g1 = p_latest('999000004');
$specRow = $db->query("SELECT id, specilaity FROM speciality WHERE id <> 1 LIMIT 1")->fetch_assoc();
e2e_post('/dmc-patients.php', ['transfer_pt_btn' => '1', 'id' => $g1['ID'], 'specialty_transfer' => $specRow['id'], 'constulant_transfer' => $consultantId], 'admin');
$g1old = p_by_id($g1['ID']);
t_ok('G1 old row discharged as transfer to other speciality', $g1old['trans_discharge'] === 'transfer to other speciality' && $g1old['DISDATE'] !== null);
t_ok('G1 new episode created for same MRN', p_count('999000004') === 2);
$g1new = p_latest('999000004');
t_ok('G1 new episode = Ward under chosen consultant', $g1new['current_location'] === 'Ward' && (int)$g1new['consultant_id'] === $consultantId);

// G2 ward patient transferred to ICU (other_specialities path)
admit('admin', '999000005', 'Female', 'Ward', [$normalCode], d(4));
$g2 = p_latest('999000005');
e2e_post('/dmc-patients.php', ['transfer_pt_btn' => '1', 'id' => $g2['ID'], 'specialty_transfer' => 'Intensive Care (ICU)'], 'admin');
$g2old = p_by_id($g2['ID']);
t_ok('G2 old row = other transfer, DISTO=ICU', $g2old['trans_discharge'] === 'other transfer' && $g2old['DISTO'] === 'Intensive Care (ICU)');
t_ok('G2 new ICU episode created', ($g2new = p_latest('999000005')) && $g2new['current_location'] === 'ICU' && p_count('999000005') === 2);

// G3 ICU patient transferred back to ward (newpatients/dmc-patients-icu-transfer.php)
admit('admin', '999000006', 'Male', 'ICU', [$normalCode], d(3));
$g3 = p_latest('999000006');
$r = e2e_post('/newpatients/dmc-patients-icu-transfer.php', ['patientid' => $g3['ID']], 'admin');
t_ok('G3 icu-transfer reports success', e2e_says_success($r['body']), 'body=' . trim($r['body']));
$g3old = p_by_id($g3['ID']);
t_ok('G3 old ICU row = Transfer from ICU', $g3old['trans_discharge'] === 'Transfer from ICU' && $g3old['DISDATE'] !== null);
t_ok('G3 new ward episode (ADMFROM=ICU)', ($g3new = p_latest('999000006')) && $g3new['current_location'] === 'Ward' && $g3new['ADMFROM'] === 'ICU' && p_count('999000006') === 2);

/* ============================================================ H. Readmission (<72h) ====== */
e2e_section('H. 72h readmission detection');
admit('admin', '999000007', 'Male', 'Ward', [$normalCode], d(10));
$h0 = p_latest('999000007');
e2e_post('/patients/dmc-patients-complete-discharge-submit.php', ['id_modify' => $h0['ID'], 'disdate' => d(8), 'disstatus' => 'Alive', 'disto' => 'Home'], 'admin');
admit('admin', '999000007', 'Male', 'Ward', [$normalCode], d(7)); // new episode within 3 days of prior discharge
$h1 = p_latest('999000007');
$isReadmit = (int)$db->query("SELECT COUNT(*) c FROM picupatients p WHERE p.ID=" . (int)$h1['ID'] . " AND EXISTS(
    SELECT 1 FROM picupatients q WHERE q.DISDATE + INTERVAL 3 DAY >= p.ADMDATE AND q.ID < p.ID AND q.MRN = p.MRN
    AND (q.trans_discharge='discharge from ICU' OR q.trans_discharge='discharge from ward' OR q.trans_discharge IS NULL))")->fetch_assoc()['c'];
t_ok('H1 new episode flagged as 72h readmission', $isReadmit === 1);
// confirm it renders on the registry search with readmission filter
$r = e2e_post('/registry/search-results.php', ['mrn' => '999000007', 'readmission' => 'readmission'], 'admin');
t_ok('H1 registry readmission search renders the readmission card', stripos($r['body'], 'Readmission in 72 hours') !== false);

/* ============================================================ I. Long-term ================ */
e2e_section('I. Long-term flag');
admit('admin', '999000009', 'Female', 'Ward', [$normalCode], d(20));
$lt = p_latest('999000009');
// longterm.php groups patients UNDER their position-3 consultant, so assign one first (otherwise an
// unassigned long-term patient is silently omitted from that page — noted as a minor design nuance).
e2e_post('/dmc-new-admissions.php', ['assign_me_btn' => '1', 'patientid' => $lt['ID']], 'consultant'); // e2e_consultant is position 3
e2e_post('/patients/dmc-patients-update.php', [
    'id' => $lt['ID'], 'bed' => $lt['BED'], 'mrn' => $lt['MRN'], 'name' => $lt['PNAME'],
    'longterm' => 'longterm', 'admdate' => $lt['ADMDATE'],
    'admissiondiagnosis' => json_decode($lt['admissiondiagnosis'], true) ?: [], 'attribChanged' => 'longterm',
], 'admin');
$lt = p_by_id($lt['ID']);
t_ok('I1 long-term flag set', $lt['longterm'] === 'longterm');
t_ok('I1 assigned to a position-3 consultant', (int)$lt['consultant_id'] === $consultantId);
$r = e2e_get('/longterm.php', 'admin');
t_ok('I1 patient appears on long-term page (grouped under its consultant)', strpos($r['body'], '999000009') !== false, 'longterm.php bytes=' . strlen($r['body']));

/* ============================================================ J. Delete endpoint ========= */
e2e_section('J. Delete endpoint (admin-only)');
// resident denied at the action endpoint => real 403
$r = e2e_post('/patients/dmc-patient-delete.php', ['id' => $h1['ID']], 'resident');
t_ok('J1 resident DELETE -> 403 (action endpoint)', $r['code'] === 403, 'code=' . $r['code']);
t_ok('J1 row still present after denied delete', p_by_id($h1['ID']) !== null);
// admin allowed
$r = e2e_post('/patients/dmc-patient-delete.php', ['id' => $h1['ID']], 'admin');
t_ok('J2 admin DELETE succeeds', e2e_says_success($r['body']));
t_ok('J2 row removed', p_by_id($h1['ID']) === null);

/* ============================================================ cleanup ===================== */
e2e_section('Cleanup (remove all 99900-prefixed test rows)');
$db->query("DELETE FROM picupatients WHERE MRN LIKE '99900%'");
$db->query("DELETE FROM consultations WHERE MRN LIKE '99900%'");
$leftP = (int)$db->query("SELECT COUNT(*) c FROM picupatients WHERE MRN LIKE '99900%'")->fetch_assoc()['c'];
$leftC = (int)$db->query("SELECT COUNT(*) c FROM consultations WHERE MRN LIKE '99900%'")->fetch_assoc()['c'];
t_ok('cleanup removed all test patients', $leftP === 0, "left=$leftP");
t_ok('cleanup removed all test consultations', $leftC === 0, "left=$leftC");

exit(e2e_summary());
