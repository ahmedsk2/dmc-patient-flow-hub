<?php 
require_once ('../dbconnect.php');
$id = $_REQUEST['id'];

 $sql = "DELETE FROM picupatients WHERE ID='".$id."'";

  
                if ($mysqli->query($sql) === TRUE) {
                  $message= "Record delete successfully";
                } else {
                 $message= "Error deleting record: " . $mysqli->error;
                }




?>