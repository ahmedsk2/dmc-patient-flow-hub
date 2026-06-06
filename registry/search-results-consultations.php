<?php
require_once __DIR__ . '/../guard.php'; require_role([0]);
 
require ('../dbconnect.php');
date_default_timezone_set('Asia/Riyadh');

    $q = "SELECT * FROM consultations WHERE MRN IS NOT NULL";
            $conds = []; $types = ''; $params = [];

            if(isset($_REQUEST['mrn_consultation']) && !empty($_REQUEST['mrn_consultation'])){
                $conds[] = "MRN=?"; $types .= 's'; $params[] = $_REQUEST['mrn_consultation'];
            }

            if(isset($_REQUEST['beforedate_consultation']) && !empty($_REQUEST['beforedate_consultation']) && !empty($_REQUEST['afterdate_consultation']) && !empty($_REQUEST['afterdate_consultation'])){
                $conds[] = "(consultation_date BETWEEN ? AND ?)"; $types .= 'ss'; $params[] = $_REQUEST['beforedate_consultation']; $params[] = $_REQUEST['afterdate_consultation'];
            }

            if(isset($_REQUEST['consultation_from']) && !empty($_REQUEST['consultation_from'])){
                $conds[] = "consultation_from=?"; $types .= 's'; $params[] = $_REQUEST['consultation_from'];
                // echo $_REQUEST['consultation_from'];
            }

            if(isset($_REQUEST['consultation_to_service']) && !empty($_REQUEST['consultation_to_service'])){
                $conds[] = "consultation_to_service=?"; $types .= 's'; $params[] = $_REQUEST['consultation_to_service'];
            }

            if(isset($_REQUEST['agerange1_consultation']) && !empty($_REQUEST['agerange1_consultation']) && !empty($_REQUEST['agerange2_consultation']) && !empty($_REQUEST['agerange2_consultation'])){
                $conds[] = "(age BETWEEN ? AND ?)"; $types .= 'ii'; $params[] = $_REQUEST['agerange1_consultation']; $params[] = $_REQUEST['agerange2_consultation'];
            }

            if(isset($_REQUEST['consultant_consultations']) && !empty($_REQUEST['consultant_consultations'])){
                $conds[] = "consultant_id=?"; $types .= 'i'; $params[] = $_REQUEST['consultant_consultations'];
            }


            if(isset($_REQUEST['signoff']) && !empty($_REQUEST['signoff'])){
                $conds[] = "signoff_date IS NOT NULL";
            }

            if(isset($_REQUEST['indications']) && !empty($_REQUEST['indications']) && is_array($_REQUEST['indications'])){

                // var_dump($_REQUEST['indications']);
                $ddx = $_REQUEST['indications'];
                $ddxcodewd= json_encode($ddx);
                // var_dump($ddxcodewd);
                if ($_REQUEST['indcondition'] == 'or'){
                $conds[] = "JSON_OVERLAPS(indication, ?)"; $types .= 's'; $params[] = $ddxcodewd;
                }elseif ($_REQUEST['indcondition'] == 'and'){
                $conds[] = "JSON_CONTAINS(indication, ?)"; $types .= 's'; $params[] = $ddxcodewd;
                }
            }

if ($conds) { $q .= " AND " . implode(" AND ", $conds); }

// Count total results
$count_q = "SELECT COUNT(*) as total FROM ($q) as subquery";
$count_stmt = $mysqli->prepare($count_q);
if ($types !== '') { $count_stmt->bind_param($types, ...$params); }
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_results = $count_result->fetch_assoc()['total'];

// Add LIMIT and OFFSET for pagination
$q .= " LIMIT 250";

$stmt = $mysqli->prepare($q);
if ($types !== '') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$result1 = $stmt->get_result();
$searchresults = $result1 -> fetch_all(MYSQLI_ASSOC);
// var_dump ($searchresults);

if ($searchresults){
     // Fetch speciality and member details once
    $specialities = [];
    $specialityResults = $mysqli->query("SELECT * FROM speciality");
    while ($row = $specialityResults->fetch_assoc()) {
        $specialities[$row['id']] = $row['specilaity'];
    }

    $consultationReasons = [];
    $reasonResults = $mysqli->query("SELECT * FROM consultation_reason");
    while ($row = $reasonResults->fetch_assoc()) {
        $consultationReasons[$row['id']] = $row['consultation_reason'];
    }
}
echo"
<div id='messsssage' class='card'>
<div class='card-header'>
Results Found: ".$total_results." and showing ".count($searchresults)."
</div>
<div class='card-body'>
                <div class='row'>";
                                   

                                        
                                                     foreach($searchresults as $s){


                                                      $decodeindications=json_decode($s['indication']);
                                                                                                       
                                                     

                                                      
                                                    echo"  
                                                    <div class='col-sm-4'>
                                                    <div class='eachrow card'  id='row".$s['id']."'>
                                                    
                                                    <div style='   margin: 1%; text-align: center;display: inline; ' class='eachcol bed card-header'  scope='row' >";
                                                   
                                     

                                              if($s['signoff_date']) {
                                                echo"
                                                <label style='text-align: center;margin-bottom: 0px;'>Sgined Off</label>";
                                                      
                                               }else{

                                                      echo"
                                                <label style='text-align: center;margin-bottom: 0px;'>Active Consultation In ".$s['current_location']." Bed #</label>

                                                      <input disabled class='txtdata' name='bed' placeholder='Bed Number' value='".$s['BED']."' style='text-align: center;' >
                                                      <input disabled class='txtdata' type='hidden' name='id' id='id' value='".$s['id']."' style='text-align: center;width: 85%;' >";
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
                                                      <label style='text-align: center;margin-bottom: 0px;'>Consultation Date</label>
                                                      <p style='text-align: center;'>".$s['consultation_date']."</p>
                                                    </td>
                                                      </tr>
                                                      </table>
                                                      </div>
                                                
                                                
                                                 <div style=' margin: 1%; ' class='eachcol admfrom'  scope='row' >
                                                      <table style='width: 100%;'>
                                                      <tr>
                                                      <td style='width: 50%; border-right: solid 0.5px;'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>Consultation From</label>
                                                      <p style='text-align: center;margin-bottom: 0px;'>".$s['consultation_from']."</p>
                                                      </td>
                                                      <td style='width: 50%;'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>Consulted Service</label>";
                                                        if (is_numeric($s['consultation_to_service'])) {
                                                            if (isset($specialities[$s['consultation_to_service']])) {
                                                                echo "<p style='text-align: center;margin-bottom: 0px;'>" . $specialities[$s['consultation_to_service']] . "</p>";
                                                            } else {
                                                                echo "<p style='text-align: center;margin-bottom: 0px;'>Unknown speciality</p>";
                                                            }
                                                        } else {
                                                            echo "<p style='text-align: center;margin-bottom: 0px;'>" . $s['consultation_to_service'] . "</p>";
                                                        }
                                                     
                                                      echo"
                                                      </td>
                                                      </tr>
                                                      </table>
                                                      </div>

                                                 <div style=' margin: 1%; ' class='eachcol admissiondiagnosis'>
                                                 <label style='text-align: center;margin-bottom: 0px;'>Diagnosis</label>
                                                 <ul style='list-style-position: inside;margin: 1% 0% 1%;'>
                                                 ";
                                           
                                                        
                                                            if (is_array($decodeindications)) {
                                                                foreach ($decodeindications as $value) {
                                                                    echo "<li>" . $consultationReasons[$value] . "</li>";
                                                                }
                                                            }
                                                           
                                                 echo"
                                              </ul></div>
                                              <div style=' margin: 1%; '>
                                             
                                              <label style='text-align: center;margin-bottom: 0px;'>Consultant Covering</label>";
                                              $con_id= $s['consultant_id'];
                                              $formationSQL = "SELECT * FROM members WHERE member_id=?";
                                               $stmt = $mysqli->prepare($formationSQL);
                                               $stmt->bind_param('i', $con_id);
                                               $stmt->execute();
                                               $result1 = $stmt->get_result();
                                               $doctor1 = $result1 -> fetch_array(MYSQLI_ASSOC);
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
                                              if($s['signoff_date']) {
                                                echo"
                                                <div style=' margin: 1%; border-top: solid 0.5px;'>
                                             
                                                <table style='width: 100%;'>
                                                <tr>
                                                <td style='width: 100%;'>
                                                <label style='text-align: center;margin-bottom: 0px;'>Signed Off on</label>
                                                <p style='text-align: center;margin-bottom: 0px;'>".$s['signoff_date']."</p>
                                                </td>
                                                </tr>
                                                </table>
                                                </div>
                                                ";
                                             
                                              } 
                                            echo"
                                                   </div >
                                                   </div >
                                                      ";
                                                        
                                                     }
                                                    


                                                   
                              
             

        
            echo"        
    
                  
             
                </div>
                </div>
              </div>
  
";
              ?> 
