<?php
	require ('dbconnect.php');
    date_default_timezone_set('Asia/Riyadh');
    $today=date("Y-m-d");

    
    if (isset($_POST['transfer_pt_btn'])) {
      // receive all input values from the form
     echo  $transfer_id = $_POST['id'];
      $specialty_transfer = $_POST['specialty_transfer'];
      if ($specialty_transfer =="ICU"){
            // echo" ICU submitted";
      }
            else
        {
             $constulant_transfer =  $_POST['constulant_transfer'];
  
  
             $formationSQL = "SELECT * FROM picupatients WHERE ID='".$transfer_id."'";
             $result1 = $mysqli->query($formationSQL);
             $patient = $result1 -> fetch_array(MYSQLI_ASSOC);
            
            //  var_dump($patient );

             $patient['consultant_id']= $constulant_transfer;
             $patient['newassign']="1";
             $patient['ADMDATE']=$today;
        
/// transfer to new doctor

              $query = "INSERT INTO picupatients (MRN, PNAME, ADMDATE, ADMFROM, admissiondiagnosis, BED, nationality, gender, consultant_id, age, newassign)
               VALUES ('".$patient['MRN']."','".$patient['PNAME']."','".$patient['ADMDATE']."','".$patient['ADMFROM']."',
               '".$patient['admissiondiagnosis']."','".$patient['BED']."','".$patient['nationality']."','".$patient['gender']."','".$patient['consultant_id']."',
               '".$patient['age']."','".$patient['newassign']."') ";

            //   mysqli_query($mysqli, $query);
  
              if (!$mysqli -> query( $query)) {
                echo("Error description: " . $mysqli -> error);

               


              }
              $query = "UPDATE  picupatients SET  DISDATE='".$today."', MORTALITY='Alive', DISTO='Internal Transfer'  WHERE ID='".$transfer_id."'";
              if (!$mysqli -> query( $query)) {
                echo("Error description: " . $mysqli -> error);
              }
            //   // header('location: PICU-patients.php');
        }
    }
    ?>