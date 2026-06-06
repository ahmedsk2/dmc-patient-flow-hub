<?php 
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
  


 $sql = "UPDATE picupatients SET BED='".$bed."',MRN='".$mrn."', PNAME='".$name."',
 ADMDATE=" . ($admdate==NULL ? "NULL" : "'".$admdate."'") . ", longterm='".$longterm."'
 , admissiondiagnosis='".$admissiondiagnosis."' WHERE ID='".$id."'";
  
                if ($mysqli->query($sql) === TRUE) {
                  $message= "Record updated successfully";
                } else {
                 $message= "Error updating record: " . $mysqli->error;
                }

echo "
$message
";


              

?>