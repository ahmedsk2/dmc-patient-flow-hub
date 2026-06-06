<?php
require_once __DIR__ . '/../guard.php'; require_login();
csrf_verify();
 
require_once ('../dbconnect.php');
$id = $_REQUEST['id_modify']; 
   $bed_modify = $_REQUEST['bed_modify']; 
   $mrn_modify = trim($_REQUEST['mrn_modify']); 
   $gender_modify = $_REQUEST['gender_modify']; 
   $pname_modify = $_REQUEST['pname_modify']; 
   $primary_modify = $_REQUEST['primary_modify'];
   $age_modify = $_REQUEST['age_modify']; 
   $nationality_modify=$_REQUEST['nationality_modify']; 
   $current_location_modify=$_REQUEST['current_location_modify']; 

     $admdate1_new=$_REQUEST['admdate_modify'];  
     if (!empty($admdate1_new)){
       $admdate=date("Y-m-d",strtotime($admdate1_new));
       }else{
         $admdate=null;
       }
   $admfrom_modify = $_REQUEST['admfrom_modify']; 
   
   $admissiondiagnosis_modify1 = $_REQUEST['admissiondiagnosis_modify']; 
   $admissiondiagnosis = json_encode($admissiondiagnosis_modify1); 
   
   
  
   
    $sql = "UPDATE  picupatients SET  MRN=?,BED=?, PNAME=?,gender=?,age=?,
    ADMFROM=?,ADMDATE=?,nationality=?
    , admissiondiagnosis=? , consultant_id=?, current_location=? WHERE ID=?";



//  $sql = "INSERT INTO picupatients (BED) VALUES ('$bed')";


                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("ssssissssisi", $mrn_modify, $bed_modify, $pname_modify, $gender_modify, $age_modify, $admfrom_modify, $admdate, $nationality_modify, $admissiondiagnosis, $primary_modify, $current_location_modify, $id);
                if ($stmt->execute() === TRUE) {
                  $message= "Record added successfully";
                  // $last_id = mysqli_insert_id($mysqli);
                } else {
                 $message= "Error adding record: " . $mysqli->error;
                }

                // echo  $message;
echo "<a>".$nationality_modify."<br>".$message."</a>";


              

?>
