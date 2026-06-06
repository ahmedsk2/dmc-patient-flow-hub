<?php
require_once __DIR__ . '/../guard.php'; require_login();
 
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
  


 $sql = "UPDATE picupatients SET current_location=?,BED=?,MRN=?, PNAME=?,  ADMFROM=?,age=?,gender=?,nationality=?,ADMFROM=?,
 ADMDATE=?
 , admissiondiagnosis=? WHERE ID=?";

                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("sssssisssssi", $current_location, $bed, $mrn, $name, $admfrom, $age, $gender, $nationality, $admfrom, $admdate, $admissiondiagnosis, $id);
                if ($stmt->execute() === TRUE) {
                  $message= "Record updated successfully";
                } else {
                 $message= "Error updating record: " . $mysqli->error;
                }

echo 
$message . $age
;


              

?>