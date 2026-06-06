<?php
require_once __DIR__ . '/../guard.php'; require_login();
csrf_verify();
 
require_once ('../dbconnect.php');
$id = $_REQUEST['id'];
$bed = $_REQUEST['bed']; 
$mrn = trim($_REQUEST['mrn']); 
$name = $_REQUEST['name'];
// $admfrom = $_REQUEST['admfrom']; 
$longterm = $_REQUEST['longterm'];

$admdate1 = $_REQUEST['admdate']; 
if (!empty($admdate1)){
  $admdate=date("Y-m-d",strtotime($admdate1));
  }else{
    $admdate=null;
  }

  $admissiondiagnosis1 = $_REQUEST['admissiondiagnosis'];
  $admissiondiagnosis = json_encode($admissiondiagnosis1);

// W3: validate only the field that actually changed, so an unrelated inline edit is never
// blocked by already-stored data in the other (unchanged) fields.
$attrib = $_REQUEST['attribChanged'] ?? '';
$verr = '';
if ($attrib === 'mrn')         { $verr = v_len($mrn, 'MRN', 50); }
elseif ($attrib === 'name')    { $verr = v_len($name, 'Patient name', 100); }
elseif ($attrib === 'admdate') { $verr = v_date_ymd($admdate, 'Admission date'); }
if ($verr !== '') { echo "Error: " . $verr; exit; }


 $sql = "UPDATE picupatients SET BED=?,MRN=?, PNAME=?,
 ADMDATE=?, longterm=?
 , admissiondiagnosis=? WHERE ID=?";

                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("ssssssi", $bed, $mrn, $name, $admdate, $longterm, $admissiondiagnosis, $id);
                if ($stmt->execute() === TRUE) {
                  $message= "Record updated successfully";
                } else {
                 error_log(__FILE__ . ": update " . $mysqli->error); $message= "Error updating record.";
                }

echo "
$message
";


              

?>
