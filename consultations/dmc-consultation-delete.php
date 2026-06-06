<?php
require_once __DIR__ . '/../guard.php'; require_role([0]);
 
require_once ('../dbconnect.php');
$id = $_REQUEST['id'];

 $sql = "DELETE FROM consultations WHERE id=?";


                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("i", $id);
                if ($stmt->execute() === TRUE) {
                  $message= "Record delete successfully";
                } else {
                 $message= "Error deleting record: " . $mysqli->error;
                }




?>