<?php
require_once __DIR__ . '/../guard.php'; require_login();
csrf_verify();
 
require ('../dbconnect.php');

   $bed_new = $_REQUEST['bed_new']; 
   $mrn_new = trim($_REQUEST['mrn_new']); 
   $pname_new = $_REQUEST['pname_new']; 
   $age_new = $_REQUEST['age_new']; 
   $current_location = $_REQUEST['current_location_new'];
   $entered_by=$_REQUEST['entered_by'];
     $consultdate_new1=$_REQUEST['consultdate_new'];  
     if (!empty($consultdate_new1)){
       $consultdate_new=date("Y-m-d",strtotime($consultdate_new1));
       }else{
         $consultdate_new=null;
       }
  $other_indication=$_REQUEST['other_indication'];

   $consultfrom_new = $_REQUEST['consultfrom_new']; 

   $indication_new1 = $_REQUEST['indication_new']; 
   $indication_new = json_encode($indication_new1); 
   $consultant_new = $_REQUEST['consultant_new']; 

   if(isset($_REQUEST['consultation_to_service'])){
   $consultation_to_service = $_REQUEST['consultation_to_service']; 
  }

  
    $sql = "INSERT INTO consultations SET  MRN=?,current_location=?,BED=?, PNAME=?,
    consultation_from=?,consultation_date=?,age=?
    , indication=?, entered_by_id=?, consultant_id=?, other_ind=?, consultation_to_service=?";



//  $sql = "INSERT INTO picupatients (BED) VALUES ('$bed')";


                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("ssssssisiiss", $mrn_new, $current_location, $bed_new, $pname_new, $consultfrom_new, $consultdate_new, $age_new, $indication_new, $entered_by, $consultant_new, $other_indication, $consultation_to_service);
                if ($stmt->execute() === TRUE) {
                    $message = "Record added successfully";
                    audit_log('consultation.create','consultations',$mysqli->insert_id);
                  // $last_id = mysqli_insert_id($mysqli);
                  echo "<script language='javascript'>\n";
                  echo "window.location.href = 'dmc-new-consultation.php';";
                  echo "</script>\n";
                } else {
                    $message= "Error adding record: " . $mysqli->error;
                }
   
                echo  $message;

?>
