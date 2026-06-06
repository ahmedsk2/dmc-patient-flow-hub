<?php
require_once __DIR__ . '/../guard.php'; require_login();
csrf_verify();
 
require_once ('../dbconnect.php');
$id = $_REQUEST['id_modify']; 
$userid = $_REQUEST['userid']; 

   
$disdate1 = $_REQUEST['disdate']; 
if (!empty($disdate1)){
  $disdate=date("Y-m-d",strtotime($disdate1));
  }else{
    $disdate=null;
  }


$disstatus = $_REQUEST['disstatus']; 

$disto = $_REQUEST['disto']; 

// echo $discahrge_type;

  $sql = "UPDATE  picupatients SET
    DISDATE=?,MORTALITY=?,DISTO=?
  ,trans_discharge='discharge from ward', trans_discharge_by=?  WHERE ID=?";



//  $sql = "INSERT INTO picupatients (BED) VALUES ('$bed')";


              $stmt = $mysqli->prepare($sql);
              $stmt->bind_param("sssii", $disdate, $disstatus, $disto, $userid, $id);
              if ($stmt->execute() === TRUE) {
                $message= "Record added successfully";
                audit_log('patient.complete_discharge','picupatients',$id);
                // $last_id = mysqli_insert_id($mysqli);
              } else {
               $message= "Error adding record: " . $mysqli->error;
              }

              // echo  $message;
echo "<a>".$message."</a>";




?>
