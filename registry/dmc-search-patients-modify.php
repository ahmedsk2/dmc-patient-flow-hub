<?php
require_once __DIR__ . '/../guard.php'; require_role([0]);
 
require ('../dbconnect.php');
$id = $_REQUEST['id_modify']; 
   $bed_modify = $_REQUEST['bed_modify']; 
   $mrn_modify = trim($_REQUEST['mrn_modify']); 
   $gender_modify = $_REQUEST['gender_modify']; 
   $pname_modify = $_REQUEST['pname_modify']; 
   $age_modify = $_REQUEST['age_modify']; 
   $nationality_modify=$_REQUEST['nationality_modify']; 
   
   $admissiondiagnosis_modify1 = $_REQUEST['admissiondiagnosis_modify']; 
   $admissiondiagnosis = json_encode($admissiondiagnosis_modify1); 
   
   
  
   
    $sql = "UPDATE  picupatients SET  MRN=?,BED=?, PNAME=?,gender=?,age=?,
    nationality=?, admissiondiagnosis=? WHERE ID=?";



//  $sql = "INSERT INTO picupatients (BED) VALUES ('$bed')";


                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param('ssssissi', $mrn_modify, $bed_modify, $pname_modify, $gender_modify, $age_modify, $nationality_modify, $admissiondiagnosis, $id);
                if ($stmt->execute() === TRUE) {
                  $message= "Record added successfully";
                  // $last_id = mysqli_insert_id($mysqli);
                } else {
                 $message= "Error adding record: " . $mysqli->error;
                }

                // echo  $message;
echo "<a>".$message."</a>";


              

?>
