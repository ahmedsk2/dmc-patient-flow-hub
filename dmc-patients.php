<?php
require_once 'sidebar.php';
if (isset($_POST['reverse_discharge_btn'])) {
  $reverse_id = $_POST['reverse_id'];
  $null = "NULL";
  $query = "UPDATE picupatients SET DISDATE=$null, med_DISDATE=$null , delay=$null WHERE ID='".$reverse_id."'";
  if (!$mysqli -> query( $query)) {
    echo("Error description: " . $mysqli -> error);
  }
}
 
if (isset($_POST['transfer_pt_btn'])) {
  // receive all input values from the form

  
$formationSQL = "SELECT * FROM other_specialities";
$result1 = $mysqli->query($formationSQL);
$other_specialities = $result1 -> fetch_all(MYSQLI_ASSOC);


 $transfer_id = $_POST['id'];

 $specialty_transfer = $_POST['specialty_transfer'];
  if ((array_search($specialty_transfer, array_column($other_specialities, 'specilaity')) !== false)){
    
        $query = "UPDATE  picupatients SET  DISDATE='".$today."', med_DISDATE='".$today."', MORTALITY='Alive', DISTO='".$specialty_transfer."', 	trans_discharge='other transfer', trans_discharge_by='".$user['member_id']."' WHERE ID='".$transfer_id."'";
          if (!$mysqli -> query( $query)) {
            echo("Error description: " . $mysqli -> error);
          }
          // keep icu admission under the same consultant if transferred to ICU

          if ( $specialty_transfer == 'Intensive Care (ICU)'){
            $formationSQL = "SELECT * FROM picupatients WHERE ID='".$transfer_id."'";
            $result1 = $mysqli->query($formationSQL);
            $patient = $result1 -> fetch_array(MYSQLI_ASSOC);

                  $query = "INSERT INTO picupatients (MRN, PNAME, ADMDATE, ADMFROM, admissiondiagnosis, BED, nationality, gender, consultant_id, age, assigned_on, current_location,admitted_by)
                  VALUES ('".$patient['MRN']."','".$patient['PNAME']."','".$today."','Ward',
                  '".$patient['admissiondiagnosis']."','".$patient['BED']."','".$patient['nationality']."','".$patient['gender']."','".$patient['consultant_id']."',
                  '".$patient['age']."','".$today."','ICU','".$user['member_id']."') ";
      
              //   mysqli_query($mysqli, $query);
      
                if (!$mysqli -> query( $query)) {
                  echo("Error description: " . $mysqli -> error);
                }else{
                  echo "<script language='javascript'>\n";
                  echo "window.location.href = 'dmc-patients.php';";
                  echo "</script>\n";
                  
                }

          }
        
  }
        else
    {
          $specialty_id = $_POST['specialty_transfer'];
          $formationSQL = "SELECT * FROM speciality WHERE id='".$specialty_id."'";
          $result1 = $mysqli->query($formationSQL);
          $specialty_tran= $result1 -> fetch_array(MYSQLI_ASSOC);

         $specialty_transfer =  $specialty_tran['specilaity'];
         $constulant_transfer =  $_POST['constulant_transfer'];

         $formationSQL = "SELECT * FROM picupatients WHERE ID='".$transfer_id."'";
         $result1 = $mysqli->query($formationSQL);
         $patient = $result1 -> fetch_array(MYSQLI_ASSOC);
        
        //  var_dump($patient );

         $patient['consultant_id']= $constulant_transfer;
         $patient['newassign']="1";
         $patient['assigned_on']=$today;
         $patient['ADMDATE']=$today;
    
/// transfer to new doctor

          $query = "INSERT INTO picupatients (MRN, PNAME, ADMDATE, ADMFROM, admissiondiagnosis, BED, nationality, gender, consultant_id, age, newassign, assigned_on,admitted_by,current_location)
           VALUES ('".$patient['MRN']."','".$patient['PNAME']."','".$patient['ADMDATE']."','".$patient['ADMFROM']."',
           '".$patient['admissiondiagnosis']."','".$patient['BED']."','".$patient['nationality']."','".$patient['gender']."','".$patient['consultant_id']."',
           '".$patient['age']."','".$patient['newassign']."','".$patient['assigned_on']."','".$user['member_id']."', 'Ward') ";

        //   mysqli_query($mysqli, $query);

          if (!$mysqli -> query( $query)) {
            echo("Error description: " . $mysqli -> error);
          }

          $query = "UPDATE  picupatients SET  DISDATE='".$today."', med_DISDATE='".$today."', MORTALITY='Alive', DISTO='".$specialty_transfer."', trans_discharge='transfer to other speciality', trans_discharge_by='".$user['member_id']."'  WHERE ID='".$transfer_id."'";
          if (!$mysqli -> query( $query)) {
            
            echo("Error description: " . $mysqli -> error);
          }else{
            echo "<script language='javascript'>\n";
            echo "window.location.href = 'dmc-patients.php';";
            echo "</script>\n";
            
          }
        //   // header('location: PICU-patients.php');
    }
}

  if (!in_array($user['position'],$access_PICU_patients)){
    
    echo "
    <div class='content-wrapper'>
    
  
    <section class='content'>
    <div class='container-fluid'>  
    <div class='alert alert-danger' role='alert'> you dont have permission to access this page, Contact you manager if you need to.
    </div>
    </div>
    </section>
    </div>
    ";
    require 'footer.php';

    exit();
  }
?>

     <style>
          .hidden{
                  display: none;
          }
          textarea {
    resize: none;
    overflow: hidden;
}

      </style>


  <!-- Content Wrapper. Contains page content -->
  <div class="content-wrapper">
  
	<?php
   		
		$formationSQL = "SELECT * FROM picupatients WHERE DISDATE IS NULL";
		$result1 = $mysqli->query($formationSQL);
		$activepicupatints = $result1 -> fetch_all(MYSQLI_ASSOC);

$userid=$user['member_id'];

if (in_array($user['position'],$access_PICU_endorsement)){
  $formationSQL = "SELECT * FROM members WHERE position ='3'";
  $result1 = $mysqli->query($formationSQL);
  $consultants = $result1 -> fetch_all(MYSQLI_ASSOC);
}else{
    $formationSQL = "SELECT * FROM members WHERE member_id = $userid";
		$result1 = $mysqli->query($formationSQL);
		$consultants = $result1 -> fetch_all(MYSQLI_ASSOC);

  }


// Fetch all ICD-10 codes and names
$icd10_result = $mysqli->query("SELECT id, name FROM icd10");
$icd10_names = [];
while ($row = $icd10_result->fetch_assoc()) {
    $icd10_names[$row['id']] = $row['name'];
}

  //settings
  $query = "select * from settings";
  $result1 = $mysqli->query($query);
  $settings = $result1 -> fetch_array(MYSQLI_ASSOC);

  $shortlos=$settings['short_los'];
  $longlos=$settings['long_los'];
	?>



    <!-- Content Header (Page header) -->
    <div class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1 class="m-0 text-dark">DMC Patient List</h1>
          </div><!-- /.col -->
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
              <li class="breadcrumb-item active">DMC Patient List</li>
            </ol>
          </div><!-- /.col -->
        </div><!-- /.row -->
      </div><!-- /.container-fluid -->
    </div>
    <!-- /.content-header -->

    <!-- Main content -->
      <div class="container-fluid">  
           

<div class="row">

 <div id="mypresentersTable" class="col-md-12">

            <!-- /.info-box -->
            <?php 

            
  $formationSQL = "SELECT dx_id FROM tb_list";
  $result1 = $mysqli->query($formationSQL);
  $tuber_list1 = $result1 -> fetch_all(MYSQLI_ASSOC);
  $tuber_list=array();
  foreach($tuber_list1 as $tuber){
    $tuber_list[]=$tuber['dx_id'];
  }

  
//find readmissions
$conditions = [];
foreach ($activepicupatints as $s) {
    // Collecting conditions for each patient
    $conditions[] = "(ID < '" . $s['ID'] . "' AND MRN = '" . $s['MRN'] . "' AND DISDATE + INTERVAL 3 DAY >= '" . $s['ADMDATE'] . "')";
}

// Constructing a single query
$formationSQL = "SELECT DISTINCT MRN FROM picupatients WHERE (" . implode(' OR ', $conditions) . ") AND (trans_discharge = 'discharge from ICU' or trans_discharge='discharge from ward' or trans_discharge IS NULL)";
$result1 = $mysqli->query($formationSQL);

$recent = [];
if ($result1) {
    while ($row = $result1->fetch_assoc()) {
        $recent[$row['MRN']] = true;
    }
}


                   $consultant_count=array();
                 
                   $patient_count=0;

                  
                   $active_ICU_count=0;
                   $tuber_set = array_flip($tuber_list);

                   foreach ($consultants as $consultant) {
                    $tuber = 0;
                    $old_n = 0;
                    $new_n = 0;
                    $icu_n = 0;
                    $ward_n = 0;
                    $activepatients = 0;
                    $any = 0;
                
                    foreach ($activepicupatints as $s) {
                        if ($s['consultant_id'] == $consultant['member_id']) {
                            $any++;
                            
                
                            $decodedadmissiondx = json_decode($s['admissiondiagnosis'], true);
                            if ($decodedadmissiondx) {
                                foreach ($decodedadmissiondx as $dx) {
                                    if (isset($tuber_set[$dx])) {
                                        $tuber++;
                                        break;
                                    }
                                }
                            }
                
                            if (is_null($s['newassign'])) {
                                $old_n++;
                            } elseif ($s['newassign'] == '1') {
                                $new_n++;
                            }
                
                            if ($s['current_location'] == 'ICU') {
                                $icu_n++;
                            } else {
                                $ward_n++;
                                $patient_count++;
                                if (!$s['med_DISDATE'] && !$s['longterm'] && !isset($tuber_set[$dx])) {
                                    $activepatients++;
                                }
                            }
                        }
                    }
                
                    if ($any > 0) {
                        $consultant_count[$consultant['member_id']] = [
                            'new' => $new_n,
                            'old' => $old_n,
                            'icu' => $icu_n,
                            'ward' => $ward_n,
                            'activepatients' => $activepatients,
                            'tb1' => $tuber,
                            'active' => $consultant['active'],
                            'specialty_id' => $consultant['specialty_id'],
                            'on_service' => $consultant['on_service'],
                             'name' => $consultant['full_name']
                        ];
                    }
                    if ($any == 0){
                      continue;
                    }
              ?>




            <div class="card">
            <div class="card-header" style="display: flex; align-items: center;">
            <h3 class="card-title">
      <a style="font-size: larger; font-weight: 700; color: black;" class="SeeMore2" id="<?php echo $consultant['member_id']; ?>-1" data-bs-toggle="collapse" href="#collapse<?php echo $consultant['member_id']; ?>" aria-expanded="false" aria-controls="collapse<?php echo $consultant['member_id']; ?>" onclick="showfunction('<?php echo $consultant['member_id']; ?>-1')">+</a>
      <a style="color: black;"  data-bs-toggle="collapse" href="#collapse<?php echo $consultant['member_id']; ?>" aria-expanded="false" aria-controls="collapse<?php echo $consultant['member_id']; ?>" onclick="showfunction('<?php echo $consultant['member_id']; ?>-1')"><i class="fas fa-user-tie text-info"></i> Dr. <?php echo $consultant['full_name']; ?> Patient List</a>
    </h3>

    <div style="margin-left: auto;">
        <?php if ($user['modify_patient'] == '1'): ?>
            <a class="btn btn-info" href="#changeconsultant_modal" data-bs-target="#changeconsultant_modal" data-book-id="<?php echo $consultant['member_id']; ?>" data-bs-toggle="modal" style="color: aliceblue; line-height: 2; padding: 0px 15px;">Change Consultant</a>
        <?php endif; ?>
    </div>
</div>


              <!-- /.card-header -->
              <div class="collapse" id="collapse<?php echo $consultant['member_id']; ?>">
                <div class="row">
         
                       
                                         <?php

                                                    $admdate = array_column($activepicupatints, 'ADMDATE');

                                                    array_multisort($admdate, SORT_ASC , $activepicupatints);
                                                                                            
                                                     foreach($activepicupatints as $s){

                                                    


                                                      if($s['consultant_id'] == $consultant['member_id'] ){

                                                      $decodedadmissiondx=json_decode($s['admissiondiagnosis']);
                                                      if ($decodedadmissiondx){
                                                      $tb_patient=array_intersect($decodedadmissiondx,$tuber_list);
                                                    }else{
                                                      $tb_patient=array();
                                                    }

                                                  
                                                      $today = date("Y-m-d");
                                                      $today1 = strtotime(date("Y-m-d"));
                                                      // $datediff = $today - strtotime($s['ADMDATE']);
                                                      // $LOS = $today->date_diff($s['ADMDATE'])->format("%a");
                                                      $timeDiff = abs($today1 - strtotime($s['ADMDATE']));

                                                      $LOS = $timeDiff/86400;  // 86400 seconds in one day

                                                      // $LOS= round($datediff / (60 * 60 * 24));
                                                      

                                                      
                                                    echo"  
                                                    <div class='col-sm-3'>
                                                    <div class='eachrow card'  id='row".$s['ID']."'>
                                                    
                                                    <div style='   margin: 2%; text-align: center;display: inline; ' class='eachcol bed card-header'  scope='row' >";
                                                    if (in_array($user['position'],$access_PICU_control)){
                                                    echo "<a  type='button' class='btn-tool'  style='display: contents;'   onclick='del(".$s['ID'].")'><i class='fa fa-trash'></i>  </a>";
                                                    }
                                                    // var_dump($decodedadmissiondx);  
                                                    // var_dump($tb_list);
                                                    
                                                    // var_dump($result);
                                                        if ($s['newassign']=='1' AND $s['assigned_on']==$today){
                                                          echo"<p style='color: red;display: contents;'><strong>New</strong></p>";
                                                          
                                                           }
                                                           if (isset($recent[$s['MRN']])) {
                                                            echo"<div style='   background: #fd7e14'><strong> Readmission in 72 hours</strong></div>";
                                                          }
                                                          if ($tb_patient ){
                                                            echo"<div style='   background: #01ff70'><strong> TB Patient</strong></div>";
                                                          }
                                                          if ($s['delay'] !==Null){
                                                        echo"<div style='   background: gold'><strong> Discharged Still in</strong></div>";
                                                      }
                                                      if (!empty($s['longterm'])){
                                                        echo"<div style='    background: #87503e;color: white;'><strong> Long Term Patient</strong></div>";
                                                      }
                                                echo"
                                                <label style='text-align: center;margin-bottom: 0px;'>In ".$s['current_location']." Bed #</label>

                                                      <input class='txtdata' name='bed' placeholder='Bed Number' value='".$s['BED']."' style='text-align: center;' >
                                                      <input class='txtdata' type='hidden' name='id' id='id' value='".$s['ID']."' style='text-align: center;width: 85%;' >
                                                     
                                                      </div>
                                                      
                                                      <div style=' margin: 2%; ' class='eachcol mrn' >
                                                      <label style='text-align: center;margin-bottom: 0px;'>MRN</label>
                                                        <input class='txtdata' name='mrn' value='".$s['MRN']."' style='text-align: center;'>
                                                      </div>

                                                      <div style=' margin: 2%; ' class='eachcol name'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>Patient Name</label>
                                                      <input class='txtdata' name='name' value='".$s['PNAME']."' style='text-align: center;'>
                                                      </div>
                                                
                                                      <div style=' margin: 2%; ' class='eachcol admdate'  scope='row' >
                                                      <label style='text-align: center;margin-bottom: 0px;'>Admission Date</label>
                                                      <input type='text' onfocus='(this.type='date')' class='txtdata' name='admdate'  id='datepicker' value='".$s['ADMDATE']."' style='text-align: center;padding: 0px;' disabled>
                                                      </div>";

                                                      if($s['current_location'] == 'ICU'){
                                                        echo"   <div style='  margin: 2%; background: royalblue;color: white;' class='eachcol'    scope='row' >
                                                        <p style='text-align: center;margin-bottom: 0px;'>ICU patient</p>
                                                       
                                                                </div>
                                                                ";

                                                      } else{

                                                      
                                                                if ($LOS < $shortlos){
                                                                  echo"   <div style=' margin: 2%; background: #d4edda;' class='eachcol admdate'    scope='row' >";
                                                                } elseif   ($LOS > $longlos){
                                                                  echo"   <div style=' margin: 2%; background: #f8d7da;' class='eachcol admdate'   scope='row' >";
                                                                }elseif   ($LOS >= $shortlos){
                                                                  echo"  <div style=' margin: 2%; background: #fff3cd;' class='eachcol admdate'    scope='row' >";
                                                                }
                                                              
                                                              

                                                                echo"
                                                                <label style='text-align: center;margin-bottom: 0px;'>Duration of Admission</label>
                                                                <input type='text' class='txtdata'  value='".$LOS."' style='text-align: center;padding: 0px;' disabled>
                                                                </div>
                                                                ";
                                                              }
                                                 echo"
                                                 <div style=' margin: 2%; ' class='eachcol admissiondiagnosis'>
                                                 <label style='text-align: center;margin-bottom: 0px;'>Diagnosis</label>
                                                      <select class='txtdata ddxname form-control' style='width: 100%;'  oninput='auto_grow(this)'  multiple='multiple' name='admissiondiagnosis'>
                                                      ";
                                                        if (is_array($decodedadmissiondx)) {
                                                          foreach ($decodedadmissiondx as $value) {
                                                              if (isset($icd10_names[$value])) {
                                                                  echo '<option selected value="' . $value . '">' . $icd10_names[$value] . '</option>';
                                                              }
                                                          }
                                                      }

                                                      echo"
                                                      </select></div>
                                                
                                                
                                                      <div style=' margin: 2%; ' class='eachcol longterm'>";
                                                      if (!empty($s['longterm'])){
                                                        echo"<input style='width: auto;' class='txtdata' type='checkbox' name='longterm' value='longterm' checked>
                                                        ";
                                                      }else{
                                                        echo " <input style='width: auto;' class='txtdata'  type='checkbox' name='longterm' value='longterm'>";
                                                      }
                                                      echo "<label for='longterm' style=' display: contents; '> Long Term Patient</label><br> </div>";

                                                      echo"
                                                      <div style=' margin: 2%; ' class='eachcol id' scope='row' ><input class='txtdata' name='id' value='".$s['ID']."' style='display: none;'>
                                                   ";
                                                   if ($user['modify_patient'] == '1'){
                                                   echo "<a class='btn btn-info' href='#details_modal' data-book-id='".$s['ID']."' data-bs-toggle='modal'  style='color: aliceblue;line-height: 2;margin-top: 3%;padding: 0px 10%;width: 100%;'>Modify</a>";
                                                   };
                                                  
                                                   if($s['current_location'] == 'ICU'){
                                                    echo "  <a  class='btn btn-danger'  href='#icu_modal' data-bs-toggle='modal'  data-book-id='".$s['ID']."'  style='color: aliceblue;line-height: 2;margin-top: 3%;padding: 0px 10%;width: 100%;'  id= 'icu_discharge'>ICU Discharge</a>";
                                                   }else{
                                                     
                                                    if ($user['member_id'] == $s['consultant_id'] OR $user['manage_patient'] == '1'){
                                                      if ($s['delay'] !==Null){
                                                        echo" <a  class='btn btn-danger'  href='#completedis_modal' data-bs-toggle='modal'  data-book-id='".$s['ID']."'  style='color: aliceblue;line-height: 2;margin-top: 3%;padding: 0px 10%;width: 100%;'  id= 'discharge'>Complete Discharge</a>";
                                                        if ($user['modify_patient'] == '1'){
                                                        echo "
                                                          <form method='post' action='dmc-patients.php'>
                                                          <input class='txtdata' type='hidden' name='reverse_id' value='".$s['ID']."'>
                                                          <button type='button' value='submit' name='reverse_discharge_btn' class='btn btn-danger' style='color: aliceblue;line-height: 2;margin-top: 3%;padding: 0px 10%;width: 100%;' onclick='reversedischarge(this)'>Reverse Discharge</button>
                                                          </form>";
                                                        };

                                                      }else{
                                                               
                                                                  echo"
                                                                  <a  class='btn btn-success'  href='#transfer_modal' data-bs-toggle='modal'  data-book-id='".$s['ID']."'  style='color: aliceblue;line-height: 2;margin-top: 3%;padding: 0px 10%;width: 100%;'  id= 'Transfer'>Transfer</a>
                                                                  <a  class='btn btn-danger'  href='#my_modal' data-bs-toggle='modal'  data-book-id='".$s['ID']."'  style='color: aliceblue;line-height: 2;margin-top: 3%;padding: 0px 10%;width: 100%;'  id= 'discharge'>Discharge</a>
                                                                  ";
                                                                  }
                                                            }
                                                        }

                                                   echo"  </div>
                                                   </div >
                                                   </div >
                                                      ";
                                                        }
                                                     }


                                                   
                                         ?> 
             

        
                    
    
                  
                  <!-- /.col -->
                </div>
                <!-- /.row -->
              </div>
              <!-- /.card-body -->
            </div>
            <!-- /.card -->
            <?php } ?>
</div>
			
      
 </div> <!--row -->
			
<div class="row">

          <div class="card">
            <div class="card-header">
              <h3 class="card-title"><i class="fas fa-user-tie text-info"></i> Patient Count per Consultant</h3>
              <div id="addbtn" class='eachrow' style='float: right;'>
                <h3 class="card-title"><i class="fas fa-user-tie text-info"></i> Total Count (non ICU): <?php echo $patient_count; ?></h3>
              </div>
            </div>
            <!-- /.card-header -->
            <div class="card-body">
              <div class="row table-responsive">
                <table class="col-md-12" style="text-align: center;">
                  <thead style="text-align: center;font-weight: 700;">
                    <tr>
                      <td class="col-md-4">Consultant</td>
                      <td class="col-md-1">Old Patients</td>
                      <td class="col-md-1">New Patients</td>
                      <td class="col-md-6" colspan="4">All Patients</td>
                    </tr>
                    <tr>
                      <td class="col-md-4"></td>
                      <td class="col-md-1"></td>
                      <td class="col-md-1"></td>
                      <td class="col-md-2">Active Patients</td>
                      <td class="col-md-2">Total Ward Patients</td>
                      <td class="col-md-1">ICU Patients</td>
                      <td class="col-md-1">TB Patients</td>
                    </tr>
                  </thead>
                  <?php
                  // on service hospitalist consultant
                  foreach ($consultant_count as $key => $cc) {
                    if ($cc['on_service'] == '1' && $cc['specialty_id'] == '1' && $cc['active'] == '1') {
                      $total = $cc['ward'];
                      echo "
                      <tr class='eachrow'>
                        <td><p>Dr. " . $cc['name'] . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol'><p>" . $cc['old'] . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol'><p>" . $cc['new'] . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol' scope='row'><p>" . $cc['activepatients'] . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol' scope='row'><p>" . $total . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol' scope='row'><p>" . $cc['icu'] . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol' scope='row'><p>" . $cc['tb1'] . "</p></td>
                      </tr>";
                    }
                  }

                  // on service subs consultant
                  foreach ($consultant_count as $key => $cc) {
                    if ($cc['on_service'] == '1' && $cc['specialty_id'] !== '1' && $cc['active'] == '1') {
                      $total = $cc['ward'];
                      echo "
                      <tr class='eachrow'>
                        <td><p>Dr. " . $cc['name'] . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol'><p>" . $cc['old'] . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol'><p>" . $cc['new'] . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol' scope='row'><p>" . $cc['activepatients'] . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol' scope='row'><p>" . $total . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol' scope='row'><p>" . $cc['icu'] . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol' scope='row'><p>" . $cc['tb1'] . "</p></td>
                      </tr>";
                    }
                  }
                  ?>
                </table>
              </div>
            </div>
          </div>

</div> <!--row -->
 

</div><!--/. container-fluid -->


<!-- Modal patient ICU discharge-->

<div class="modal" id="icu_modal">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
     
          <h4 class="modal-title">Patient Details</h4>
          <button type="button" class="close" data-bs-dismiss="modal">&times;</button>
      </div>
    <!-- data retrived from JS -->
      <div class="modal-body">
      <div id="icudischarge"></div>
      </div>
     
    
  </div>
</div>
</div>    




<!-- Modal patient complete discharge-->

<div class="modal" id="completedis_modal">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
     
          <h4 class="modal-title">Complete Discharge</h4>
          <button type="button" class="close" data-bs-dismiss="modal">&times;</button>
      </div>
    <!-- data retrived from JS -->
      <div class="modal-body">
      <div id="completedis"></div>
      </div>
     
    
  </div>
</div>
</div>    

<!-- Modal patient details-->

<div class="modal" id="details_modal">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
     
          <h4 class="modal-title">Patient Details</h4>
          <button type="button" class="close" data-bs-dismiss="modal">&times;</button>
      </div>
    <!-- data retrived from JS -->
      <div class="modal-body">
      <div id="pdetailsdiv"></div>
      </div>
     
    
  </div>
</div>
</div>    

<!-- Modal patient change consultant-->

<div class="modal" id="changeconsultant_modal">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
     
          <h4 class="modal-title">Change Consultant</h4>
          <button type="button" class="close" data-bs-dismiss="modal">&times;</button>
      </div>
    <!-- data retrived from JS -->
      <div class="modal-body">
      <div id="changeconsultantdiv"></div>
      </div>
     
    
  </div>
</div>
</div>  

<!-- Modal patient transfer_modal-->

<div class="modal" id="transfer_modal">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
     
          <h4 class="modal-title">Transfer Patient</h4>
          <button type="button" class="close" data-bs-dismiss="modal">&times;</button>
      </div>
    <!-- data retrived from JS -->
      <div class="modal-body">
      <div id="ptransferdiv"></div>
      </div>
     
    
  </div>
</div>
</div>    

<!-- Modal -->

<div class="modal" id="my_modal">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
     
          <h4 class="modal-title">Discharge Patient</h4>
          <button type="button" class="close" data-bs-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <p>Kindly Review admission and discharge details</p>

        <div id="pdischargediv"></div>
       
      </div>

      <div class="modal-footer">

      </div>

          </form>
    </div>
  </div>
</div>
           



  </div>
  <!-- /.content-wrapper -->


<?php

require 'footer.php';

?>
  <!-- Select2 JS -->
  <script src="vendor/select2/4.0.13/select2.min.js"></script>
  <!-- Daterangepicker JS -->
  <script src="vendor/moments.js/2.30.1/moment.min.js"></script>
  <script src="vendor/daterangepicker/3.1.0/daterangepicker.min.js"></script>
  <script type="text/javascript">
      $(document).ready(function() {
        $('.select2').select2({
      placeholder: 'Select',
      
    } );

        $('.ddxname').select2({
            placeholder: 'Select',
            minimumInputLength: 4,
            ajax: {
                url: 'fetchicd10.php',
                dataType: 'json',
                delay: 250,
                data: function (data) {
                    return {
                        searchTerm: data.term // search term
                    };
                },
                processResults: function (response) {
                    return {
                        results:response
                    };
                },
                cache: true
            }
        });
      });

    </script>
  <script>

$(document).ready(function($) {
  
  $('.txtdata').on('change', function(){
var parent = $(this).parent('.eachcol').parent('.eachrow');
    var id = $(parent).find('.id').find('input').val();
    var bed = $(parent).find('.bed').find('input').val();
    var mrn = $(parent).find('.mrn').find('input').val();
    var name = $(parent).find('.name').find('input').val();
    // var admfrom = $(parent).find('.admfrom').find('select').val();
    var admdate = $(parent).find('.admdate').find('input').val();
    var admissiondiagnosis = $(parent).find('.admissiondiagnosis').find('select').val();
    
    var longtermbox = $(parent).find('.longterm').find('input[name$="longterm"]');
    if(longtermbox.prop('checked') === true){
          var longterm = $(parent).find('.longterm').find('input[name$="longterm"]').val();
        }else{
          var longterm = '';
        }

    //  alert (scot);

    var attribChanged = $(this).attr('name');
    data = {id: id, bed: bed, mrn: mrn,name: name,  admdate: admdate, longterm:longterm
      // , admfrom: admfrom
      , admissiondiagnosis:admissiondiagnosis, attribChanged: attribChanged};
    $.post('patients/dmc-patients-update.php', data, function(data){
      // $(parent).html(data);
     
    });
    $(this).parent('.eachcol').css("backgroundColor", "#90EE90");

  });
});
</script>

<script>

$("textarea").each(function(textarea) {
    $(this).height( $(this)[0].scrollHeight );
});


  </script>


<script>
$(function() {
  $('input[name="admdate"]').daterangepicker({
    singleDatePicker: true,
    timePicker: false,
    timePicker24Hour: false,
    autoUpdateInput: false,
    showDropdowns: true,
    minYear: 2010,
    maxYear: parseInt(moment().format('YYYY'),10),
    locale: {
            format: 'YYYY-MM-DD'
        }
  }, ).on("apply.daterangepicker", function (e, picker) {
        picker.element.val(picker.startDate.format(picker.locale.format));
        var parent = $(this).parent('.eachcol').parent('.eachrow');
    var id = $(parent).find('.id').find('input').val();
    var bed = $(parent).find('.bed').find('input').val();
    var mrn = $(parent).find('.mrn').find('input').val();
    var name = $(parent).find('.name').find('input').val();
    // var admfrom = $(parent).find('.admfrom').find('select').val();
    var admdate = $(parent).find('.admdate').find('input').val();
    var admissiondiagnosis = $(parent).find('.admissiondiagnosis').find('select').val();
    var longtermbox = $(parent).find('.longterm').find('input[name$="longterm"]');
    if(longtermbox.prop('checked') === true){
          var longterm = $(parent).find('.longterm').find('input[name$="longterm"]').val();
        }else{
          var longterm = '';
        }
 
    //  alert (scot);

    var attribChanged = $(this).attr('name');
    data = {id: id, bed: bed, mrn: mrn,name: name,  admdate: admdate,longterm:longterm,
      // admfrom: admfrom,
       admissiondiagnosis:admissiondiagnosis, attribChanged: attribChanged};
    $.post('patients/dmc-patients-update.php', data, function(data){
      // $(parent).html(data);
     
    });
    $(this).parent('.eachcol').css("backgroundColor", "#90EE90");
    });
});


</script>



        <script>


function auto_grow(element) {
    element.style.height = "5px";
    element.style.height = (element.scrollHeight)+"px";
}


function del(value){
  if(!confirm("Do you really want to delete this patient?")) {
    return false;
  }
  var rowname= "row";
rowname+=value;
row = document.getElementById(rowname);

    var id = value;
    data = {id: id};
    $.post('patients/dmc-patient-delete.php', data, function(data){
    // $(parent).html(data);
  });
  // location.reload();
  row.style.display = "none";
    }


    document.addEventListener('DOMContentLoaded', function () {
      document.querySelectorAll('.collapse').forEach(function (collapseElement) {
        collapseElement.addEventListener('show.bs.collapse', function () {
          var triggerElement = collapseElement.previousElementSibling.querySelector('.SeeMore2');
          if (triggerElement) {
            triggerElement.textContent = '-';
          }
        });
        collapseElement.addEventListener('hide.bs.collapse', function () {
          var triggerElement = collapseElement.previousElementSibling.querySelector('.SeeMore2');
          if (triggerElement) {
            triggerElement.textContent = '+';
          }
        });
      });
    });

    function showfunction(elementId) {
      var element = document.getElementById(elementId);
      if (!element) return;

      element.classList.toggle('SeeMore2');
      if (element.classList.contains('SeeMore2')) {
        element.innerHTML = '+';
      } else {
        element.innerHTML = '-';
      }
    }
	</script>
      

      
<script>

function reversedischarge(button) {
    // Disable the button and show loading spinner
    button.disabled = true;
    button.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"><span class="sr-only">Loading...</span></div>';
    // Allow the form to be submitted
    // Create a hidden input with the same name as the button to include it in the form submission
    var hiddenInput = document.createElement('input');
    hiddenInput.type = 'hidden';
    hiddenInput.name = button.name;
    hiddenInput.value = button.value;
    button.form.appendChild(hiddenInput);

    // Allow the form to be submitted
    button.form.submit();
}


$('#my_modal').on('show.bs.modal', function(e) {
 
  var bookId = $(e.relatedTarget).data('book-id');
  var userid = <?php echo $user['member_id']; ?>;

 data = {bookId: bookId,userid:userid};
 $.post('patients/dmc-patients-discharge.php', data, function(data){
 $('#pdischargediv').html(data);
     
    });
  
    // $(e.currentTarget).find('input[name="patientId"]').val(bookId);
    // disdate=document.getElementById('disdate').value='';
    // document.getElementById('finaldiagnosis').value='';
    // document.getElementById('disstatus').value='';
    // document.getElementById('disto').value='';
    // document.getElementById('disdate').style.backgroundColor = "";
    // document.getElementById('finaldiagnosis').style.backgroundColor = "";
    // document.getElementById('disto').style.backgroundColor = "";
    // document.getElementById('disstatus').style.backgroundColor = "";
});

$('#details_modal').on('show.bs.modal', function(e) {
     $.fn.modal.Constructor.prototype.enforceFocus = function () { };

//  var bookId = $(e.relatedTarget).data('book-id');
 var bookId = $(e.relatedTarget).data('book-id');
//  $(e.currentTarget).find('input[name="patientId"]').val(bookId);


 data = {bookId: bookId};
 $.post('patients/dmc-patients-details.php', data, function(data){
 $('#pdetailsdiv').html(data);
     
    });
});

$('#changeconsultant_modal').on('show.bs.modal', function(e) {
 
 //  var bookId = $(e.relatedTarget).data('book-id');
  var bookId = $(e.relatedTarget).data('book-id');
 //  $(e.currentTarget).find('input[name="patientId"]').val(bookId);
 
 
  data = {bookId: bookId};
  $.post('patients/dmc-patients-changeconsultant.php', data, function(data){
  $('#changeconsultantdiv').html(data);
      
     });
 });

// completedis_modale modal 

$('#completedis_modal').on('show.bs.modal', function(e) {
 
 var bookId = $(e.relatedTarget).data('book-id');
 var userid = <?php echo $user['member_id']; ?>;

 data = {bookId: bookId,userid:userid};
 $.post('patients/dmc-patients-completedischarge.php', data, function(data){
 $('#completedis').html(data);

     
    });
});

// icu dishcarge modal 

$('#icu_modal').on('show.bs.modal', function(e) {
 
  var bookId = $(e.relatedTarget).data('book-id');
  var userid = <?php echo $user['member_id']; ?>;

  data = {bookId: bookId,userid:userid};
  $.post('patients/dmc-patients-icudischarge.php', data, function(data){
  $('#icudischarge').html(data);

      
     });
 });

 /// transfer modal
 $('#transfer_modal').on('show.bs.modal', function(e) {
 
 //  var bookId = $(e.relatedTarget).data('book-id');
  var myBookId1 = $(e.relatedTarget).data('book-id');
 //  $(e.currentTarget).find('input[name="patientId"]').val(bookId);
//  $(".modal-body #bookId").val( myBookId );
 

  data = {myBookId1: myBookId1};
  $.post('patients/dmc-patients-ptransferdiv.php', data, function(data){
  $('#ptransferdiv').html(data);

      
     });
 });


</script>


