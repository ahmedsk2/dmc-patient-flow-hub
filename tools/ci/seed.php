<?php
/**
 * tools/ci/seed.php — deterministic, SYNTHETIC (no-PHI) fixture for CI.
 *
 * Populates a freshly-loaded schema (tools/ci/schema.sql + migrations 10/11) with just enough
 * reference + clinical data for the full test suite to run: reference tables, a few members
 * (incl. on-service position-3 consultants), ~30 admissions across 2024 (discharges, ICU, mortality,
 * a 72h-readmission pair) and ~12 consultations (with/without sign-off). All values are invented —
 * no real patient data. Re-runnable (truncates first). Reads DB creds from config.local.php.
 *
 * After this, CI re-runs migrations 07 + 09 to backfill the derived patient_diagnosis / patients tables.
 */
$cfg = dirname(__DIR__, 2) . '/config.local.php';
if (is_file($cfg)) require_once $cfg;
// DMC_DB_* env overrides win (CI sets these; also lets a local scratch DB be seeded without touching config).
$H = getenv('DMC_DB_HOST') ?: (defined('DB_HOST') ? DB_HOST : '127.0.0.1');
$U = getenv('DMC_DB_USER') ?: (defined('DB_USER') ? DB_USER : 'root');
$P = getenv('DMC_DB_PASS'); if ($P === false) { $P = defined('DB_PASS') ? DB_PASS : ''; }
$N = getenv('DMC_DB_NAME') ?: (defined('DB_NAME') ? DB_NAME : 'dmc_ci');
$db = new mysqli($H, $U, $P, $N);
if ($db->connect_errno) { fwrite(STDERR, "connect: {$db->connect_error}\n"); exit(2); }
// Preserve explicit id=0 rows the app relies on (settings id=0, consultation_reason 'other'=0) —
// otherwise an AUTO_INCREMENT column turns 0 into the next value.
$db->query("SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'");
$db->query("SET FOREIGN_KEY_CHECKS=0");
foreach (['picupatients','picupatients_temp','consultations','members','icd10','tb_list','speciality',
          'other_specialities','consultation_reason','countries','settings','position',
          'patient_diagnosis','patients'] as $t) { $db->query("TRUNCATE TABLE `$t`"); }
$db->query("SET FOREIGN_KEY_CHECKS=1");
$q = function ($sql) use ($db) { if (!$db->query($sql)) { fwrite(STDERR, "SQL ERR: {$db->error}\n  $sql\n"); exit(3); } };

// reference data
$q("INSERT INTO settings (id,min_hospitalist,max_hospitalist,min_subs,max_subs,short_los,long_los,mfa_enforcement)
    VALUES (0,6,30,7,7,5,11,0)");
$q("INSERT INTO position (id,position) VALUES (2,'Registrar'),(3,'Consultant'),(4,'Resident'),(5,'Observer')");
$q("INSERT INTO speciality (id,specilaity) VALUES (1,'Hospitalist'),(2,'Infectious Disease'),(3,'Pulmonary')");
$q("INSERT INTO other_specialities (id,specilaity) VALUES (1,'Intensive Care (ICU)'),(2,'Surgery'),(3,'Cardiology')");
$q("INSERT INTO consultation_reason (id,consultation_reason) VALUES (0,'other'),(1,'Blood Pressure Control'),(2,'Blood Sugar Control')");
$q("INSERT INTO countries (id,code,name) VALUES (1,'SA','Saudi Arabia'),(2,'YE','Yemen'),(3,'EG','Egypt')");
// icd10: a TB code (A150, in tb_list) + non-TB codes
$q("INSERT INTO icd10 (id,name,autoid) VALUES ('A150','Tuberculosis of lung',1),('I10','Essential hypertension',2),('E11','Type 2 diabetes mellitus',3),('J18','Pneumonia',4),('N39','Urinary tract infection',5)");
$q("INSERT INTO tb_list (id,dx_id) VALUES (1,'A150')");

// members: 3 on-service position-3 consultants (specialty 1,1,2), 1 registrar, 1 resident, 1 observer
$hash = password_hash('CiSeed!123', PASSWORD_DEFAULT);
$mem = [
    [1,'ci_consult_a',0,3,1,1,1,'Dr CI Alpha'],
    [2,'ci_consult_b',0,3,1,1,1,'Dr CI Bravo'],
    [3,'ci_consult_c',0,3,1,2,1,'Dr CI Charlie'],
    [4,'ci_registrar',1,2,1,0,0,'CI Registrar'],
    [5,'ci_resident', 1,4,0,0,0,'CI Resident'],
    [6,'ci_observer', 1,5,0,0,0,'CI Observer'],
];
$st = $db->prepare("INSERT INTO members (member_id,member_name,member_password,member_email,position,active,on_service,specialty_id,full_name,assign_access,add_new_patient,manage_patient,modify_patient,pass_exp_date)
    VALUES (?,?,?,?,?,1,?,?,?,1,1,1,1,CURDATE())");
foreach ($mem as [$id,$name,$_z,$pos,$onsvc,$spec,$_a,$full]) {
    $email = $name . '@ci.test';
    $st->bind_param('isssiiis', $id, $name, $hash, $email, $pos, $onsvc, $spec, $full);
    $st->execute();
}
// an admin matching report_data_validate.php's default HTTP login (localtest / LocalTest123!),
// so the full tests/run.php is green in CI without extra env. (setup_accounts still adds the e2e_* roles.)
$ltHash = password_hash('LocalTest123!', PASSWORD_DEFAULT);
$lt = $db->prepare("INSERT INTO members (member_id,member_name,member_password,member_email,position,active,on_service,specialty_id,full_name,assign_access,add_new_patient,manage_patient,modify_patient,pass_exp_date)
    VALUES (10,'localtest',?,'localtest@ci.test',0,1,1,1,'CI Local Admin',1,1,1,1,CURDATE())");
$lt->bind_param('s', $ltHash);
$lt->execute();

// picupatients: deterministic spread across 2024. Columns kept minimal-but-valid.
$ins = $db->prepare("INSERT INTO picupatients
    (MRN,PNAME,ADMDATE,DISDATE,med_DISDATE,ADMFROM,DISTO,MORTALITY,admissiondiagnosis,BED,nationality,gender,age,consultant_id,admitted_by,current_location,trans_discharge,longterm)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$codes = ['["I10"]','["E11"]','["J18"]','["A150"]','["N39"]','["I10","E11"]'];
$genders = ['Male','Female'];
$rowN = 0;
$add = function($mrn,$adm,$dis,$med,$disto,$mort,$dx,$loc,$trans,$lt,$cons) use (&$ins,&$rowN,$genders) {
    $name = 'CI Patient ' . $mrn;
    $from = 'ER'; $bed = 'B' . (($rowN % 20) + 1); $nat = 'Saudi Arabia';
    $g = $genders[$rowN % 2]; $age = 30 + ($rowN % 50); $adminBy = 4;
    $ins->bind_param('sssssssssssssiisss', $mrn,$name,$adm,$dis,$med,$from,$disto,$mort,$dx,$bed,$nat,$g,$age,$cons,$adminBy,$loc,$trans,$lt);
    $ins->execute(); $rowN++;
};
// 24 ward admissions Jan-Dec 2024 (2/month), about half discharged
for ($m = 1; $m <= 12; $m++) {
    $adm1 = sprintf('2024-%02d-05', $m);
    $dis1 = sprintf('2024-%02d-12', $m);
    $add(1000 + $m, $adm1, $dis1, $dis1, 'Home', 'Alive', $codes[$m % 6], 'Ward', 'discharge from ward', null, (($m % 3) + 1));
    $adm2 = sprintf('2024-%02d-18', $m);
    // second one stays active (no discharge) for odd months, discharged for even
    if ($m % 2 === 0) {
        $dis2 = sprintf('2024-%02d-25', $m);
        $add(1100 + $m, $adm2, $dis2, $dis2, 'Home', 'Alive', $codes[($m + 1) % 6], 'Ward', 'discharge from ward', null, (($m % 3) + 1));
    } else {
        $add(1100 + $m, $adm2, null, null, null, 'Alive', $codes[($m + 1) % 6], 'Ward', null, null, (($m % 3) + 1));
    }
}
// 4 ICU admissions (2 deaths) across the year
$add(2001, '2024-03-03', '2024-03-10', '2024-03-10', 'Home', 'Dead',  '["J18"]', 'ICU', 'discharge from ICU', null, 1);
$add(2002, '2024-06-07', '2024-06-15', '2024-06-15', 'Home', 'Alive', '["I10"]', 'ICU', 'discharge from ICU', null, 2);
$add(2003, '2024-09-09', '2024-09-20', '2024-09-20', 'Home', 'Dead',  '["N39"]', 'ICU', 'discharge from ICU', null, 1);
$add(2004, '2024-11-02', null, null, null, 'Alive', '["A150"]', 'ICU', null, null, 3);
// a 72h readmission pair (same MRN): discharged 2024-04-10, re-admitted 2024-04-12 (within 3 days)
$add(3001, '2024-04-01', '2024-04-10', '2024-04-10', 'Home', 'Alive', '["I10"]', 'Ward', 'discharge from ward', null, 1);
$add(3001, '2024-04-12', '2024-04-20', '2024-04-20', 'Home', 'Alive', '["I10"]', 'Ward', 'discharge from ward', null, 1);
// a long-term active patient
$add(4001, '2024-02-01', null, null, null, 'Alive', '["E11"]', 'Ward', null, 'longterm', 2);
// a few CURRENT-YEAR rows so current-year-defaulting pages (a4 ?y=thisYear, dashboard YTD) have data
$ty = date('Y');
$add(5001, "$ty-01-10", "$ty-01-15", "$ty-01-15", 'Home', 'Alive', '["I10"]', 'Ward', 'discharge from ward', null, 1);
$add(5002, "$ty-02-05", null,        null,        null,   'Alive', '["E11"]', 'Ward', null,                  null, 2);
$add(5003, "$ty-03-08", "$ty-03-12", "$ty-03-12", 'Home', 'Dead',  '["J18"]', 'ICU',  'discharge from ICU',  null, 1);

// consultations: ~12, consultant_id in {1,2,3}, with/without sign-off, across 2024
$cins = $db->prepare("INSERT INTO consultations (MRN,PNAME,age,BED,consultation_date,consultation_from,current_location,entered_by_id,indication,consultant_id,signoff_date,other_ind,consultation_to_service)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
$consData = [
    // mrn, condate, signoff(or null), consultant
    [1001,'2024-01-06', '2024-01-08', 1], [1102,'2024-02-19', null, 2], [2001,'2024-03-04','2024-03-05',1],
    [3001,'2024-04-02', '2024-04-03', 1], [1005,'2024-05-06', '2024-05-09', 2], [2002,'2024-06-08', null, 3],
    [1107,'2024-07-19', '2024-07-22', 1], [1008,'2024-08-06','2024-08-07', 2], [2003,'2024-09-10','2024-09-12',1],
    [1010,'2024-10-06', null, 3], [1111,'2024-11-19','2024-11-21', 2], [4001,'2024-12-03','2024-12-05', 1],
    // current-year consultations so current-year-defaulting views have data
    [5001, date('Y').'-01-11', date('Y').'-01-13', 1], [5002, date('Y').'-02-06', null, 2],
];
foreach ($consData as $i => [$mrn,$cd,$so,$cons]) {
    $pname = 'CI Patient ' . $mrn; $age = 40 + $i; $bed = 'B' . (($i % 10) + 1);
    $from = 'Hospitalist'; $loc = 'Ward'; $entered = 4; $ind = '["1"]'; $oth = 'ci'; $toSvc = 2;
    $mrnS = (string) $mrn;
    // 13 cols: MRN(s) PNAME(s) age(i) BED(s) condate(s) from(s) loc(s) entered(i) indication(s,JSON) consultant(i) signoff(s) other(s) toSvc(s)
    $cins->bind_param('ssissssisisss', $mrnS,$pname,$age,$bed,$cd,$from,$loc,$entered,$ind,$cons,$so,$oth,$toSvc);
    $cins->execute();
}

echo "seeded: members=" . $db->query("SELECT COUNT(*) c FROM members")->fetch_assoc()['c']
   . " picupatients=" . $db->query("SELECT COUNT(*) c FROM picupatients")->fetch_assoc()['c']
   . " consultations=" . $db->query("SELECT COUNT(*) c FROM consultations")->fetch_assoc()['c']
   . " icd10=" . $db->query("SELECT COUNT(*) c FROM icd10")->fetch_assoc()['c'] . "\n";
