<?php 
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
    DISDATE='".$disdate."',MORTALITY='".$disstatus."',DISTO='".$disto."' 
  ,trans_discharge='discharge from ward', trans_discharge_by='".$userid."'  WHERE ID='".$id."'";
 


//  $sql = "INSERT INTO picupatients (BED) VALUES ('$bed')";


              if ($mysqli->query($sql) === TRUE) {
                $message= "Record added successfully";
                // $last_id = mysqli_insert_id($mysqli);
              } else {
               $message= "Error adding record: " . $mysqli->error;
              }

              // echo  $message;
echo "<a>".$message."</a>";




?>
