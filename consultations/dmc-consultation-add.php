<?php
require_once __DIR__ . '/../guard.php'; require_role([0, 2, 3, 4]); // clinical roles only (no Observer)
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

  // W3: validate the new consultation input (client checks are bypassable). Require the patient
  // identity; validate age/date only when provided (the schema allows them to be null).
  $verr = v_first([
      v_len($mrn_new, 'MRN', 50),
      v_len($pname_new, 'Patient name', 100),
      ($age_new === '' || $age_new === null) ? '' : v_int_range($age_new, 'Age', 0, 150),
      ($consultdate_new === '' || $consultdate_new === null) ? '' : v_date_ymd($consultdate_new, 'Consultation date'),
  ]);
  if ($verr !== '') { echo "Error: " . htmlspecialchars($verr, ENT_QUOTES, 'UTF-8'); exit; }

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
                    error_log(__FILE__ . ": adding " . $mysqli->error); $message= "Error adding record.";
                }
   
                echo  $message;

?>
