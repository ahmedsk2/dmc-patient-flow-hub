<?php
require_once __DIR__ . '/../guard.php'; require_role([0, 2, 3, 4]); require_capability('assign_access');

session_start();

require_once "../authCookieSessionValidate.php";

if(!$isLoggedIn) {
    header("Location: ../");
}
date_default_timezone_set('Asia/Riyadh');
$today = date("Y-m-d");


// if (!in_array($user['position'],$access_PICU_control)){

//     echo "
//     <div class='content-wrapper'>
    
  
//     <section class='content'>
//     <div class='container-fluid'>  
//     <div class='alert alert-danger' role='alert'> you dont have permission to access this page, Contact you manager.
//     </div>
//     </div>
//     </section>
//     </div>
//     ";
  

//     exit();
//   }


  require_once ('../dbconnect.php');
// get settings and limits of patients
$query = "select * from settings";
$result1 = $mysqli->query($query);
$settings = $result1 -> fetch_array(MYSQLI_ASSOC);

$min_hospitalist= $settings['min_hospitalist'];
$max_hospitalist= $settings['max_hospitalist'];
$min_subs= $settings['min_subs'];
$max_subs= $settings['max_subs'];


/// resetting new labeling
// $sql = "UPDATE picupatients SET newassign= Null,  consultant_id = Null limit 25";
$sql = "UPDATE picupatients SET newassign= Null WHERE assigned_on != ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("s", $today);
        if ($stmt->execute() === TRUE) {
            $message= "New labeling Rest done";
          } else {
           error_log(__FILE__ . ": New labeling Rest " . $mysqli->error); $message= "Error New labeling Rest.";
          }

// select hospitalists only and on service
$formationSQL = "SELECT * FROM members WHERE specialty_id = '1' AND on_service = '1'";
$result1 = $mysqli->query($formationSQL);
$hospitalist = $result1 -> fetch_all(MYSQLI_ASSOC);

// select other-subspeciality only and on service
$formationSQL = "SELECT * FROM members WHERE specialty_id != '1' AND on_service = '1'";
$result1 = $mysqli->query($formationSQL);
$subspeciality = $result1 -> fetch_all(MYSQLI_ASSOC);







//////////// ICU Patients distributed

$formationSQL = "SELECT COUNT(*) FROM picupatients WHERE DISDATE IS NULL AND current_location = 'ICU' AND consultant_id IS NULL";
$result1 = $mysqli->query($formationSQL);
$new_icu_patients = $result1 -> fetch_array(MYSQLI_ASSOC);

$n_new_icu_patients = $new_icu_patients['COUNT(*)'];

while ($n_new_icu_patients > 0){

    foreach ($hospitalist as $h){

    $n_new_icu_patients--;
    $sql = "UPDATE picupatients SET consultant_id=?, newassign='1', assigned_on=? WHERE DISDATE IS NULL AND current_location = 'ICU' AND consultant_id IS NULL Limit 1";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("is", $h['member_id'], $today);
    if ($stmt->execute() === TRUE) {
        $message= "</br> ICU Records Record updated successfully </br>";
    } else {
    error_log(__FILE__ . ": update " . $mysqli->error); $message= "Error updating record.";
    }
      echo "$message";
    echo "new ICU ramining" . $n_new_icu_patients;
    // echo  "</br> Minimum count" .$leastcount;
    // echo  $message;

    }

}



  ///  Count how many active paitent not in ICU for each doctor
  $formationSQL = "SELECT * FROM picupatients WHERE med_DISDATE IS NULL AND DISDATE IS NULL AND (longterm != 'longterm' or longterm is null) AND (current_location != 'ICU' or current_location is null)";
  $result1 = $mysqli->query($formationSQL);
  $activepicupatints = $result1 -> fetch_all(MYSQLI_ASSOC);
  
            /// make TB dx list
                    $formationSQL = "SELECT dx_id FROM tb_list";
                    $result1 = $mysqli->query($formationSQL);
                    $tb_list1 = $result1 -> fetch_all(MYSQLI_ASSOC);
                    $tb_list=array();
                    foreach($tb_list1 as $tb){
                        $tb_list[]=$tb['dx_id'];
                    }

                    // var_dump($activepicupatints);

 $hospitalist_count=array();


  foreach ($hospitalist as $h){
    $p_number=0;
    foreach ($activepicupatints as $a){
        $decodedadmissiondx=json_decode($a['admissiondiagnosis']);
        if ($decodedadmissiondx){
            $is_tb_patient=array_intersect($decodedadmissiondx,$tb_list);
        }else {
            $is_tb_patient=array();
        }
        if ($is_tb_patient){ /// Dont Count TB patients

            }else{
                if ($a['consultant_id'] == $h['member_id']){
                    $p_number++;
                }
            }
        }


    $hospitalist_count[$h['member_id']]=$p_number;
  }

//   var_dump($hospitalist_count);
  asort($hospitalist_count);

  


// how many patients is there....
$formationSQL = "SELECT COUNT(*) FROM picupatients WHERE DISDATE IS NULL AND consultant_id IS NULL ";
$result1 = $mysqli->query($formationSQL);
$newpateints = $result1 -> fetch_array(MYSQLI_ASSOC);
// var_dump($newpateints);
$n_newpatients = $newpateints['COUNT(*)'];
// start giving those with less than 8 first...............................................................
// echo "$n_newpatients";

$leastcount =0;

while (($leastcount <= ($min_hospitalist-1)) && $n_newpatients > 0){
    foreach ($hospitalist_count as $key => $hc){
        if ($hc < $min_hospitalist){
            $hospitalist_count[$key]++;
            $n_newpatients--;
            $sql = "UPDATE picupatients SET consultant_id=?,newassign='1', assigned_on=? WHERE DISDATE IS NULL AND consultant_id IS NULL Limit 1";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("is", $key, $today);
            if ($stmt->execute() === TRUE) {
                $message= "</br> First Round ( fill all hospitalist with less than 8 patients ): Record updated successfully </br>";
            } else {
            error_log(__FILE__ . ": update " . $mysqli->error); $message= "Error updating record.";
            }
              echo "$message";
            echo "</br>new ramining" . $n_newpatients;
            // echo  "</br> Minimum count" .$leastcount;
            // echo  $message;
        }
        // echo  "</br><strong> First Round </strong>";

        if ($n_newpatients <=0){
            break 2;
        }
    }
    $leastcount = min($hospitalist_count);
    // echo $leastcount;
}


// if the least consultant has 8 then start giving the one with more than 8

if($leastcount >= $min_hospitalist && $n_newpatients>0){
// echo "2nd stage";
    while (($leastcount < $max_hospitalist)){
        foreach ($hospitalist_count as $key => $hc){
            if ($hc < $max_hospitalist){
                $hospitalist_count[$key]++;
                $n_newpatients--;
                $sql = "UPDATE picupatients SET consultant_id=?,newassign='1', assigned_on=? WHERE DISDATE IS NULL AND consultant_id IS NULL Limit 1";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("is", $key, $today);
                if ($stmt->execute() === TRUE) {
                    $message= "</br> second Round ( all hospitalist have 8 patients or more ): Record updated successfully </br>";
                } else {
                error_log(__FILE__ . ": update " . $mysqli->error); $message= "Error updating record.";
                }
                  echo "$message";
                // echo "</br>new ramining" . $n_newpatients;
                // echo  "</br> Minimum count" .$leastcount;
            }
            // echo  "</br><strong> second Round </strong>";
            if ($n_newpatients==0){
                break 2;
            }
        }
        $leastcount = min($hospitalist_count);
        // echo $leastcount;
    }


}




// subspecilaities sorting
$subcount =0;
if ($leastcount>=$max_hospitalist && $n_newpatients>0){

        $subspeciality_count=array();


        foreach ($subspeciality as $h){
        $p_number=0;
        foreach ($activepicupatints as $a){
        if ($a['consultant_id'] == $h['member_id']){
            $p_number++;
        }
        }
        $subspeciality_count[$h['member_id']]=$p_number;
        }



        //   var_dump($hospitalist_count);
        asort($subspeciality_count);
        //   var_dump($hospitalist_count);

        // var_dump($subspeciality);

        
        while (($subcount < 5)){
            foreach ($subspeciality_count as $key => $hc){
                if ($hc < $max_subs){
                    $subspeciality_count[$key]++;
                    $n_newpatients--;
                    $sql = "UPDATE picupatients SET consultant_id=?,newassign='1', assigned_on=? WHERE DISDATE IS NULL AND consultant_id IS NULL Limit 1";
                    $stmt = $mysqli->prepare($sql);
                    $stmt->bind_param("is", $key, $today);
                    if ($stmt->execute() === TRUE) {
                        $message= "</br> third round ( all hospitalist have 15 and fill subspeciality with less than 5 ): Record updated successfully </br>";
                    } else {
                    error_log(__FILE__ . ": update " . $mysqli->error); $message= "Error updating record.";
                    }
                      echo "$message";
                    // echo "</br>new ramining" . $n_newpatients;
                    // echo  "</br> Minimum count" .$subcount;
                }
                // echo  "</br><strong> First Round </strong>";

                if ($n_newpatients <=0){
                    break 2;
                }
            }
            $subcount = min($subspeciality_count);
            echo "</br>" . $subcount . "</br>";
        }
}


// redistribure again on hospitalitsts the remaining extra characters
if ($subcount>=5 && $n_newpatients>0){

    while (($n_newpatients > 0)){
        foreach ($hospitalist_count as $key => $hc){
            
                $hospitalist_count[$key]++;
                $n_newpatients--;
                $sql = "UPDATE picupatients SET consultant_id=?,newassign='1', assigned_on=? WHERE DISDATE IS NULL AND consultant_id IS NULL Limit 1";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("is", $key, $today);
                if ($stmt->execute() === TRUE) {
                    $message= "</br> Last round (Give extra to hospitalist as subs already have 5 ): Record updated successfully </br>";
                } else {
                error_log(__FILE__ . ": update " . $mysqli->error); $message= "Error updating record.";
                }
                  echo "$message";
                // echo "</br>new ramining" . $n_newpatients;
                // echo  "</br> Minimum count" .$leastcount;
                // echo  $message;

            
        }
    
    
    }
}
audit_log('patients.shuffle','picupatients',null); // bulk auto-assignment run (actor from session)
echo "<script language='javascript'>\n";
echo "window.location.href = '../dmc-new-admissions.php';";
echo "</script>\n";


?>
