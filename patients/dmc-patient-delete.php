<?php
require_once __DIR__ . '/../guard.php'; require_role([0]);
csrf_verify();
 
require_once ('../dbconnect.php');
$id = $_REQUEST['id'];

 $sql = "DELETE FROM picupatients WHERE ID=?";


                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("i", $id);
                if ($stmt->execute() === TRUE) {
                  $message= "Record deleted successfully";
                  audit_log('patient.delete','picupatients',$id);
                } else {
                 error_log("patient.delete failed (ID $id): " . $mysqli->error);
                 $message= "Error deleting record";
                }

// Echo a machine-detectable status so the client only removes the row on confirmed success (W1).
echo $message;
?>
