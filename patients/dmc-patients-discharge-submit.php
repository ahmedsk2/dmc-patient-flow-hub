<?php
require_once __DIR__ . '/../guard.php'; require_login();
csrf_verify();
 
require_once ('../dbconnect.php');
$id = $_REQUEST['id_modify']; 
   $bed_modify = $_REQUEST['bed_modify']; 
   $mrn_modify = $_REQUEST['mrn_modify']; 
   $gender_modify = $_REQUEST['gender_modify']; 
   $pname_modify = $_REQUEST['pname_modify']; 
   $age = $_REQUEST['age']; 
   $nationality_modify=$_REQUEST['nationality_modify']; 
   $userid = $_REQUEST['userid']; 

  

     $admdate1 = $_REQUEST['admdate']; 
     if (!empty($admdate1)){
       $admdate=date("Y-m-d",strtotime($admdate1));
       }else{
         $admdate=null;
       }

   $admfrom_modify = $_REQUEST['admfrom_modify']; 

   $admissiondiagnosis_modify1 = $_REQUEST['admissiondiagnosis_modify']; 
   $admissiondiagnosis = json_encode($admissiondiagnosis_modify1); 
   
   
$disdate1 = $_REQUEST['disdate']; 
if (!empty($disdate1)){
  $disdate=date("Y-m-d",strtotime($disdate1));
  }else{
    $disdate=null;
  }

$discahrge_type= $_REQUEST['discahrge_type']; 


$disstatus = $_REQUEST['disstatus']; 

$disto = $_REQUEST['disto'];

// W3: validate the discharge-specific inputs (defense-in-depth; client checks are bypassable).
$verr = v_first([
    v_in($discahrge_type, 'Discharge type', ['medical', 'both']),
    v_date_ymd($disdate, 'Discharge date'),
    v_required($disstatus, 'Status at discharge'),
    v_required($disto, 'Discharged to'),
]);
if ($verr !== '') { echo "<a>Error: " . htmlspecialchars($verr, ENT_QUOTES, 'UTF-8') . "</a>"; exit; }

// echo $discahrge_type;
if (strcmp($discahrge_type,'medical') == 0){ //still in


  $sql = "UPDATE  picupatients SET  MRN=?,BED=?, PNAME=?, age=?, gender=?,
  ADMFROM=?,nationality=?
  ,ADMDATE=?
    ,med_DISDATE=?,MORTALITY=?,delay=?
  , admissiondiagnosis=?, trans_discharge_by=?  WHERE ID=?";



//  $sql = "INSERT INTO picupatients (BED) VALUES ('$bed')";


              $stmt = $mysqli->prepare($sql);
              $stmt->bind_param("sssissssssssii", $mrn_modify, $bed_modify, $pname_modify, $age, $gender_modify, $admfrom_modify, $nationality_modify, $admdate, $disdate, $disstatus, $disto, $admissiondiagnosis, $userid, $id);
              if ($stmt->execute() === TRUE) {
                $message= "Record added successfully";
                audit_log('patient.discharge','picupatients',$id, ['type'=>'medical']);
                // $last_id = mysqli_insert_id($mysqli);
              } else {
               error_log(__FILE__ . ": add " . $mysqli->error); $message= "Error adding record.";
              }

              // echo  $message;
echo "<a>".$message."</a>";

}else{
  $sql = "UPDATE  picupatients SET  MRN=?,BED=?, PNAME=?, age=?, gender=?,
  ADMFROM=?,nationality=?
  ,ADMDATE=?
    ,DISDATE=?,med_DISDATE=?,MORTALITY=?,DISTO=?
  , admissiondiagnosis=?, trans_discharge='discharge from ward', trans_discharge_by=?  WHERE ID=?";



//  $sql = "INSERT INTO picupatients (BED) VALUES ('$bed')";


              $stmt = $mysqli->prepare($sql);
              $stmt->bind_param("sssisssssssssii", $mrn_modify, $bed_modify, $pname_modify, $age, $gender_modify, $admfrom_modify, $nationality_modify, $admdate, $disdate, $disdate, $disstatus, $disto, $admissiondiagnosis, $userid, $id);
              if ($stmt->execute() === TRUE) {
                $message= "Record added successfully";
                audit_log('patient.discharge','picupatients',$id, ['type'=>'complete']);
                // $last_id = mysqli_insert_id($mysqli);
              } else {
               error_log(__FILE__ . ": add " . $mysqli->error); $message= "Error adding record.";
              }

              // echo  $message;
echo "<a>".$message."</a>";


            

}




?>
