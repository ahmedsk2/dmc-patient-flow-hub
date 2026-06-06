<?php 
require_once ('../dbconnect.php');
$id = $_REQUEST['id1'];
$bed = $_REQUEST['bed']; 
$mrn = trim($_REQUEST['mrn']); 
$name = $_REQUEST['name'];
$age = $_REQUEST['age'];
$gender = $_REQUEST['gender'];
$current_location = $_REQUEST['current_location'];
$nationality = $_REQUEST['nationality'];
$admfrom = $_REQUEST['admfrom'];
// $admfrom = $_REQUEST['admfrom']; 

$admdate1 = $_REQUEST['admdate']; 
if (!empty($admdate1)){
  $admdate=date("Y-m-d",strtotime($admdate1));
  }else{
    $admdate=null;
  }

  $admissiondiagnosis1 = $_REQUEST['admissiondiagnosis']; 
  $admissiondiagnosis = json_encode($admissiondiagnosis1); 
  


 $sql = "UPDATE picupatients SET current_location='".$current_location."',BED='".$bed."',MRN='".$mrn."', PNAME='".$name."',  ADMFROM='".$admfrom."',age='".$age."',gender='".$gender."',nationality='".$nationality."',ADMFROM='".$admfrom."',
 ADMDATE=" . ($admdate==NULL ? "NULL" : "'".$admdate."'") . "
 , admissiondiagnosis='".$admissiondiagnosis."' WHERE ID='".$id."'";
  
                if ($mysqli->query($sql) === TRUE) {
                  $message= "Record updated successfully";
                } else {
                 $message= "Error updating record: " . $mysqli->error;
                }

echo 
$message . $age
;


              

?>