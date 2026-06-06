<?php
require_once __DIR__ . '/../guard.php'; require_login();
csrf_verify();
 
require_once ('../dbconnect.php');
   $bed_new = $_REQUEST['bed_new']; 
   $mrn_new = trim($_REQUEST['mrn_new']);
   $gender_new = $_REQUEST['gender_new']; 
   $pname_new = $_REQUEST['pname_new']; 
   $nationality_new=$_REQUEST['nationality_new']; 
   $age_new = $_REQUEST['age_new']; 
   $current_location = $_REQUEST['current_location_new'];
   $admitted_by=$_REQUEST['admitted_by'];
     $admdate1_new=$_REQUEST['admdate_new'];  
     if (!empty($admdate1_new)){
       $admdate=date("Y-m-d",strtotime($admdate1_new));
       }else{
         $admdate=null;
       }


   $admfrom_new = $_REQUEST['admfrom_new']; 

   $admissiondiagnosis_new1 = $_REQUEST['admissiondiagnosis_new']; 
   $admissiondiagnosis = json_encode($admissiondiagnosis_new1); 
   
   $patient_check_query = "SELECT * FROM picupatients WHERE MRN=? AND DISDATE IS NULL LIMIT 1";
   $stmt = $mysqli->prepare($patient_check_query);
   $stmt->bind_param("s", $mrn_new);
   $stmt->execute();
   $result = $stmt->get_result();
   $patient = mysqli_fetch_assoc($result);
   
   $errors = array(); 

   if ($patient) { // if user exists
     if ($patient['MRN'] === $mrn_new) {
       array_push($errors, "Patient with same MRN is already addmitted");
     }
 
     
   }
  
   if (count($errors) == 0) { 
    $sql = "INSERT INTO picupatients SET  MRN=?,current_location=?,BED=?, PNAME=?,gender=?,
    ADMFROM=?,ADMDATE=?,nationality=?,age=?
    , admissiondiagnosis=?, admitted_by=?";



//  $sql = "INSERT INTO picupatients (BED) VALUES ('$bed')";


                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("ssssssssiss", $mrn_new, $current_location, $bed_new, $pname_new, $gender_new, $admfrom_new, $admdate, $nationality_new, $age_new, $admissiondiagnosis, $admitted_by);
                if ($stmt->execute() === TRUE) {
                  array_push($errors,"Record added successfully");
                  audit_log('patient.admit','picupatients',$mysqli->insert_id, ['mrn'=>$mrn_new]);
                  // $last_id = mysqli_insert_id($mysqli);
                  echo "<script language='javascript'>\n";
                  echo "window.location.href = 'dmc-new-admissions.php';";
                  echo "</script>\n";
                } else {
                  array_push($errors, "Error adding record: " . $mysqli->error);
                }
    } else{

      echo "<a style='color:red;float:left; text-align:left'>".$errors[0]."</a>";
      
    }
                // echo  $message;


              

?>
