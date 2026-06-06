<?php
require_once __DIR__ . '/guard.php'; require_role([0]);
 
require ('dbconnect.php');
$id = $_REQUEST['id'];
$position = $_REQUEST['position']; 
$activate = $_REQUEST['activate'];
$service = $_REQUEST['service'];
$full_name = $_REQUEST['full_name'];
$speciality = $_REQUEST['speciality'];
$accessbox = $_REQUEST['accessbox'];
$addbox = $_REQUEST['addbox'];
$managebox = $_REQUEST['managebox'];
$modifybox = $_REQUEST['modifybox'];
 $sql = "UPDATE members SET position='".$position."', full_name='".$full_name."', specialty_id='".$speciality."', active='".$activate."',
  on_service='".$service."', assign_access='".$accessbox."' , add_new_patient='".$addbox."', manage_patient='".$managebox."'
  , modify_patient='".$modifybox."'  WHERE member_id='".$id."'";
  
                if ($mysqli->query($sql) === TRUE) {
                  $message= "Record updated successfully";
                } else {
                 $message= "Error updating record: " . $mysqli->error;
                }

echo "
$message
";


              

?>