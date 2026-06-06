<?php 
require_once ('../dbconnect.php');
$pid = $_REQUEST['patientid']; 
$userid =$id = $_REQUEST['userid']; 
date_default_timezone_set('Asia/Riyadh');
$today=date("Y-m-d");

$formationSQL = "SELECT * FROM picupatients WHERE ID='".$pid."'";
$result1 = $mysqli->query($formationSQL);
$patient = $result1 -> fetch_array(MYSQLI_ASSOC);

 var_dump($patient );

$patient['consultant_id']= null;
$patient['newassign']=null;
$patient['assigned_on']=null;
$patient['ADMDATE']=$today;
$patient['admitted_by']=$userid;
$patient['current_location']='Ward';
/// transfer to new doctor

 $query = "INSERT INTO picupatients (MRN, PNAME, ADMDATE, ADMFROM, admissiondiagnosis,  nationality, gender,  age, admitted_by, current_location)
  VALUES ('".$patient['MRN']."','".$patient['PNAME']."','".$patient['ADMDATE']."','ICU','".$patient['admissiondiagnosis']."','".$patient['nationality']."','".$patient['gender']."','".$patient['age']."','".$patient['admitted_by']."','".$patient['current_location']."') ";

//   mysqli_query($mysqli, $query);

 if (!$mysqli -> query( $query)) {
   echo("Error description: " . $mysqli -> error);

  


 }
 $query = "UPDATE  picupatients SET  DISDATE='".$today."',med_DISDATE='".$today."',DISTO='Ward', MORTALITY='Alive', trans_discharge='Transfer from ICU', trans_discharge_by='".$patient['admitted_by']."'  WHERE ID='".$pid."'";
 if (!$mysqli -> query( $query)) {
   echo("Error description: " . $mysqli -> error);
 }


?>
