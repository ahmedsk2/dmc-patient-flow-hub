<?php
require_once __DIR__ . '/../guard.php'; require_role([0, 2, 3, 4]); require_capability('modify_patient');
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

   // CLIN-MRN: canonical MRN format is digits-only (<=11). ~1.4% of legacy records hold a
   // non-conforming MRN from data-entry errors (names/beds typed into the field); enforcing the
   // format on every save would block editing those patients' OTHER fields. So validate the MRN
   // only when it is actually being CHANGED — setting a new MRN still requires the clean format.
   $mrn_changed = true;
   if ($mrn_check = $mysqli->prepare("SELECT MRN FROM picupatients WHERE ID = ?")) {
       $mrn_check->bind_param("i", $id);
       $mrn_check->execute();
       $mrn_row = $mrn_check->get_result()->fetch_assoc();
       $mrn_check->close();
       if ($mrn_row && (string) $mrn_row['MRN'] === (string) $mrn_modify) { $mrn_changed = false; }
   }

   // W3: validate the edited patient record before persisting (client checks are bypassable).
   $verr = v_first([
       $mrn_changed ? v_mrn($mrn_modify) : '',
       v_len($pname_modify, 'Patient name', 100),
       v_in($gender_modify, 'Gender', ['Male', 'Female']),
       v_int_range($age_modify, 'Age', 0, 150),
       v_date_ymd($admdate, 'Admission date'),
       v_required($nationality_modify, 'Nationality'),
       v_required($admfrom_modify, 'Admitted from'),
       v_required($current_location_modify, 'Patient location'),
       v_required($primary_modify, 'Primary consultant'),
   ]);
   if ($verr !== '') { echo "<a>Error: " . htmlspecialchars($verr, ENT_QUOTES, 'UTF-8') . "</a>"; exit; }

    $sql = "UPDATE  picupatients SET  MRN=?,BED=?, PNAME=?,gender=?,age=?,
    ADMFROM=?,ADMDATE=?,nationality=?
    , admissiondiagnosis=? , consultant_id=?, current_location=? WHERE ID=?";



//  $sql = "INSERT INTO picupatients (BED) VALUES ('$bed')";


                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("ssssissssisi", $mrn_modify, $bed_modify, $pname_modify, $gender_modify, $age_modify, $admfrom_modify, $admdate, $nationality_modify, $admissiondiagnosis, $primary_modify, $current_location_modify, $id);
                if ($stmt->execute() === TRUE) {
                  $message= "Record added successfully";
                  audit_log('patient.modify','picupatients',$id, ['mrn'=>$mrn_modify]);
                  // $last_id = mysqli_insert_id($mysqli);
                } else {
                 error_log(__FILE__ . ": add " . $mysqli->error); $message= "Error adding record.";
                }

                // echo  $message;
echo "<a>".$nationality_modify."<br>".$message."</a>";


              

?>
