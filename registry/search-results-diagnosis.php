<?php
require_once __DIR__ . '/../guard.php'; require_role([0]);
 
session_start();
// var_dump($_SESSION);
require ('../dbconnect.php');


// ---- CSV EXPORT HANDLER (place near the top, before any echo/HTML) ----
if (isset($_GET['export']) && $_GET['export'] === '1') {
    date_default_timezone_set('Asia/Riyadh');

    $keyword = trim($_REQUEST['keyword'] ?? '');
    $from    = trim($_REQUEST['beforedate_keyword'] ?? '');
    $to      = trim($_REQUEST['afterdate_keyword'] ?? '');

    // date validator (YYYY-MM-DD)
    $valid_date = function($d){ return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); };

    // 1) ICD-10 lookup (prepared)
    $icd10_ids = [];
    if ($keyword !== '') {
        if ($stmt = $mysqli->prepare("SELECT id FROM icd10 WHERE name LIKE CONCAT('%', ?, '%')")) {
            $stmt->bind_param("s", $keyword);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $icd10_ids[] = (string)$row['id'];
            $stmt->close();
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            error_log(__FILE__ . ": Error preparing ICD10 query: " . $mysqli->error); echo "A database error occurred.";
            exit;
        }
    }

    if (count($icd10_ids) === 0) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "No results to export.";
        exit;
    }

    // 2) Build WHERE identical to page (no LIMIT)
    $where = "p.ADMDATE IS NOT NULL";
    $types = ''; $params = [];

    if ($from !== '' && $valid_date($from)) {
        $where .= " AND p.ADMDATE >= ?"; $types .= 's'; $params[] = $from;
    }
    if ($to !== '' && $valid_date($to)) {
        $where .= " AND p.ADMDATE <= ?"; $types .= 's'; $params[] = $to;
    }

    $jsonParts = [];
    foreach ($icd10_ids as $id) {
        $jsonParts[] = "JSON_CONTAINS(p.admissiondiagnosis, ?)"; $types .= 's'; $params[] = '["' . (string)$id . '"]';
    }
    $where .= " AND (" . implode(' OR ', $jsonParts) . ")";

    $q = "SELECT p.* FROM picupatients p WHERE {$where} ORDER BY p.ADMDATE DESC";

    // Lookups
    $icd10_names = [];
    if ($icd10_result = $mysqli->query("SELECT id, name FROM icd10")) {
        while ($r = $icd10_result->fetch_assoc()) $icd10_names[$r['id']] = $r['name'];
    }
    $memberData = [];
    if ($memberResult = $mysqli->query("SELECT * FROM members")) {
        while ($r = $memberResult->fetch_assoc()) $memberData[$r['member_id']] = $r;
    }

    // Query rows
    if (!$stmt = $mysqli->prepare($q)) {
        header('Content-Type: text/plain; charset=utf-8');
        error_log(__FILE__ . ": Query error: " . $mysqli->error); echo "A database error occurred.";
        exit;
    }
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $rs = $stmt->get_result();

    // CSV headers
    $filename = "patients_export_" . date('Ymd_His') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    $out = fopen('php://output', 'w');

    // Optional: utility to neutralize formula-injection
    $safeCell = function($v) {
        $s = (string)($v ?? '');
        return (preg_match('/^[=\+\-@]/', $s)) ? "'".$s : $s;
    };

    // Updated header row
    fputcsv($out, [
        'ID','MRN','Age','Gender','Nationality',
        'ADMDATE','med_DISDATE','DISDATE','Admission Location','BED','ADMFROM',
        'MORTALITY','DISTO','Trans/Discharge Type','Delay','LongTerm',
        'LOS (days)',
        'Admission Diagnosis (IDs)','Admission Diagnosis (Names)'
    ]);

    $today1 = strtotime(date("Y-m-d"));

    $mapDiagnosis = function (?string $json, array $nameMap): string {
        if (!$json) return '';
        $arr = json_decode($json, true);
        if (!is_array($arr)) return $json;
        $names = [];
        foreach ($arr as $id) if (isset($nameMap[$id])) $names[] = $nameMap[$id];
        return implode('; ', $names);
    };

    while ($row = $rs->fetch_assoc()) {
        $LOS = 0;
        if (!empty($row['med_DISDATE'])) {
            $LOS = abs(strtotime($row['med_DISDATE']) - strtotime($row['ADMDATE'])) / 86400;
        } else {
            $LOS = abs($today1 - strtotime($row['ADMDATE'])) / 86400;
        }

        fputcsv($out, [
            $row['ID'] ?? '',
            $safeCell($row['MRN'] ?? ''),
            $row['age'] ?? '',
            $row['gender'] ?? '',
            $row['nationality'] ?? '',
            $row['ADMDATE'] ?? '',
            $row['med_DISDATE'] ?? '',
            $row['DISDATE'] ?? '',
            $row['current_location'] ?? '', // renamed column header
            $safeCell($row['BED'] ?? ''),
            $row['ADMFROM'] ?? '',
            $row['MORTALITY'] ?? '',
            $row['DISTO'] ?? '',
            $row['trans_discharge'] ?? '',
            $row['delay'] ?? '',
            $row['longterm'] ?? '',
            $LOS,
            $row['admissiondiagnosis'] ?? '',
            $mapDiagnosis($row['admissiondiagnosis'] ?? null, $icd10_names),
        ]);
    }

    fclose($out);
    exit;
}
// ---- END CSV EXPORT HANDLER ----


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

$keyword = trim($_REQUEST['keyword'] ?? '');
$from    = trim($_REQUEST['beforedate_keyword'] ?? '');
$to      = trim($_REQUEST['afterdate_keyword'] ?? '');

// --- small helpers ---
$valid_date = function($d) {
    // accept YYYY-MM-DD only
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
};

// 1) Find ICD-10 IDs that match the free text (safe with prepared statement)
$icd10_ids = [];
if ($keyword !== '') {
    if ($stmt = $mysqli->prepare("SELECT id FROM icd10 WHERE name LIKE CONCAT('%', ?, '%')")) {
        $stmt->bind_param("s", $keyword);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $icd10_ids[] = $row['id'];
        }
        $stmt->close();
    } else {
        error_log(__FILE__ . ": Error preparing ICD10 query: " . $mysqli->error); die("A database error occurred. Please try again later.");
    }
}

// 2) Build patients query with the same conditions you render with
$searchresults = [];
$total_results = 0;

if (count($icd10_ids) > 0) {

    // base WHERE
    $where = "p.ADMDATE IS NOT NULL";
    $types = ''; $params = [];

    // date range (supports any combo: both, from-only, to-only)
    if ($from !== '' && $valid_date($from)) {
        $where .= " AND p.ADMDATE >= ?"; $types .= 's'; $params[] = $from;
    }
    if ($to !== '' && $valid_date($to)) {
        $where .= " AND p.ADMDATE <= ?"; $types .= 's'; $params[] = $to;
    }

    // JSON_CONTAINS OR chain
    $jsonParts = [];
    foreach ($icd10_ids as $id) {
        // store as string in JSON (your original code used '["$dd"]')
        $jsonParts[] = "JSON_CONTAINS(p.admissiondiagnosis, ?)"; $types .= 's'; $params[] = '["' . (string)$id . '"]';
    }
    if (!empty($jsonParts)) {
        $where .= " AND (" . implode(" OR ", $jsonParts) . ")";
    }

    // final queries
    $q       = "SELECT p.* FROM picupatients p WHERE {$where} ORDER BY p.ADMDATE DESC LIMIT 250";
    $count_q = "SELECT COUNT(*) AS total FROM picupatients p WHERE {$where}";

    // Count
    if (!$count_stmt = $mysqli->prepare($count_q)) {
        error_log(__FILE__ . ": Count error: " . $mysqli->error); die("A database error occurred. Please try again later.");
    }
    if ($types !== '') { $count_stmt->bind_param($types, ...$params); }
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_results = (int)$count_result->fetch_assoc()['total'];

    // Fetch rows
    if (!$stmt = $mysqli->prepare($q)) {
        error_log(__FILE__ . ": Query error: " . $mysqli->error); die("A database error occurred. Please try again later.");
    }
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $result1 = $stmt->get_result();
    $searchresults = $result1->fetch_all(MYSQLI_ASSOC);

    // Lookups you use later
    if ($searchresults) {
        $icd10_result = $mysqli->query("SELECT id, name FROM icd10");
        $icd10_names  = [];
        while ($row = $icd10_result->fetch_assoc()) {
            $icd10_names[$row['id']] = $row['name'];
        }

        $memberData   = [];
        $memberResult = $mysqli->query("SELECT * FROM members");
        while ($row = $memberResult->fetch_assoc()) {
            $memberData[$row['member_id']] = $row;
        }
    }

} else {
    // no matching ICD-10s → empty results
    $searchresults = [];
    $total_results = 0;
}

// Export URL (preserve filters incl. new dates)
$qs = $_GET;
$qs['export'] = '1';
$qs['keyword'] = $keyword; // ensure keyword stays
if ($from !== '') $qs['beforedate_keyword'] = $from;
if ($to   !== '') $qs['afterdate_keyword']  = $to;

$exportUrl = htmlspecialchars($_SERVER['PHP_SELF'] . '?' . http_build_query($qs), ENT_QUOTES);

echo"
<div id='messsssage' class='card'>
  <div class='card-header d-flex justify-content-between align-items-center'>
    <span>Results Found: ".htmlspecialchars($total_results, ENT_QUOTES, 'UTF-8')." and showing ".htmlspecialchars(count($searchresults), ENT_QUOTES, 'UTF-8')."</span>
    <a href='$exportUrl' class='btn btn-success btn-sm'>Export CSV</a>
  </div>
  <div class='card-body'>


                <div class='row'>";
                                   

                                        
                                                     foreach($searchresults as $s){

                                                    


                         


                                                      $formationSQL = "SELECT * FROM picupatients WHERE DISDATE + INTERVAL 3 DAY >=? AND ID <? AND MRN=? AND (trans_discharge = 'discharge from ICU' or trans_discharge='discharge from ward' or trans_discharge IS NULL) LIMIT 1";
                                                      $stmt = $mysqli->prepare($formationSQL);
                                                      $stmt->bind_param('sis', $s['ADMDATE'], $s['ID'], $s['MRN']);
                                                      $stmt->execute();
                                                      $result1 = $stmt->get_result();
                                                        $recentadmission = $result1 -> fetch_all(MYSQLI_ASSOC);
                                                        // var_dump($recentadmission);

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
                                                    <div class='eachrow card'  id='row".htmlspecialchars($s['ID'], ENT_QUOTES, 'UTF-8')."'>
                                                    
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
                                                <label style='text-align: center;margin-bottom: 0px;'>Admitted In ".htmlspecialchars($s['current_location'], ENT_QUOTES, 'UTF-8')." Bed #</label>

                                                      <input disabled class='txtdata' name='bed' placeholder='Bed Number' value='".htmlspecialchars($s['BED'], ENT_QUOTES, 'UTF-8')."' style='text-align: center;' >
                                                      <input disabled class='txtdata' type='hidden' name='id' id='id' value='".htmlspecialchars($s['ID'], ENT_QUOTES, 'UTF-8')."' style='text-align: center;width: 85%;' >";
                                                      }

                                                      echo"
                                                        </div>
                                                      
                                                      <div style=' margin: 1%; ' class='eachcol mrn' >
                                                      <table style='width: 100%;'>
                                                      <tr>
                                                      <td style='width: 50%; border-right: solid 0.5px;'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>MRN</label>
                                                      <p style='text-align: center;'>".htmlspecialchars($s['MRN'], ENT_QUOTES, 'UTF-8')."</p>
                                                      </td>
                                                      <td style='width: 50%;'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>Age</label>
                                                      <p style='text-align: center;'>".htmlspecialchars($s['age'], ENT_QUOTES, 'UTF-8')."</p>
                                                    </td>
                                                      </tr>
                                                      </table>
                                                      </div>

                                                      <div style=' margin: 1%; ' class='eachcol name'>
                                                      <table style='width: 100%;'>
                                                      <tr>
                                                      <td style='width: 50%; border-right: solid 0.5px;'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>Patient Name</label>
                                                      <p style='text-align: center;'>".htmlspecialchars($s['PNAME'], ENT_QUOTES, 'UTF-8')."</p>
                                                      </td>
                                                      <td style='width: 50%;'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>Gender</label>
                                                      <p style='text-align: center;'>".htmlspecialchars($s['gender'], ENT_QUOTES, 'UTF-8')."</p>
                                                    </td>
                                                      </tr>
                                                      </table>
                                                      </div>
                                                
                                                
                                                 <div style=' margin: 1%; ' class='eachcol admfrom'  scope='row' >
                                                      <table style='width: 100%;'>
                                                      <tr>
                                                      <td style='width: 50%; border-right: solid 0.5px;'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>Admission From</label>
                                                      <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['ADMFROM'], ENT_QUOTES, 'UTF-8')."</p>
                                                      </td>
                                                      <td style='width: 50%;'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>Admitted By</label>";
                                                      $mem_id= $s['admitted_by'];
                                                if (isset($memberData[$mem_id])) {
                                                  $doctor = $memberData[$mem_id];
                                                }

                                                      echo"
                                                      <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($doctor['full_name'], ENT_QUOTES, 'UTF-8')."</p>
                                                      </td>
                                                      </tr>
                                                      </table>
                                                      </div>

                                                      <div style=' margin: 1%; ' class='eachcol admdate'  scope='row' >
                                                      <label style='text-align: center;margin-bottom: 0px;'>Admission Date</label>
                                                      <p style='text-align: center;'>".htmlspecialchars($s['ADMDATE'], ENT_QUOTES, 'UTF-8')."</p>
                                                      </div>
                                                      <div style=' margin: 1%; ' class='eachcol admdate'  scope='row' >
                                                      <label style='text-align: center;margin-bottom: 0px;'>Nationality</label>";

                                                      $nation = $s['nationality'];
                                                      
                                                      echo"
                                                      <p style='text-align: center;'>".htmlspecialchars($nation, ENT_QUOTES, 'UTF-8')."</p>
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
                                                                echo los_band_badge($LOS, $shortlos, $longlos); // a11y: name the LOS band (not colour-only)

                                                                echo"
                                                                <label style='text-align: center;margin-bottom: 0px;'>Duration of Admission: ".htmlspecialchars($LOS, ENT_QUOTES, 'UTF-8')." Days</label>
                                                            
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
                                                          echo '<li>' . htmlspecialchars($icd10_names[$value], ENT_QUOTES, 'UTF-8') . '</li>';
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
                                              <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($doctor1['full_name'], ENT_QUOTES, 'UTF-8')."</p>";
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
                                                <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['MORTALITY'], ENT_QUOTES, 'UTF-8')."</p>
                                                </td>
                                                <td style='width: 50%;'>
                                                <label style='text-align: center;margin-bottom: 0px;'>Discharged By</label>";
                                                $mem_id2= $s['trans_discharge_by'];
                                                if (isset($memberData[$mem_id2])) {
                                                  $doctor2 = $memberData[$mem_id2];
                                                }
                                                echo"
                                                <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($doctor2['full_name'], ENT_QUOTES, 'UTF-8')."</p>
                                                </td>
                                                </tr>
                                                </table>
                                                </div>
                                                <div style=' margin: 1%;'>
                                                <table style='width: 100%;'>
                                                <tr>
                                                <td style='width: 50%; border-right: solid 0.5px;'>
                                                <label style='text-align: center;margin-bottom: 0px;'>Discharge Date</label>
                                                <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['med_DISDATE'], ENT_QUOTES, 'UTF-8')."</p>
                                                </td>
                                                <td style='width: 50%;'>
                                                <label style='text-align: center;margin-bottom: 0px; color:red;'>Delay Due To</label>
                                                
                                                <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['delay'], ENT_QUOTES, 'UTF-8')."</p>
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
                                                <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['MORTALITY'], ENT_QUOTES, 'UTF-8')."</p>
                                                </td>
                                                <td style='width: 50%;'>
                                                <label style='text-align: center;margin-bottom: 0px;'>Discharged By</label>";
                                                $mem_id2= $s['trans_discharge_by'];
                                                if (isset($memberData[$mem_id2])) {
                                                  $doctor2 = $memberData[$mem_id2];
                                                }
  
                                                echo"
                                                <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($doctor2['full_name'], ENT_QUOTES, 'UTF-8')."</p>
                                                </td>
                                                </tr>
                                                </table>
                                                </div>
                                              <div style=' margin: 1%;'>
                                             
                                              <table style='width: 100%;'>
                                              <tr>
                                              <td style='width: 50%; border-right: solid 0.5px;'>
                                              <label style='text-align: center;margin-bottom: 0px;'>Discharge Date</label>
                                              <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['med_DISDATE'], ENT_QUOTES, 'UTF-8')."</p>
                                              </td>
                                              <td style='width: 50%;'>
                                              <label style='text-align: center;margin-bottom: 0px;'>Discharged To</label>
                                              <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['DISTO'], ENT_QUOTES, 'UTF-8')."</p>
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
                                              <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['DISDATE'], ENT_QUOTES, 'UTF-8')."</p>
                                              </td>
                                              <td style='width: 50%;'>
                                              <label style='text-align: center;margin-bottom: 0px; color:red;'>Delay Due To</label>
                                              
                                              <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['delay'], ENT_QUOTES, 'UTF-8')."</p>
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
                                            <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['MORTALITY'], ENT_QUOTES, 'UTF-8')."</p>
                                            </td>
                                            <td style='width: 50%;'>
                                            <label style='text-align: center;margin-bottom: 0px;'>Transfer By</label>";
                                            $mem_id2= $s['trans_discharge_by'];
                                                if (isset($memberData[$mem_id2])) {
                                                  $doctor2 = $memberData[$mem_id2];
                                                }

                                            echo"
                                            <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($doctor2['full_name'], ENT_QUOTES, 'UTF-8')."</p>
                                            </td>
                                            </tr>
                                            </table>
                                            </div>
                                          <div style=' margin: 1%;'>
                                         
                                          <table style='width: 100%;'>
                                          <tr>
                                          <td style='width: 50%; border-right: solid 0.5px;'>
                                          <label style='text-align: center;margin-bottom: 0px;'>Tramsfer At</label>
                                          <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['med_DISDATE'], ENT_QUOTES, 'UTF-8')."</p>
                                          </td>
                                          <td style='width: 50%;'>
                                          <label style='text-align: center;margin-bottom: 0px;'>Transfer To</label>
                                          <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['DISTO'], ENT_QUOTES, 'UTF-8')."</p>
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
                                            <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['MORTALITY'], ENT_QUOTES, 'UTF-8')."</p>
                                            </td>
                                            <td style='width: 50%;'>
                                            <label style='text-align: center;margin-bottom: 0px;'>Transfer By</label>";
                                            $mem_id2= $s['trans_discharge_by'];
                                                if (isset($memberData[$mem_id2])) {
                                                  $doctor2 = $memberData[$mem_id2];
                                                }

                                            echo"
                                            <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($doctor2['full_name'], ENT_QUOTES, 'UTF-8')."</p>
                                            </td>
                                            </tr>
                                            </table>
                                            </div>
                                          <div style=' margin: 1%;'>
                                         
                                          <table style='width: 100%;'>
                                          <tr>
                                          <td style='width: 50%; border-right: solid 0.5px;'>
                                          <label style='text-align: center;margin-bottom: 0px;'>Tramsfer At</label>
                                          <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['med_DISDATE'], ENT_QUOTES, 'UTF-8')."</p>
                                          </td>
                                          <td style='width: 50%;'>
                                          <label style='text-align: center;margin-bottom: 0px;'>Transfer To</label>
                                          <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['DISTO'], ENT_QUOTES, 'UTF-8')."</p>
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
                                            <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['MORTALITY'], ENT_QUOTES, 'UTF-8')."</p>
                                            </td>
                                            <td style='width: 50%;'>
                                            <label style='text-align: center;margin-bottom: 0px;'>Discharged By</label>";
                                            $mem_id2= $s['trans_discharge_by'];
                                                if (isset($memberData[$mem_id2])) {
                                                  $doctor2 = $memberData[$mem_id2];
                                                }

                                            echo"
                                            <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($doctor2['full_name'], ENT_QUOTES, 'UTF-8')."</p>
                                            </td>
                                            </tr>
                                            </table>
                                            </div>
                                          <div style=' margin: 1%;'>
                                         
                                          <table style='width: 100%;'>
                                          <tr>
                                          <td style='width: 50%; border-right: solid 0.5px;'>
                                          <label style='text-align: center;margin-bottom: 0px;'>Discharged At</label>
                                          <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['med_DISDATE'], ENT_QUOTES, 'UTF-8')."</p>
                                          </td>
                                          <td style='width: 50%;'>
                                          <label style='text-align: center;margin-bottom: 0px;'>Discharged To</label>
                                          <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['DISTO'], ENT_QUOTES, 'UTF-8')."</p>
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
                                <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['MORTALITY'], ENT_QUOTES, 'UTF-8')."</p>
                                </td>
                                <td style='width: 50%;'>
                                <label style='text-align: center;margin-bottom: 0px;'>Transfer By</label>";
                                $mem_id2= $s['trans_discharge_by'];
                                                if (isset($memberData[$mem_id2])) {
                                                  $doctor2 = $memberData[$mem_id2];
                                                }

                                echo"
                                <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($doctor2['full_name'], ENT_QUOTES, 'UTF-8')."</p>
                                </td>
                                </tr>
                                </table>
                                </div>
                              <div style=' margin: 1%;'>
                             
                              <table style='width: 100%;'>
                              <tr>
                              <td style='width: 50%; border-right: solid 0.5px;'>
                              <label style='text-align: center;margin-bottom: 0px;'>Transfer At</label>
                              <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['med_DISDATE'], ENT_QUOTES, 'UTF-8')."</p>
                              </td>
                              <td style='width: 50%;'>
                              <label style='text-align: center;margin-bottom: 0px;'>Transfer To</label>
                              <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['DISTO'], ENT_QUOTES, 'UTF-8')."</p>
                              </td>
                              </tr>
                              </table>
                              </div>";
                            
                }
                if ($_SESSION['position'] == '0'){
                  echo "<a class='btn btn-info' href='#modify_modal' data-book-id='".htmlspecialchars($s['ID'], ENT_QUOTES, 'UTF-8')."' data-bs-toggle='modal'  style='color: aliceblue;line-height: 2;margin-top: 3%;padding: 0px 10%;width: 100%;'>Modify</a>";
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
                                                    <div class='eachrow card'  id='row".htmlspecialchars($s['ID'], ENT_QUOTES, 'UTF-8')."'>
                                                    
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
                                                <label style='text-align: center;margin-bottom: 0px;'>Admitted In ".htmlspecialchars($s['current_location'], ENT_QUOTES, 'UTF-8')." Bed #</label>

                                                      <input disabled class='txtdata' name='bed' placeholder='Bed Number' value='".htmlspecialchars($s['BED'], ENT_QUOTES, 'UTF-8')."' style='text-align: center;' >
                                                      <input disabled class='txtdata' type='hidden' name='id' id='id' value='".htmlspecialchars($s['ID'], ENT_QUOTES, 'UTF-8')."' style='text-align: center;width: 85%;' >";
                                                      }

                                                      echo"
                                                        </div>
                                                      
                                                      <div style=' margin: 1%; ' class='eachcol mrn' >
                                                      <table style='width: 100%;'>
                                                      <tr>
                                                      <td style='width: 50%; border-right: solid 0.5px;'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>MRN</label>
                                                      <p style='text-align: center;'>".htmlspecialchars($s['MRN'], ENT_QUOTES, 'UTF-8')."</p>
                                                      </td>
                                                      <td style='width: 50%;'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>Age</label>
                                                      <p style='text-align: center;'>".htmlspecialchars($s['age'], ENT_QUOTES, 'UTF-8')."</p>
                                                    </td>
                                                      </tr>
                                                      </table>
                                                      </div>

                                                      <div style=' margin: 1%; ' class='eachcol name'>
                                                      <table style='width: 100%;'>
                                                      <tr>
                                                      <td style='width: 50%; border-right: solid 0.5px;'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>Patient Name</label>
                                                      <p style='text-align: center;'>".htmlspecialchars($s['PNAME'], ENT_QUOTES, 'UTF-8')."</p>
                                                      </td>
                                                      <td style='width: 50%;'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>Gender</label>
                                                      <p style='text-align: center;'>".htmlspecialchars($s['gender'], ENT_QUOTES, 'UTF-8')."</p>
                                                    </td>
                                                      </tr>
                                                      </table>
                                                      </div>
                                                
                                                
                                                 <div style=' margin: 1%; ' class='eachcol admfrom'  scope='row' >
                                                      <table style='width: 100%;'>
                                                      <tr>
                                                      <td style='width: 50%; border-right: solid 0.5px;'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>Admission From</label>
                                                      <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['ADMFROM'], ENT_QUOTES, 'UTF-8')."</p>
                                                      </td>
                                                      <td style='width: 50%;'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>Admitted By</label>";
                                                      $mem_id= $s['admitted_by'];
                                                if (isset($memberData[$mem_id])) {
                                                  $doctor = $memberData[$mem_id];
                                                }

                                                      echo"
                                                      <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($doctor['full_name'], ENT_QUOTES, 'UTF-8')."</p>
                                                      </td>
                                                      </tr>
                                                      </table>
                                                      </div>

                                                      <div style=' margin: 1%; ' class='eachcol admdate'  scope='row' >
                                                      <label style='text-align: center;margin-bottom: 0px;'>Admission Date</label>
                                                      <p style='text-align: center;'>".htmlspecialchars($s['ADMDATE'], ENT_QUOTES, 'UTF-8')."</p>
                                                      </div>
                                                      <div style=' margin: 1%; ' class='eachcol admdate'  scope='row' >
                                                      <label style='text-align: center;margin-bottom: 0px;'>Nationality</label>";

                                                      $nation = $s['nationality'];
                                                      
                                                      echo"
                                                      <p style='text-align: center;'>".htmlspecialchars($nation, ENT_QUOTES, 'UTF-8')."</p>
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
                                                                echo los_band_badge($LOS, $shortlos, $longlos); // a11y: name the LOS band (not colour-only)

                                                                echo"
                                                                <label style='text-align: center;margin-bottom: 0px;'>Duration of Admission: ".htmlspecialchars($LOS, ENT_QUOTES, 'UTF-8')." Days</label>
                                                            
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
                                                          echo '<li>' . htmlspecialchars($icd10_names[$value], ENT_QUOTES, 'UTF-8') . '</li>';
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
                                              <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($doctor1['full_name'], ENT_QUOTES, 'UTF-8')."</p>";
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
                                                <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['MORTALITY'], ENT_QUOTES, 'UTF-8')."</p>
                                                </td>
                                                <td style='width: 50%;'>
                                                <label style='text-align: center;margin-bottom: 0px;'>Discharged By</label>";
                                                $mem_id2= $s['trans_discharge_by'];
                                                if (isset($memberData[$mem_id2])) {
                                                  $doctor2 = $memberData[$mem_id2];
                                                }
                                                echo"
                                                <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($doctor2['full_name'], ENT_QUOTES, 'UTF-8')."</p>
                                                </td>
                                                </tr>
                                                </table>
                                                </div>
                                                <div style=' margin: 1%;'>
                                                <table style='width: 100%;'>
                                                <tr>
                                                <td style='width: 50%; border-right: solid 0.5px;'>
                                                <label style='text-align: center;margin-bottom: 0px;'>Discharge Date</label>
                                                <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['med_DISDATE'], ENT_QUOTES, 'UTF-8')."</p>
                                                </td>
                                                <td style='width: 50%;'>
                                                <label style='text-align: center;margin-bottom: 0px; color:red;'>Delay Due To</label>
                                                
                                                <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['delay'], ENT_QUOTES, 'UTF-8')."</p>
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
                                                <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['MORTALITY'], ENT_QUOTES, 'UTF-8')."</p>
                                                </td>
                                                <td style='width: 50%;'>
                                                <label style='text-align: center;margin-bottom: 0px;'>Discharged By</label>";
                                                $mem_id2= $s['trans_discharge_by'];
                                                if (isset($memberData[$mem_id2])) {
                                                  $doctor2 = $memberData[$mem_id2];
                                                }
                                                echo"
                                                <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($doctor2['full_name'], ENT_QUOTES, 'UTF-8')."</p>
                                                </td>
                                                </tr>
                                                </table>
                                                </div>
                                              <div style=' margin: 1%;'>
                                             
                                              <table style='width: 100%;'>
                                              <tr>
                                              <td style='width: 50%; border-right: solid 0.5px;'>
                                              <label style='text-align: center;margin-bottom: 0px;'>Discharge Date</label>
                                              <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['med_DISDATE'], ENT_QUOTES, 'UTF-8')."</p>
                                              </td>
                                              <td style='width: 50%;'>
                                              <label style='text-align: center;margin-bottom: 0px;'>Discharged To</label>
                                              <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['DISTO'], ENT_QUOTES, 'UTF-8')."</p>
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
                                              <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['DISDATE'], ENT_QUOTES, 'UTF-8')."</p>
                                              </td>
                                              <td style='width: 50%;'>
                                              <label style='text-align: center;margin-bottom: 0px; color:red;'>Delay Due To</label>
                                              
                                              <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['delay'], ENT_QUOTES, 'UTF-8')."</p>
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
                                            <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['MORTALITY'], ENT_QUOTES, 'UTF-8')."</p>
                                            </td>
                                            <td style='width: 50%;'>
                                            <label style='text-align: center;margin-bottom: 0px;'>Transfer By</label>";
                                            $mem_id2= $s['trans_discharge_by'];
                                                if (isset($memberData[$mem_id2])) {
                                                  $doctor2 = $memberData[$mem_id2];
                                                }
                                            echo"
                                            <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($doctor2['full_name'], ENT_QUOTES, 'UTF-8')."</p>
                                            </td>
                                            </tr>
                                            </table>
                                            </div>
                                          <div style=' margin: 1%;'>
                                         
                                          <table style='width: 100%;'>
                                          <tr>
                                          <td style='width: 50%; border-right: solid 0.5px;'>
                                          <label style='text-align: center;margin-bottom: 0px;'>Tramsfer At</label>
                                          <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['med_DISDATE'], ENT_QUOTES, 'UTF-8')."</p>
                                          </td>
                                          <td style='width: 50%;'>
                                          <label style='text-align: center;margin-bottom: 0px;'>Transfer To</label>
                                          <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['DISTO'], ENT_QUOTES, 'UTF-8')."</p>
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
                                            <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['MORTALITY'], ENT_QUOTES, 'UTF-8')."</p>
                                            </td>
                                            <td style='width: 50%;'>
                                            <label style='text-align: center;margin-bottom: 0px;'>Transfer By</label>";
                                            $mem_id2= $s['trans_discharge_by'];
                                                if (isset($memberData[$mem_id2])) {
                                                  $doctor2 = $memberData[$mem_id2];
                                                }
                                            echo"
                                            <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($doctor2['full_name'], ENT_QUOTES, 'UTF-8')."</p>
                                            </td>
                                            </tr>
                                            </table>
                                            </div>
                                          <div style=' margin: 1%;'>
                                         
                                          <table style='width: 100%;'>
                                          <tr>
                                          <td style='width: 50%; border-right: solid 0.5px;'>
                                          <label style='text-align: center;margin-bottom: 0px;'>Tramsfer At</label>
                                          <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['med_DISDATE'], ENT_QUOTES, 'UTF-8')."</p>
                                          </td>
                                          <td style='width: 50%;'>
                                          <label style='text-align: center;margin-bottom: 0px;'>Transfer To</label>
                                          <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['DISTO'], ENT_QUOTES, 'UTF-8')."</p>
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
                                            <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['MORTALITY'], ENT_QUOTES, 'UTF-8')."</p>
                                            </td>
                                            <td style='width: 50%;'>
                                            <label style='text-align: center;margin-bottom: 0px;'>Discharged By</label>";
                                            $mem_id2= $s['trans_discharge_by'];
                                                if (isset($memberData[$mem_id2])) {
                                                  $doctor2 = $memberData[$mem_id2];
                                                }
                                            echo"
                                            <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($doctor2['full_name'], ENT_QUOTES, 'UTF-8')."</p>
                                            </td>
                                            </tr>
                                            </table>
                                            </div>
                                          <div style=' margin: 1%;'>
                                         
                                          <table style='width: 100%;'>
                                          <tr>
                                          <td style='width: 50%; border-right: solid 0.5px;'>
                                          <label style='text-align: center;margin-bottom: 0px;'>Discharged At</label>
                                          <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['med_DISDATE'], ENT_QUOTES, 'UTF-8')."</p>
                                          </td>
                                          <td style='width: 50%;'>
                                          <label style='text-align: center;margin-bottom: 0px;'>Discharged To</label>
                                          <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['DISTO'], ENT_QUOTES, 'UTF-8')."</p>
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
                                <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['MORTALITY'], ENT_QUOTES, 'UTF-8')."</p>
                                </td>
                                <td style='width: 50%;'>
                                <label style='text-align: center;margin-bottom: 0px;'>Transfer By</label>";
                                $mem_id2= $s['trans_discharge_by'];
                                                if (isset($memberData[$mem_id2])) {
                                                  $doctor2 = $memberData[$mem_id2];
                                                }
                                echo"
                                <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($doctor2['full_name'], ENT_QUOTES, 'UTF-8')."</p>
                                </td>
                                </tr>
                                </table>
                                </div>
                              <div style=' margin: 1%;'>
                             
                              <table style='width: 100%;'>
                              <tr>
                              <td style='width: 50%; border-right: solid 0.5px;'>
                              <label style='text-align: center;margin-bottom: 0px;'>Transfer At</label>
                              <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['med_DISDATE'], ENT_QUOTES, 'UTF-8')."</p>
                              </td>
                              <td style='width: 50%;'>
                              <label style='text-align: center;margin-bottom: 0px;'>Transfer To</label>
                              <p style='text-align: center;margin-bottom: 0px;'>".htmlspecialchars($s['DISTO'], ENT_QUOTES, 'UTF-8')."</p>
                              </td>
                              </tr>
                              </table>
                              </div>";
                            
                }
                if ($_SESSION['position'] == '0'){
                  echo "<a class='btn btn-info' href='#modify_modal' data-book-id='".htmlspecialchars($s['ID'], ENT_QUOTES, 'UTF-8')."' data-bs-toggle='modal'  style='color: aliceblue;line-height: 2;margin-top: 3%;padding: 0px 10%;width: 100%;'>Modify</a>";
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
 