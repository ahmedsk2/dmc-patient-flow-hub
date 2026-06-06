<?php 
require ('dbconnect.php');
$id = $_REQUEST['id'];

 $sql = "DELETE FROM members WHERE member_id='".$id."'";

  
                if ($mysqli->query($sql) === TRUE) {
                  $message= "Record delete successfully";
                } else {
                 $message= "Error deleting record: " . $mysqli->error;
                }




?>