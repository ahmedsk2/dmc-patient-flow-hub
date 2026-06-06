<?php
require_once __DIR__ . '/../guard.php'; require_login();
 
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
// echo $discahrge_type;
if (strcmp($discahrge_type,'medical') == 0){ //still in


  $sql = "UPDATE  picupatients SET  MRN='".$mrn_modify."',BED='".$bed_modify."', PNAME='".$pname_modify."', age='".$age."', gender='".$gender_modify."',
  ADMFROM='".$admfrom_modify."',nationality='".$nationality_modify."'
  ,ADMDATE=" . ($admdate==NULL ? "NULL" : "'".$admdate."'") . "
    ,med_DISDATE='".$disdate."',MORTALITY='".$disstatus."',delay='".$disto."' 
  , admissiondiagnosis='".$admissiondiagnosis."', trans_discharge_by='".$userid."'  WHERE ID='".$id."'";
 


//  $sql = "INSERT INTO picupatients (BED) VALUES ('$bed')";


              if ($mysqli->query($sql) === TRUE) {
                $message= "Record added successfully";
                // $last_id = mysqli_insert_id($mysqli);
              } else {
               $message= "Error adding record: " . $mysqli->error;
              }

              // echo  $message;
echo "<a>".$message."</a>";

}else{
  $sql = "UPDATE  picupatients SET  MRN='".$mrn_modify."',BED='".$bed_modify."', PNAME='".$pname_modify."', age='".$age."', gender='".$gender_modify."',
  ADMFROM='".$admfrom_modify."',nationality='".$nationality_modify."'
  ,ADMDATE=" . ($admdate==NULL ? "NULL" : "'".$admdate."'") . "
    ,DISDATE='".$disdate."',med_DISDATE='".$disdate."',MORTALITY='".$disstatus."',DISTO='".$disto."' 
  , admissiondiagnosis='".$admissiondiagnosis."', trans_discharge='discharge from ward', trans_discharge_by='".$userid."'  WHERE ID='".$id."'";
 


//  $sql = "INSERT INTO picupatients (BED) VALUES ('$bed')";


              if ($mysqli->query($sql) === TRUE) {
                $message= "Record added successfully";
                // $last_id = mysqli_insert_id($mysqli);
              } else {
               $message= "Error adding record: " . $mysqli->error;
              }

              // echo  $message;
echo "<a>".$message."</a>";


            

}




?>
