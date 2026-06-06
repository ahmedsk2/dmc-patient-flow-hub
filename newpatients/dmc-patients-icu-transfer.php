<?php
require_once __DIR__ . '/../guard.php'; require_login();
csrf_verify();
 
require_once ('../dbconnect.php');
$pid = $_REQUEST['patientid']; 
$userid =$id = $_REQUEST['userid']; 
date_default_timezone_set('Asia/Riyadh');
$today=date("Y-m-d");

$formationSQL = "SELECT * FROM picupatients WHERE ID=?";
$stmt = $mysqli->prepare($formationSQL);
$stmt->bind_param("i", $pid);
$stmt->execute();
$result1 = $stmt->get_result();
$patient = $result1 -> fetch_array(MYSQLI_ASSOC);


$patient['consultant_id']= null;
$patient['newassign']=null;
$patient['assigned_on']=null;
$patient['ADMDATE']=$today;
$patient['admitted_by']=$userid;
$patient['current_location']='Ward';
/// transfer to new doctor

 $query = "INSERT INTO picupatients (MRN, PNAME, ADMDATE, ADMFROM, admissiondiagnosis,  nationality, gender,  age, admitted_by, current_location)
  VALUES (?,?,?,'ICU',?,?,?,?,?,?) ";

//   mysqli_query($mysqli, $query);

 $ok = true;
 $mysqli->begin_transaction(); // R2: the new ICU record + the old-record discharge must commit together

 $stmt = $mysqli->prepare($query);
 $stmt->bind_param("sssssisss", $patient['MRN'], $patient['PNAME'], $patient['ADMDATE'], $patient['admissiondiagnosis'], $patient['nationality'], $patient['gender'], $patient['age'], $patient['admitted_by'], $patient['current_location']);
 if (!$stmt -> execute()) {
   error_log("icu-transfer INSERT failed (pid $pid): " . $mysqli->error);
   $ok = false;
 }
 $query = "UPDATE  picupatients SET  DISDATE=?,med_DISDATE=?,DISTO='Ward', MORTALITY='Alive', trans_discharge='Transfer from ICU', trans_discharge_by=?  WHERE ID=?";
 $stmt = $mysqli->prepare($query);
 $stmt->bind_param("sssi", $today, $today, $patient['admitted_by'], $pid);
 if (!$stmt -> execute()) {
   error_log("icu-transfer UPDATE failed (pid $pid): " . $mysqli->error);
   $ok = false;
 }

 if ($ok) { $mysqli->commit(); audit_log('patient.icu_transfer','picupatients',$pid); } else { $mysqli->rollback(); }

// Machine-detectable status so the caller only reloads on confirmed success (W1).
echo $ok ? "Record transferred successfully" : "Error transferring record";
?>
