<?php
require_once __DIR__ . '/../guard.php'; require_role([0]);

session_start();
require ('../dbconnect.php');

  // TB list

  $formationSQL = "SELECT dx_id FROM tb_list";
  $result1 = $mysqli->query($formationSQL);
  $tb_list1 = $result1 -> fetch_all(MYSQLI_ASSOC);
  $tb_list=array();
  foreach($tb_list1 as $tb){
    $tb_list[]=$tb['dx_id'];
  }
  //settings
  $query = "select * from settings";
  $result1 = $mysqli->query($query);
  $settings = $result1 -> fetch_array(MYSQLI_ASSOC);

  $shortlos=$settings['short_los'];
  $longlos=$settings['long_los'];


  
date_default_timezone_set('Asia/Riyadh');

    $q = "SELECT * FROM picupatients WHERE ADMDATE IS NOT NULL";

            if(isset($_REQUEST['mrn']) && !empty($_REQUEST['mrn'])){
                $q .= " AND MRN='".$_REQUEST['mrn']."'";
            }

            if(isset($_REQUEST['beforedate']) && !empty($_REQUEST['beforedate']) && !empty($_REQUEST['afterdate']) && !empty($_REQUEST['afterdate'])){
                $q .= " AND (ADMDATE BETWEEN '".$_REQUEST['beforedate']."' AND '".$_REQUEST['afterdate']."')";
            }

            if(isset($_REQUEST['admfrom']) && !empty($_REQUEST['admfrom'])){
                $q .= " AND ADMFROM='".$_REQUEST['admfrom']."'";
            }

            if(isset($_REQUEST['current_location']) && !empty($_REQUEST['current_location'])){
              $q .= " AND current_location='".$_REQUEST['current_location']."'";
          }

            if(isset($_REQUEST['dischargedto']) && !empty($_REQUEST['dischargedto'])){
                $q .= " AND DISTO='".$_REQUEST['dischargedto']."'";
            }

            if(isset($_REQUEST['agerange1']) && !empty($_REQUEST['agerange1']) && !empty($_REQUEST['agerange2']) && !empty($_REQUEST['agerange2'])){
                $q .= " AND (age BETWEEN '".$_REQUEST['agerange1']."' AND '".$_REQUEST['agerange2']."')";
            }

            if(isset($_REQUEST['consultant']) && !empty($_REQUEST['consultant'])){
                $q .= " AND consultant_id='".$_REQUEST['consultant']."'";
            }

            if(isset($_REQUEST['gender']) && !empty($_REQUEST['gender'])){
                $q .= " AND gender='".$_REQUEST['gender']."'";
            }

            if(isset($_REQUEST['mortality']) && !empty($_REQUEST['mortality'])){
                $q .= " AND MORTALITY='".$_REQUEST['mortality']."'";
            }

            if(isset($_REQUEST['nationality']) && !empty($_REQUEST['nationality'])){
                $q .= " AND nationality='".$_REQUEST['nationality']."'";
            }

            if(isset($_REQUEST['delay']) && !empty($_REQUEST['delay'])){
                $q .= " AND delay='".$_REQUEST['delay']."'";
            }

            if(isset($_REQUEST['longterm']) && !empty($_REQUEST['longterm'])){
                $q .= " AND longterm='".$_REQUEST['longterm']."'";
            }
            
            if(isset($_REQUEST['only']) && !empty($_REQUEST['only'])){
                $q .= " AND DISDATE IS NOT NULL";
            }

            if(isset($_REQUEST['admissiondiagnosis']) && !empty($_REQUEST['admissiondiagnosis']) && is_array($_REQUEST['admissiondiagnosis'])){
               
                // echo"ahmed";
                $ddx = $_REQUEST['admissiondiagnosis'];
                $ddxcodewd= json_encode($ddx);
                if ($_REQUEST['dxcondition'] == 'or'){
                        //  $q .= " AND JSON_OVERLAPS(admissiondiagnosis, '$ddxcodewd')";
                      $q .= " AND (";
                      $numItems = count($ddx);
                      $i = 0;
                      foreach ($ddx as $d){
                        if(++$i === $numItems) {
                        $q .= "JSON_CONTAINS(admissiondiagnosis, '[\"$d\"]')";
                        }else{
                        $q .= "JSON_CONTAINS(admissiondiagnosis, '[\"$d\"]') OR ";
                        }
                      }
                      $q .= ")";
                }elseif ($_REQUEST['dxcondition'] == 'and'){
                      $q .= " AND JSON_CONTAINS(admissiondiagnosis, '$ddxcodewd')";
                }
            }
// echo $q;

// Count total results
$count_q = "SELECT COUNT(*) as total FROM ($q) as subquery";
$count_result = $mysqli->query($count_q);
$total_results = $count_result->fetch_assoc()['total'];

// Add LIMIT and OFFSET for pagination
$q .= " LIMIT 250";

$result1 = $mysqli->query($q);
$searchresults = $result1 -> fetch_all(MYSQLI_ASSOC);
// var_dump ($searchresults);

// include("export-results-exel.php");

?>
<div id="results-container">
<?php
echo"
<div id='messsssage' class='card'>
<div class='card-header'>
Results Found: ".$total_results." and showing ".count($searchresults)."
</div>";

echo"
<div class='card-body'>
                <div class='row'>";
                               
if ($searchresults){
  // Fetch all ICD-10 codes and names
$icd10_result = $mysqli->query("SELECT id, name FROM icd10");
$icd10_names = [];
while ($row = $icd10_result->fetch_assoc()) {
    $icd10_names[$row['id']] = $row['name'];
}

$memberData = [];
$memberQuery = "SELECT * FROM members";
$memberResult = $mysqli->query($memberQuery);

while ($row = $memberResult->fetch_assoc()) {
    $memberData[$row['member_id']] = $row;
}

}
                                        
                                                     foreach($searchresults as $s){

                                                    


                                                      $formationSQL = "SELECT * FROM picupatients WHERE DISDATE + INTERVAL 3 DAY >='".$s['ADMDATE']."' AND ID <'".$s['ID']."' AND MRN='".$s['MRN']."' AND (trans_discharge = 'discharge from ICU' or trans_discharge='discharge from ward' or trans_discharge IS NULL) LIMIT 1";
                                                      $result1 = $mysqli->query($formationSQL);
                                                        $recentadmission = $result1 -> fetch_all(MYSQLI_ASSOC);
                                                        // var_dump($recentadmission);
                                                                  // searching for readmissions
                                                        /// only show recent admissions
                                                        if(isset($_REQUEST['readmission']) && !empty($_REQUEST['readmission'])){
                                                          if($recentadmission){
                                                    


                                                      $decodedadmissiondx=json_decode($s['admissiondiagnosis']);
                                                      if ($decodedadmissiondx){
                                                      $tb_patient=array_intersect($decodedadmissiondx,$tb_list);
                                                    }else{
                                                      $tb_patient=array();
                                                    }

                                                  
                                                      $today = date("Y-m-d");
                                                      $today1 = strtotime(date("Y-m-d"));
                                                      // $datediff = $today - strtotime($s['ADMDATE']);
                                                      // $LOS = $today->date_diff($s['ADMDATE'])->format("%a");
                                                      if ($s['med_DISDATE']){
                                                        $timeDiff = abs(strtotime($s['med_DISDATE']) - strtotime($s['ADMDATE']));

                                                        $LOS = $timeDiff/86400;  // 86400 seconds in one day
                                                      }else{
                                                          
                                                      $timeDiff = abs($today1 - strtotime($s['ADMDATE']));

                                                      $LOS = $timeDiff/86400;  // 86400 seconds in one day

                                                    }
                                                      // $LOS= round($datediff / (60 * 60 * 24));
                                                      

                                                      
                                                    echo"  
                                                    <div class='col-sm-4'>
                                                    <div class='eachrow card'  id='row".$s['ID']."'>
                                                    
                                                    <div style='   margin: 1%; text-align: center;display: inline; ' class='eachcol bed card-header'  scope='row' >";
                                                   
                                                    // var_dump($decodedadmissiondx);  
                                                    // var_dump($tb_list);
                                                    
                                                    // var_dump($result);
                                                        if ($s['newassign']=='1' AND $s['assigned_on']==$today){
                                                          echo"<p style='color: red;display: contents;'><strong>New</strong></p>";
                                                          
                                                           }
                                                           if ($recentadmission ){
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
                                                      // 1> if discharge still in

                                              if($s['med_DISDATE'] && $s['DISDATE'] == NULL && $s['trans_discharge'] == NULL) {
                                                echo"
                                                <label style='text-align: center;margin-bottom: 0px;'>Discharge Still in</label>";
                                                      
                                                      ///2> if discharge is complete
                                              } elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'discharge from ward'){
                                                echo"
                                                <label style='text-align: center;margin-bottom: 0px;'>Discharged</label>";
                                            
                                              // 4> transfer to other service within internal medicine department 
                                        }  elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'transfer to other speciality'){
                                            echo"
                                            <label style='text-align: center;margin-bottom: 0px;'>Transferred Intradepartment</label>";
                                         // 5> transfer to other service outside internal medicine department 
                                    }  elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'other transfer'){
                                        if ($s['DISTO'] == 'Intensive Care (ICU)'){
                                            echo"
                                            <label style='text-align: center;margin-bottom: 0px;'>Transferred to ICU</label>";
                                        }else{
                                        echo"
                                        <label style='text-align: center;margin-bottom: 0px;'>Transferred out department</label>";
                                        }
                                    
                                    // 6> Discharged from ICU
                                } elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'discharge from ICU'){
                                    echo" <label style='text-align: center;margin-bottom: 0px;'>Discharged from ICU</label>";
                                    
                                // 7> Discharged from ICU
                            } elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'Transfer from ICU'){
                                echo" <label style='text-align: center;margin-bottom: 0px;'>Transferred back from ICU</label>";
                                
                            }else{

                                                      echo"
                                                <label style='text-align: center;margin-bottom: 0px;'>Admitted In ".$s['current_location']." Bed #</label>

                                                      <input disabled class='txtdata' name='bed' placeholder='Bed Number' value='".$s['BED']."' style='text-align: center;' >
                                                      <input disabled class='txtdata' type='hidden' name='id' id='id' value='".$s['ID']."' style='text-align: center;width: 85%;' >";
                                                      }

                                                      echo"
                                                        </div>
                                                      
                                                      <div style=' margin: 1%; ' class='eachcol mrn' >
                                                      <table style='width: 100%;'>
                                                      <tr>
                                                      <td style='width: 50%; border-right: solid 0.5px;'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>MRN</label>
                                                      <p style='text-align: center;'>".$s['MRN']."</p>
                                                      </td>
                                                      <td style='width: 50%;'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>Age</label>
                                                      <p style='text-align: center;'>".$s['age']."</p>
                                                    </td>
                                                      </tr>
                                                      </table>
                                                      </div>

                                                      <div style=' margin: 1%; ' class='eachcol name'>
                                                      <table style='width: 100%;'>
                                                      <tr>
                                                      <td style='width: 50%; border-right: solid 0.5px;'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>Patient Name</label>
                                                      <p style='text-align: center;'>".$s['PNAME']."</p>
                                                      </td>
                                                      <td style='width: 50%;'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>Gender</label>
                                                      <p style='text-align: center;'>".$s['gender']."</p>
                                                    </td>
                                                      </tr>
                                                      </table>
                                                      </div>
                                                
                                                
                                                 <div style=' margin: 1%; ' class='eachcol admfrom'  scope='row' >
                                                      <table style='width: 100%;'>
                                                      <tr>
                                                      <td style='width: 50%; border-right: solid 0.5px;'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>Admission From</label>
                                                      <p style='text-align: center;margin-bottom: 0px;'>".$s['ADMFROM']."</p>
                                                      </td>
                                                      <td style='width: 50%;'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>Admitted By</label>";
                                                      $mem_id= $s['admitted_by'];
                                                      if (isset($memberData[$mem_id])) {
                                                        $doctor = $memberData[$mem_id];
                                                      }
                                                      echo"
                                                      <p style='text-align: center;margin-bottom: 0px;'>".$doctor['full_name']."</p>
                                                      </td>
                                                      </tr>
                                                      </table>
                                                      </div>

                                                      <div style=' margin: 1%; ' class='eachcol admdate'  scope='row' >
                                                      <label style='text-align: center;margin-bottom: 0px;'>Admission Date</label>
                                                      <p style='text-align: center;'>".$s['ADMDATE']."</p>
                                                      </div>
                                                      <div style=' margin: 1%; ' class='eachcol admdate'  scope='row' >
                                                      <label style='text-align: center;margin-bottom: 0px;'>Nationality</label>";

                                                      $nation = $s['nationality'];
                                                      
                                                      echo"
                                                      <p style='text-align: center;'>".$nation."</p>
                                                      </div>";

                                                      if($s['current_location'] == 'ICU'){
                                                        echo"   <div style='  margin: 1%; background: royalblue;color: white;' class='eachcol'    scope='row' >
                                                        <p style='text-align: center;margin-bottom: 0px;'>ICU patient</p>
                                                       
                                                                </div>
                                                                ";

                                                      } else{

                                                      
                                                                if ($LOS < $shortlos){
                                                                  echo"   <div style=' margin: 1%; background: #d4edda;' class='eachcol admdate'    scope='row' >";
                                                                } elseif   ($LOS > $longlos){
                                                                  echo"   <div style=' margin: 1%; background: #f8d7da;' class='eachcol admdate'   scope='row' >";
                                                                }elseif   ($LOS >= $shortlos){
                                                                  echo"  <div style=' margin: 1%; background: #fff3cd;' class='eachcol admdate'    scope='row' >";
                                                                }

                                                                echo"
                                                                <label style='text-align: center;margin-bottom: 0px;'>Duration of Admission: ".$LOS." Days</label>
                                                            
                                                                </div>
                                                                ";
                                                              }
                                                              echo"
                                                 <div style=' margin: 1%; ' class='eachcol admissiondiagnosis'>
                                                 <label style='text-align: center;margin-bottom: 0px;'>Diagnosis</label>
                                                 <ul style='list-style-position: inside;margin: 1% 0% 1%;'>
                                                 ";
                                                 if (is_array($decodedadmissiondx)) {
                                                  foreach ($decodedadmissiondx as $value) {
                                                      if (isset($icd10_names[$value])) {
                                                          echo '<li>' . $icd10_names[$value] . '</li>';
                                                      }
                                                  }
                                              }
                                                 echo"
                                              </ul></div>
                                              <div style=' margin: 1%; '>
                                             
                                              <label style='text-align: center;margin-bottom: 0px;'>Primary Consultant</label>";
                                              $con_id= $s['consultant_id'];
                                              if (isset($memberData[$con_id])) {
                                                $doctor1 = $memberData[$con_id];
                                              }
                                                if ($doctor1){
                                              echo"
                                              <p style='text-align: center;margin-bottom: 0px;'>".$doctor1['full_name']."</p>";
                                            }else{
                                                echo"  <p style='text-align: center;margin-bottom: 0px;'>Not Assigned Yet</p>";
                                            }
                                              
                                            echo" </div>

                                            ";

                                              // discharge and transfer part
                                              // 1> if discharge still in
                                              if($s['med_DISDATE'] && $s['DISDATE'] == NULL && $s['trans_discharge'] == NULL) {
                                                echo"
                                                <div style=' margin: 1%; border-top: solid 0.5px;'>
                                             
                                                <table style='width: 100%;'>
                                                <tr>
                                                <td style='width: 50%; border-right: solid 0.5px;'>
                                                <label style='text-align: center;margin-bottom: 0px;'>Discharge Status</label>
                                                <p style='text-align: center;margin-bottom: 0px;'>".$s['MORTALITY']."</p>
                                                </td>
                                                <td style='width: 50%;'>
                                                <label style='text-align: center;margin-bottom: 0px;'>Discharged By</label>";
                                                $mem_id2= $s['trans_discharge_by'];
                                                if (isset($memberData[$mem_id2])) {
                                                  $doctor2 = $memberData[$mem_id2];
                                                }
                                                echo"
                                                <p style='text-align: center;margin-bottom: 0px;'>".$doctor2['full_name']."</p>
                                                </td>
                                                </tr>
                                                </table>
                                                </div>
                                                <div style=' margin: 1%;'>
                                                <table style='width: 100%;'>
                                                <tr>
                                                <td style='width: 50%; border-right: solid 0.5px;'>
                                                <label style='text-align: center;margin-bottom: 0px;'>Discharge Date</label>
                                                <p style='text-align: center;margin-bottom: 0px;'>".$s['med_DISDATE']."</p>
                                                </td>
                                                <td style='width: 50%;'>
                                                <label style='text-align: center;margin-bottom: 0px; color:red;'>Delay Due To</label>
                                                
                                                <p style='text-align: center;margin-bottom: 0px;'>".$s['delay']."</p>
                                                </td>
                                                </tr>
                                                </table>
                                                <label style='text-align: center;margin-bottom: 0px; color:red;'>File Not Closed Yet</label>
                                                </div>";
                                                ///2> if discharge is complete
                                              } elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'discharge from ward'){
                                                echo"
                                                <div style=' margin: 1%; border-top: solid 0.5px;'>
                                             
                                                <table style='width: 100%;'>
                                                <tr>
                                                <td style='width: 50%; border-right: solid 0.5px;'>
                                                <label style='text-align: center;margin-bottom: 0px;'>Discharge Status</label>
                                                <p style='text-align: center;margin-bottom: 0px;'>".$s['MORTALITY']."</p>
                                                </td>
                                                <td style='width: 50%;'>
                                                <label style='text-align: center;margin-bottom: 0px;'>Discharged By</label>";
                                                $mem_id2= $s['trans_discharge_by'];
                                                if (isset($memberData[$mem_id2])) {
                                                  $doctor2 = $memberData[$mem_id2];
                                                }
                                                echo"
                                                <p style='text-align: center;margin-bottom: 0px;'>".$doctor2['full_name']."</p>
                                                </td>
                                                </tr>
                                                </table>
                                                </div>
                                              <div style=' margin: 1%;'>
                                             
                                              <table style='width: 100%;'>
                                              <tr>
                                              <td style='width: 50%; border-right: solid 0.5px;'>
                                              <label style='text-align: center;margin-bottom: 0px;'>Discharge Date</label>
                                              <p style='text-align: center;margin-bottom: 0px;'>".$s['med_DISDATE']."</p>
                                              </td>
                                              <td style='width: 50%;'>
                                              <label style='text-align: center;margin-bottom: 0px;'>Discharged To</label>
                                              <p style='text-align: center;margin-bottom: 0px;'>".$s['DISTO']."</p>
                                              </td>
                                              </tr>
                                              </table>
                                              </div>";

                                                    // 3> if discharge is complete and there is delay
                                              if ($s['delay']){
                                                  echo"
                                              <div style=' margin: 1%;'>
                                             
                                              <table style='width: 100%;'>
                                              <tr>
                                              <td style='width: 50%; border-right: solid 0.5px;'>
                                              <label style='text-align: center;margin-bottom: 0px; color:red;'>File Closed At</label>
                                              <p style='text-align: center;margin-bottom: 0px;'>".$s['DISDATE']."</p>
                                              </td>
                                              <td style='width: 50%;'>
                                              <label style='text-align: center;margin-bottom: 0px; color:red;'>Delay Due To</label>
                                              
                                              <p style='text-align: center;margin-bottom: 0px;'>".$s['delay']."</p>
                                              </td>
                                              </tr>
                                              </table>
                                              </div>";
                                            }
                                            // 4> transfer to other service within internal medicine department 
                                        }  elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'transfer to other speciality'){
                                            echo"
                                            <div style=' margin: 1%; border-top: solid 0.5px;'>
                                            <label style='text-align: center;margin-bottom: 0px;'>Transfer to Other Specilaity</label>
                                            <table style='width: 100%;'>
                                            <tr>
                                            <td style='width: 50%; border-right: solid 0.5px;'>
                                            <label style='text-align: center;margin-bottom: 0px;'>Transfer Status</label>
                                            <p style='text-align: center;margin-bottom: 0px;'>".$s['MORTALITY']."</p>
                                            </td>
                                            <td style='width: 50%;'>
                                            <label style='text-align: center;margin-bottom: 0px;'>Transfer By</label>";
                                            $mem_id2= $s['trans_discharge_by'];
                                            if (isset($memberData[$mem_id2])) {
                                              $doctor2 = $memberData[$mem_id2];
                                            }
                                            echo"
                                            <p style='text-align: center;margin-bottom: 0px;'>".$doctor2['full_name']."</p>
                                            </td>
                                            </tr>
                                            </table>
                                            </div>
                                          <div style=' margin: 1%;'>
                                         
                                          <table style='width: 100%;'>
                                          <tr>
                                          <td style='width: 50%; border-right: solid 0.5px;'>
                                          <label style='text-align: center;margin-bottom: 0px;'>Tramsfer At</label>
                                          <p style='text-align: center;margin-bottom: 0px;'>".$s['med_DISDATE']."</p>
                                          </td>
                                          <td style='width: 50%;'>
                                          <label style='text-align: center;margin-bottom: 0px;'>Transfer To</label>
                                          <p style='text-align: center;margin-bottom: 0px;'>".$s['DISTO']."</p>
                                          </td>
                                          </tr>
                                          </table>
                                          </div>";
                                          // 5> transfer to other service outside internal medicine department 
                                        } elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'other transfer'){
                                            echo"
                                            <div style=' margin: 1%; border-top: solid 0.5px;'>";
                                            if ($s['DISTO'] == 'Intensive Care (ICU)'){
                                                echo"
                                                <label style='text-align: center;margin-bottom: 0px;'>Transferred to ICU</label>";
                                            }else{
                                            echo"
                                            <label style='text-align: center;margin-bottom: 0px;'>Transfer to Other Specilaity</label>";
                                            }
                                            echo"
                                            
                                            <table style='width: 100%;'>
                                            <tr>
                                            <td style='width: 50%; border-right: solid 0.5px;'>
                                            <label style='text-align: center;margin-bottom: 0px;'>Transfer Status</label>
                                            <p style='text-align: center;margin-bottom: 0px;'>".$s['MORTALITY']."</p>
                                            </td>
                                            <td style='width: 50%;'>
                                            <label style='text-align: center;margin-bottom: 0px;'>Transfer By</label>";
                                            $mem_id2= $s['trans_discharge_by'];
                                            if (isset($memberData[$mem_id2])) {
                                              $doctor2 = $memberData[$mem_id2];
                                            }
                                            echo"
                                            <p style='text-align: center;margin-bottom: 0px;'>".$doctor2['full_name']."</p>
                                            </td>
                                            </tr>
                                            </table>
                                            </div>
                                          <div style=' margin: 1%;'>
                                         
                                          <table style='width: 100%;'>
                                          <tr>
                                          <td style='width: 50%; border-right: solid 0.5px;'>
                                          <label style='text-align: center;margin-bottom: 0px;'>Tramsfer At</label>
                                          <p style='text-align: center;margin-bottom: 0px;'>".$s['med_DISDATE']."</p>
                                          </td>
                                          <td style='width: 50%;'>
                                          <label style='text-align: center;margin-bottom: 0px;'>Transfer To</label>
                                          <p style='text-align: center;margin-bottom: 0px;'>".$s['DISTO']."</p>
                                          </td>
                                          </tr>
                                          </table>
                                          </div>";
                                        // 6> Discharged from ICU
                                        } elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'discharge from ICU'){
                                            echo"
                                            <div style=' margin: 1%; border-top: solid 0.5px;'>
                                            
                                            <label style='text-align: center;margin-bottom: 0px;'>Discharged from ICU</label>
                                            
                                            
                                            <table style='width: 100%;'>
                                            <tr>
                                            <td style='width: 50%; border-right: solid 0.5px;'>
                                            <label style='text-align: center;margin-bottom: 0px;'>Discharge Status</label>
                                            <p style='text-align: center;margin-bottom: 0px;'>".$s['MORTALITY']."</p>
                                            </td>
                                            <td style='width: 50%;'>
                                            <label style='text-align: center;margin-bottom: 0px;'>Discharged By</label>";
                                            $mem_id2= $s['trans_discharge_by'];
                                            if (isset($memberData[$mem_id2])) {
                                              $doctor2 = $memberData[$mem_id2];
                                            }
                                            echo"
                                            <p style='text-align: center;margin-bottom: 0px;'>".$doctor2['full_name']."</p>
                                            </td>
                                            </tr>
                                            </table>
                                            </div>
                                          <div style=' margin: 1%;'>
                                         
                                          <table style='width: 100%;'>
                                          <tr>
                                          <td style='width: 50%; border-right: solid 0.5px;'>
                                          <label style='text-align: center;margin-bottom: 0px;'>Discharged At</label>
                                          <p style='text-align: center;margin-bottom: 0px;'>".$s['med_DISDATE']."</p>
                                          </td>
                                          <td style='width: 50%;'>
                                          <label style='text-align: center;margin-bottom: 0px;'>Discharged To</label>
                                          <p style='text-align: center;margin-bottom: 0px;'>".$s['DISTO']."</p>
                                          </td>
                                          </tr>
                                          </table>
                                          </div>";
                                        // 7> Discharged from ICU
                            } elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'Transfer from ICU'){
                                echo"
                                <div style=' margin: 1%; border-top: solid 0.5px;'>
                                
                                <label style='text-align: center;margin-bottom: 0px;'>Transferred Back from ICU</label>
                                
                                
                                <table style='width: 100%;'>
                                <tr>
                                <td style='width: 50%; border-right: solid 0.5px;'>
                                <label style='text-align: center;margin-bottom: 0px;'>Transfer Status</label>
                                <p style='text-align: center;margin-bottom: 0px;'>".$s['MORTALITY']."</p>
                                </td>
                                <td style='width: 50%;'>
                                <label style='text-align: center;margin-bottom: 0px;'>Transfer By</label>";
                                $mem_id2= $s['trans_discharge_by'];
                                if (isset($memberData[$mem_id2])) {
                                  $doctor2 = $memberData[$mem_id2];
                                }

                                echo"
                                <p style='text-align: center;margin-bottom: 0px;'>".$doctor2['full_name']."</p>
                                </td>
                                </tr>
                                </table>
                                </div>
                              <div style=' margin: 1%;'>
                             
                              <table style='width: 100%;'>
                              <tr>
                              <td style='width: 50%; border-right: solid 0.5px;'>
                              <label style='text-align: center;margin-bottom: 0px;'>Transfer At</label>
                              <p style='text-align: center;margin-bottom: 0px;'>".$s['med_DISDATE']."</p>
                              </td>
                              <td style='width: 50%;'>
                              <label style='text-align: center;margin-bottom: 0px;'>Transfer To</label>
                              <p style='text-align: center;margin-bottom: 0px;'>".$s['DISTO']."</p>
                              </td>
                              </tr>
                              </table>
                              </div>";
                            
                }
                if ($_SESSION['position'] == '0'){
                  echo "<a class='btn btn-info' href='#modify_modal' data-book-id='".$s['ID']."' data-bs-toggle='modal'  style='color: aliceblue;line-height: 2;margin-top: 3%;padding: 0px 10%;width: 100%;'>Modify</a>";
                  };
                                            echo"
                                                   </div >
                                                   </div >
                                                      ";
                                                        
                                                     } else{ }


                                                        }else{
                                                          //// if not searching for radmissions


                                                      $decodedadmissiondx=json_decode($s['admissiondiagnosis']);
                                                      if ($decodedadmissiondx){
                                                      $tb_patient=array_intersect($decodedadmissiondx,$tb_list);
                                                    }else{
                                                      $tb_patient=array();
                                                    }

                                                  
                                                      $today = date("Y-m-d");
                                                      $today1 = strtotime(date("Y-m-d"));
                                                      // $datediff = $today - strtotime($s['ADMDATE']);
                                                      // $LOS = $today->date_diff($s['ADMDATE'])->format("%a");
                                                      if ($s['med_DISDATE']){
                                                        $timeDiff = abs(strtotime($s['med_DISDATE']) - strtotime($s['ADMDATE']));

                                                        $LOS = $timeDiff/86400;  // 86400 seconds in one day
                                                      }else{
                                                          
                                                      $timeDiff = abs($today1 - strtotime($s['ADMDATE']));

                                                      $LOS = $timeDiff/86400;  // 86400 seconds in one day

                                                    }
                                                      // $LOS= round($datediff / (60 * 60 * 24));
                                                      

                                                      
                                                    echo"  
                                                    <div class='col-sm-4'>
                                                    <div class='eachrow card'  id='row".$s['ID']."'>
                                                    
                                                    <div style='   margin: 1%; text-align: center;display: inline; ' class='eachcol bed card-header'  scope='row' >";
                                                   
                                                    // var_dump($decodedadmissiondx);  
                                                    // var_dump($tb_list);
                                                    
                                                    // var_dump($result);
                                                        if ($s['newassign']=='1' AND $s['assigned_on']==$today){
                                                          echo"<p style='color: red;display: contents;'><strong>New</strong></p>";
                                                          
                                                           }
                                                           if ($recentadmission ){
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
                                                      // 1> if discharge still in

                                              if($s['med_DISDATE'] && $s['DISDATE'] == NULL && $s['trans_discharge'] == NULL) {
                                                echo"
                                                <label style='text-align: center;margin-bottom: 0px;'>Discharge Still in</label>";
                                                      
                                                      ///2> if discharge is complete
                                              } elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'discharge from ward'){
                                                echo"
                                                <label style='text-align: center;margin-bottom: 0px;'>Discharged</label>";
                                            
                                              // 4> transfer to other service within internal medicine department 
                                        }  elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'transfer to other speciality'){
                                            echo"
                                            <label style='text-align: center;margin-bottom: 0px;'>Transferred Intradepartment</label>";
                                         // 5> transfer to other service outside internal medicine department 
                                    }  elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'other transfer'){
                                        if ($s['DISTO'] == 'Intensive Care (ICU)'){
                                            echo"
                                            <label style='text-align: center;margin-bottom: 0px;'>Transferred to ICU</label>";
                                        }else{
                                        echo"
                                        <label style='text-align: center;margin-bottom: 0px;'>Transferred out department</label>";
                                        }
                                    
                                    // 6> Discharged from ICU
                                } elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'discharge from ICU'){
                                    echo" <label style='text-align: center;margin-bottom: 0px;'>Discharged from ICU</label>";
                                    
                                // 7> Discharged from ICU
                            } elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'Transfer from ICU'){
                                echo" <label style='text-align: center;margin-bottom: 0px;'>Transferred back from ICU</label>";
                                
                            }else{

                                                      echo"
                                                <label style='text-align: center;margin-bottom: 0px;'>Admitted In ".$s['current_location']." Bed #</label>

                                                      <input disabled class='txtdata' name='bed' placeholder='Bed Number' value='".$s['BED']."' style='text-align: center;' >
                                                      <input disabled class='txtdata' type='hidden' name='id' id='id' value='".$s['ID']."' style='text-align: center;width: 85%;' >";
                                                      }

                                                      echo"
                                                        </div>
                                                      
                                                      <div style=' margin: 1%; ' class='eachcol mrn' >
                                                      <table style='width: 100%;'>
                                                      <tr>
                                                      <td style='width: 50%; border-right: solid 0.5px;'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>MRN</label>
                                                      <p style='text-align: center;'>".$s['MRN']."</p>
                                                      </td>
                                                      <td style='width: 50%;'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>Age</label>
                                                      <p style='text-align: center;'>".$s['age']."</p>
                                                    </td>
                                                      </tr>
                                                      </table>
                                                      </div>

                                                      <div style=' margin: 1%; ' class='eachcol name'>
                                                      <table style='width: 100%;'>
                                                      <tr>
                                                      <td style='width: 50%; border-right: solid 0.5px;'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>Patient Name</label>
                                                      <p style='text-align: center;'>".$s['PNAME']."</p>
                                                      </td>
                                                      <td style='width: 50%;'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>Gender</label>
                                                      <p style='text-align: center;'>".$s['gender']."</p>
                                                    </td>
                                                      </tr>
                                                      </table>
                                                      </div>
                                                
                                                
                                                 <div style=' margin: 1%; ' class='eachcol admfrom'  scope='row' >
                                                      <table style='width: 100%;'>
                                                      <tr>
                                                      <td style='width: 50%; border-right: solid 0.5px;'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>Admission From</label>
                                                      <p style='text-align: center;margin-bottom: 0px;'>".$s['ADMFROM']."</p>
                                                      </td>
                                                      <td style='width: 50%;'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>Admitted By</label>";
                                                      $mem_id= $s['admitted_by'];
                                                      if (isset($memberData[$mem_id])) {
                                                        $doctor = $memberData[$mem_id];
                                                      }
                                                      echo"
                                                      <p style='text-align: center;margin-bottom: 0px;'>".$doctor['full_name']."</p>
                                                      </td>
                                                      </tr>
                                                      </table>
                                                      </div>

                                                      <div style=' margin: 1%; ' class='eachcol admdate'  scope='row' >
                                                      <label style='text-align: center;margin-bottom: 0px;'>Admission Date</label>
                                                      <p style='text-align: center;'>".$s['ADMDATE']."</p>
                                                      </div>
                                                      <div style=' margin: 1%; ' class='eachcol admdate'  scope='row' >
                                                      <label style='text-align: center;margin-bottom: 0px;'>Nationality</label>";

                                                      $nation = $s['nationality'];
                                                      
                                                      echo"
                                                      <p style='text-align: center;'>".$nation."</p>
                                                      </div>";

                                                      if($s['current_location'] == 'ICU'){
                                                        echo"   <div style='  margin: 1%; background: royalblue;color: white;' class='eachcol'    scope='row' >
                                                        <p style='text-align: center;margin-bottom: 0px;'>ICU patient</p>
                                                       
                                                                </div>
                                                                ";

                                                      } else{

                                                      
                                                                if ($LOS < $shortlos){
                                                                  echo"   <div style=' margin: 1%; background: #d4edda;' class='eachcol admdate'    scope='row' >";
                                                                } elseif   ($LOS > $longlos){
                                                                  echo"   <div style=' margin: 1%; background: #f8d7da;' class='eachcol admdate'   scope='row' >";
                                                                }elseif   ($LOS >= $shortlos){
                                                                  echo"  <div style=' margin: 1%; background: #fff3cd;' class='eachcol admdate'    scope='row' >";
                                                                }

                                                                echo"
                                                                <label style='text-align: center;margin-bottom: 0px;'>Duration of Admission: ".$LOS." Days</label>
                                                            
                                                                </div>
                                                                ";
                                                              }
                                                              echo"
                                                 <div style=' margin: 1%; ' class='eachcol admissiondiagnosis'>
                                                 <label style='text-align: center;margin-bottom: 0px;'>Diagnosis</label>
                                                 <ul style='list-style-position: inside;margin: 1% 0% 1%;'>
                                                 ";
                                           
                                                 if (is_array($decodedadmissiondx)) {
                                                  foreach ($decodedadmissiondx as $value) {
                                                      if (isset($icd10_names[$value])) {
                                                          echo '<li>' . $icd10_names[$value] . '</li>';
                                                      }
                                                  }
                                              }
                                                 echo"
                                              </ul></div>
                                              <div style=' margin: 1%; '>
                                             
                                              <label style='text-align: center;margin-bottom: 0px;'>Primary Consultant</label>";
                                              $con_id= $s['consultant_id'];
                                              if (isset($memberData[$con_id])) {
                                                $doctor1 = $memberData[$con_id];
                                              }
                                                if ($doctor1){
                                              echo"
                                              <p style='text-align: center;margin-bottom: 0px;'>".$doctor1['full_name']."</p>";
                                            }else{
                                                echo"  <p style='text-align: center;margin-bottom: 0px;'>Not Assigned Yet</p>";
                                            }
                                              
                                            echo" </div>

                                            ";

                                              // discharge and transfer part
                                              // 1> if discharge still in
                                              if($s['med_DISDATE'] && $s['DISDATE'] == NULL && $s['trans_discharge'] == NULL) {
                                                echo"
                                                <div style=' margin: 1%; border-top: solid 0.5px;'>
                                             
                                                <table style='width: 100%;'>
                                                <tr>
                                                <td style='width: 50%; border-right: solid 0.5px;'>
                                                <label style='text-align: center;margin-bottom: 0px;'>Discharge Status</label>
                                                <p style='text-align: center;margin-bottom: 0px;'>".$s['MORTALITY']."</p>
                                                </td>
                                                <td style='width: 50%;'>
                                                <label style='text-align: center;margin-bottom: 0px;'>Discharged By</label>";
                                                $mem_id2= $s['trans_discharge_by'];
                                                if (isset($memberData[$mem_id2])) {
                                                  $doctor2 = $memberData[$mem_id2];
                                                }
                                                echo"
                                                <p style='text-align: center;margin-bottom: 0px;'>".$doctor2['full_name']."</p>
                                                </td>
                                                </tr>
                                                </table>
                                                </div>
                                                <div style=' margin: 1%;'>
                                                <table style='width: 100%;'>
                                                <tr>
                                                <td style='width: 50%; border-right: solid 0.5px;'>
                                                <label style='text-align: center;margin-bottom: 0px;'>Discharge Date</label>
                                                <p style='text-align: center;margin-bottom: 0px;'>".$s['med_DISDATE']."</p>
                                                </td>
                                                <td style='width: 50%;'>
                                                <label style='text-align: center;margin-bottom: 0px; color:red;'>Delay Due To</label>
                                                
                                                <p style='text-align: center;margin-bottom: 0px;'>".$s['delay']."</p>
                                                </td>
                                                </tr>
                                                </table>
                                                <label style='text-align: center;margin-bottom: 0px; color:red;'>File Not Closed Yet</label>
                                                </div>";
                                                ///2> if discharge is complete
                                              } elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'discharge from ward'){
                                                echo"
                                                <div style=' margin: 1%; border-top: solid 0.5px;'>
                                             
                                                <table style='width: 100%;'>
                                                <tr>
                                                <td style='width: 50%; border-right: solid 0.5px;'>
                                                <label style='text-align: center;margin-bottom: 0px;'>Discharge Status</label>
                                                <p style='text-align: center;margin-bottom: 0px;'>".$s['MORTALITY']."</p>
                                                </td>
                                                <td style='width: 50%;'>
                                                <label style='text-align: center;margin-bottom: 0px;'>Discharged By</label>";
                                                $mem_id2= $s['trans_discharge_by'];
                                                if (isset($memberData[$mem_id2])) {
                                                  $doctor2 = $memberData[$mem_id2];
                                                }
                                                echo"
                                                <p style='text-align: center;margin-bottom: 0px;'>".$doctor2['full_name']."</p>
                                                </td>
                                                </tr>
                                                </table>
                                                </div>
                                              <div style=' margin: 1%;'>
                                             
                                              <table style='width: 100%;'>
                                              <tr>
                                              <td style='width: 50%; border-right: solid 0.5px;'>
                                              <label style='text-align: center;margin-bottom: 0px;'>Discharge Date</label>
                                              <p style='text-align: center;margin-bottom: 0px;'>".$s['med_DISDATE']."</p>
                                              </td>
                                              <td style='width: 50%;'>
                                              <label style='text-align: center;margin-bottom: 0px;'>Discharged To</label>
                                              <p style='text-align: center;margin-bottom: 0px;'>".$s['DISTO']."</p>
                                              </td>
                                              </tr>
                                              </table>
                                              </div>";

                                                    // 3> if discharge is complete and there is delay
                                              if ($s['delay']){
                                                  echo"
                                              <div style=' margin: 1%;'>
                                             
                                              <table style='width: 100%;'>
                                              <tr>
                                              <td style='width: 50%; border-right: solid 0.5px;'>
                                              <label style='text-align: center;margin-bottom: 0px; color:red;'>File Closed At</label>
                                              <p style='text-align: center;margin-bottom: 0px;'>".$s['DISDATE']."</p>
                                              </td>
                                              <td style='width: 50%;'>
                                              <label style='text-align: center;margin-bottom: 0px; color:red;'>Delay Due To</label>
                                              
                                              <p style='text-align: center;margin-bottom: 0px;'>".$s['delay']."</p>
                                              </td>
                                              </tr>
                                              </table>
                                              </div>";
                                            }
                                            // 4> transfer to other service within internal medicine department 
                                        }  elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'transfer to other speciality'){
                                            echo"
                                            <div style=' margin: 1%; border-top: solid 0.5px;'>
                                            <label style='text-align: center;margin-bottom: 0px;'>Transfer to Other Specilaity</label>
                                            <table style='width: 100%;'>
                                            <tr>
                                            <td style='width: 50%; border-right: solid 0.5px;'>
                                            <label style='text-align: center;margin-bottom: 0px;'>Transfer Status</label>
                                            <p style='text-align: center;margin-bottom: 0px;'>".$s['MORTALITY']."</p>
                                            </td>
                                            <td style='width: 50%;'>
                                            <label style='text-align: center;margin-bottom: 0px;'>Transfer By</label>";
                                            $mem_id2= $s['trans_discharge_by'];
                                            if (isset($memberData[$mem_id2])) {
                                              $doctor2 = $memberData[$mem_id2];
                                            }
                                            echo"
                                            <p style='text-align: center;margin-bottom: 0px;'>".$doctor2['full_name']."</p>
                                            </td>
                                            </tr>
                                            </table>
                                            </div>
                                          <div style=' margin: 1%;'>
                                         
                                          <table style='width: 100%;'>
                                          <tr>
                                          <td style='width: 50%; border-right: solid 0.5px;'>
                                          <label style='text-align: center;margin-bottom: 0px;'>Tramsfer At</label>
                                          <p style='text-align: center;margin-bottom: 0px;'>".$s['med_DISDATE']."</p>
                                          </td>
                                          <td style='width: 50%;'>
                                          <label style='text-align: center;margin-bottom: 0px;'>Transfer To</label>
                                          <p style='text-align: center;margin-bottom: 0px;'>".$s['DISTO']."</p>
                                          </td>
                                          </tr>
                                          </table>
                                          </div>";
                                          // 5> transfer to other service outside internal medicine department 
                                        } elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'other transfer'){
                                            echo"
                                            <div style=' margin: 1%; border-top: solid 0.5px;'>";
                                            if ($s['DISTO'] == 'Intensive Care (ICU)'){
                                                echo"
                                                <label style='text-align: center;margin-bottom: 0px;'>Transferred to ICU</label>";
                                            }else{
                                            echo"
                                            <label style='text-align: center;margin-bottom: 0px;'>Transfer to Other Specilaity</label>";
                                            }
                                            echo"
                                            
                                            <table style='width: 100%;'>
                                            <tr>
                                            <td style='width: 50%; border-right: solid 0.5px;'>
                                            <label style='text-align: center;margin-bottom: 0px;'>Transfer Status</label>
                                            <p style='text-align: center;margin-bottom: 0px;'>".$s['MORTALITY']."</p>
                                            </td>
                                            <td style='width: 50%;'>
                                            <label style='text-align: center;margin-bottom: 0px;'>Transfer By</label>";
                                            $mem_id2= $s['trans_discharge_by'];
                                            if (isset($memberData[$mem_id2])) {
                                              $doctor2 = $memberData[$mem_id2];
                                            }
                                            echo"
                                            <p style='text-align: center;margin-bottom: 0px;'>".$doctor2['full_name']."</p>
                                            </td>
                                            </tr>
                                            </table>
                                            </div>
                                          <div style=' margin: 1%;'>
                                         
                                          <table style='width: 100%;'>
                                          <tr>
                                          <td style='width: 50%; border-right: solid 0.5px;'>
                                          <label style='text-align: center;margin-bottom: 0px;'>Tramsfer At</label>
                                          <p style='text-align: center;margin-bottom: 0px;'>".$s['med_DISDATE']."</p>
                                          </td>
                                          <td style='width: 50%;'>
                                          <label style='text-align: center;margin-bottom: 0px;'>Transfer To</label>
                                          <p style='text-align: center;margin-bottom: 0px;'>".$s['DISTO']."</p>
                                          </td>
                                          </tr>
                                          </table>
                                          </div>";
                                        // 6> Discharged from ICU
                                        } elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'discharge from ICU'){
                                            echo"
                                            <div style=' margin: 1%; border-top: solid 0.5px;'>
                                            
                                            <label style='text-align: center;margin-bottom: 0px;'>Discharged from ICU</label>
                                            
                                            
                                            <table style='width: 100%;'>
                                            <tr>
                                            <td style='width: 50%; border-right: solid 0.5px;'>
                                            <label style='text-align: center;margin-bottom: 0px;'>Discharge Status</label>
                                            <p style='text-align: center;margin-bottom: 0px;'>".$s['MORTALITY']."</p>
                                            </td>
                                            <td style='width: 50%;'>
                                            <label style='text-align: center;margin-bottom: 0px;'>Discharged By</label>";
                                            $mem_id2= $s['trans_discharge_by'];
                                            if (isset($memberData[$mem_id2])) {
                                              $doctor2 = $memberData[$mem_id2];
                                            }
                                            echo"
                                            <p style='text-align: center;margin-bottom: 0px;'>".$doctor2['full_name']."</p>
                                            </td>
                                            </tr>
                                            </table>
                                            </div>
                                          <div style=' margin: 1%;'>
                                         
                                          <table style='width: 100%;'>
                                          <tr>
                                          <td style='width: 50%; border-right: solid 0.5px;'>
                                          <label style='text-align: center;margin-bottom: 0px;'>Discharged At</label>
                                          <p style='text-align: center;margin-bottom: 0px;'>".$s['med_DISDATE']."</p>
                                          </td>
                                          <td style='width: 50%;'>
                                          <label style='text-align: center;margin-bottom: 0px;'>Discharged To</label>
                                          <p style='text-align: center;margin-bottom: 0px;'>".$s['DISTO']."</p>
                                          </td>
                                          </tr>
                                          </table>
                                          </div>";
                                        // 7> Discharged from ICU
                            } elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'Transfer from ICU'){
                                echo"
                                <div style=' margin: 1%; border-top: solid 0.5px;'>
                                
                                <label style='text-align: center;margin-bottom: 0px;'>Transferred Back from ICU</label>
                                
                                
                                <table style='width: 100%;'>
                                <tr>
                                <td style='width: 50%; border-right: solid 0.5px;'>
                                <label style='text-align: center;margin-bottom: 0px;'>Transfer Status</label>
                                <p style='text-align: center;margin-bottom: 0px;'>".$s['MORTALITY']."</p>
                                </td>
                                <td style='width: 50%;'>
                                <label style='text-align: center;margin-bottom: 0px;'>Transfer By</label>";
                                $mem_id2= $s['trans_discharge_by'];
                                if (isset($memberData[$mem_id2])) {
                                  $doctor2 = $memberData[$mem_id2];
                                }
                                echo"
                                <p style='text-align: center;margin-bottom: 0px;'>".$doctor2['full_name']."</p>
                                </td>
                                </tr>
                                </table>
                                </div>
                              <div style=' margin: 1%;'>
                             
                              <table style='width: 100%;'>
                              <tr>
                              <td style='width: 50%; border-right: solid 0.5px;'>
                              <label style='text-align: center;margin-bottom: 0px;'>Transfer At</label>
                              <p style='text-align: center;margin-bottom: 0px;'>".$s['med_DISDATE']."</p>
                              </td>
                              <td style='width: 50%;'>
                              <label style='text-align: center;margin-bottom: 0px;'>Transfer To</label>
                              <p style='text-align: center;margin-bottom: 0px;'>".$s['DISTO']."</p>
                              </td>
                              </tr>
                              </table>
                              </div>";
                            
                }
                if ($_SESSION['position'] == '0'){
                  echo "<a class='btn btn-info' href='#modify_modal' data-book-id='".$s['ID']."' data-bs-toggle='modal'  style='color: aliceblue;line-height: 2;margin-top: 3%;padding: 0px 10%;width: 100%;'>Modify</a>";
                  };
                                            echo"
                                                   </div >
                                                   </div >
                                                      ";


                                                     }
                                                    }
                                                    
                                                    

                                                   
                              
             

        
            echo"        
    
                  
             
                </div>
                </div>
              </div>
  
";

?>

  


<!-- Modal patient details-->

    <div class="modal" id="modify_modal">
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

<script>
$('#modify_modal').on('show.bs.modal', function(e) {
 
 //  var bookId = $(e.relatedTarget).data('book-id');
  var bookId = $(e.relatedTarget).data('book-id');
 //  $(e.currentTarget).find('input[name="patientId"]').val(bookId);
 
 
  data = {bookId: bookId};
  $.post('registry/dmc-search-patient-details.php', data, function(data){
  $('#pdetailsdiv').html(data);
      
     });
 });
 </script>
 