<?php
require_once __DIR__ . '/guard.php'; require_role([0]);
csrf_verify();
 
require ('dbconnect.php');
$id = $_REQUEST['id'];

 $sql = "DELETE FROM members WHERE member_id='".$id."'";

  
                if ($mysqli->query($sql) === TRUE) {
                  $message= "Record delete successfully";
                  audit_log('member.delete','members',$id);
                } else {
                 $message= "Error deleting record: " . $mysqli->error;
                }




?>
