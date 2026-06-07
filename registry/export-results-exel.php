<?php
require_once __DIR__ . '/../guard.php'; require_role([0]);

require ('../dbconnect.php');
require_once __DIR__ . '/../xlsx-writer.php'; // dependency-free .xlsx writer (replaced abandoned PHPExcel)
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
$types = ''; $params = [];
if(isset($_POST['search_keyword_btn'])){

                $keyword=$_REQUEST['keyword'];

                $icd10q = "SELECT * FROM icd10 WHERE name like CONCAT('%', ?, '%')";
                $icd10stmt = $mysqli->prepare($icd10q);
                $icd10stmt->bind_param('s', $keyword);
                $icd10stmt->execute();
                $result1 = $icd10stmt->get_result();
                $icd10list = $result1 -> fetch_all(MYSQLI_ASSOC);

                // var_dump($icd10list);



                if (count($icd10list)>0){



                    $q = "SELECT * FROM picupatients WHERE ADMDATE IS NOT NULL";



                                // echo"ahmed";


                                    $q .= " AND (";
                                    $numItems = count($icd10list);
                                    $i = 0;
                                    foreach ($icd10list as $d){
                                        $dd=$d['id'];
                                        if(++$i === $numItems) {
                                        $q .= "JSON_CONTAINS(admissiondiagnosis, ?)"; $types .= 's'; $params[] = '["' . $dd . '"]';
                                        }else{
                                        $q .= "JSON_CONTAINS(admissiondiagnosis, ?) OR "; $types .= 's'; $params[] = '["' . $dd . '"]';
                                        }
                                    }
                                    $q .= ")";


                }
}else if(isset($_POST["search_btn"])){

        $q = "SELECT * FROM picupatients WHERE ADMDATE IS NOT NULL";
                $conds = [];

                if(isset($_REQUEST['mrn']) && !empty($_REQUEST['mrn'])){
                    $conds[] = "MRN=?"; $types .= 's'; $params[] = $_REQUEST['mrn'];
                }

                if(isset($_REQUEST['beforedate']) && !empty($_REQUEST['beforedate']) && !empty($_REQUEST['afterdate']) && !empty($_REQUEST['afterdate'])){
                    $conds[] = "(ADMDATE BETWEEN ? AND ?)"; $types .= 'ss'; $params[] = $_REQUEST['beforedate']; $params[] = $_REQUEST['afterdate'];
                }

                if(isset($_REQUEST['admfrom']) && !empty($_REQUEST['admfrom'])){
                    $conds[] = "ADMFROM=?"; $types .= 's'; $params[] = $_REQUEST['admfrom'];
                }

                if(isset($_REQUEST['current_location']) && !empty($_REQUEST['current_location'])){
                $conds[] = "current_location=?"; $types .= 's'; $params[] = $_REQUEST['current_location'];
            }

                if(isset($_REQUEST['dischargedto']) && !empty($_REQUEST['dischargedto'])){
                    $conds[] = "DISTO=?"; $types .= 's'; $params[] = $_REQUEST['dischargedto'];
                }

                if(isset($_REQUEST['agerange1']) && !empty($_REQUEST['agerange1']) && !empty($_REQUEST['agerange2']) && !empty($_REQUEST['agerange2'])){
                    $conds[] = "(age BETWEEN ? AND ?)"; $types .= 'ii'; $params[] = $_REQUEST['agerange1']; $params[] = $_REQUEST['agerange2'];
                }

                if(isset($_REQUEST['consultant']) && !empty($_REQUEST['consultant'])){
                    $conds[] = "consultant_id=?"; $types .= 'i'; $params[] = $_REQUEST['consultant'];
                }

                if(isset($_REQUEST['gender']) && !empty($_REQUEST['gender'])){
                    $conds[] = "gender=?"; $types .= 's'; $params[] = $_REQUEST['gender'];
                }

                if(isset($_REQUEST['mortality']) && !empty($_REQUEST['mortality'])){
                    $conds[] = "MORTALITY=?"; $types .= 's'; $params[] = $_REQUEST['mortality'];
                }

                if(isset($_REQUEST['nationality']) && !empty($_REQUEST['nationality'])){
                    $conds[] = "nationality=?"; $types .= 's'; $params[] = $_REQUEST['nationality'];
                }

                if(isset($_REQUEST['delay']) && !empty($_REQUEST['delay'])){
                    $conds[] = "delay=?"; $types .= 's'; $params[] = $_REQUEST['delay'];
                }

                if(isset($_REQUEST['longterm']) && !empty($_REQUEST['longterm'])){
                    $conds[] = "longterm=?"; $types .= 's'; $params[] = $_REQUEST['longterm'];
                }

                if(isset($_REQUEST['only']) && !empty($_REQUEST['only'])){
                    $conds[] = "DISDATE IS NOT NULL";
                }

                if(isset($_REQUEST['admissiondiagnosis']) && !empty($_REQUEST['admissiondiagnosis']) && is_array($_REQUEST['admissiondiagnosis'])){

                    // echo"ahmed";
                    $ddx = $_REQUEST['admissiondiagnosis'];
                    $ddxcodewd= json_encode($ddx);
                    if ($_REQUEST['dxcondition'] == 'or'){
                            //  $q .= " AND JSON_OVERLAPS(admissiondiagnosis, '$ddxcodewd')";
                        $orParts = [];
                        foreach ($ddx as $d){
                            $orParts[] = "JSON_CONTAINS(admissiondiagnosis, ?)"; $types .= 's'; $params[] = '["' . $d . '"]';
                        }
                        $conds[] = "(" . implode(" OR ", $orParts) . ")";
                    }elseif ($_REQUEST['dxcondition'] == 'and'){
                        $conds[] = "JSON_CONTAINS(admissiondiagnosis, ?)"; $types .= 's'; $params[] = $ddxcodewd;
                    }
                }

                if ($conds) { $q .= " AND " . implode(" AND ", $conds); }
    // echo $q;
}

$items = array();
$stmt = $mysqli->prepare($q);
if ($types !== '') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$result = $stmt->get_result();
$export="";


//  if(mysqli_num_rows($result) > 0)
//  {



//  $export .= '
//  <table> 
//  <tr> 
//  <th>MRN</th>
//  <th>Age</th>
//  <th>Gender</th>
//  <th>Nationality</th>
//  <th>Diagnosis</th>
//  <th>Admission Date</th>
//  <th>Admission From</th>
//  <th>Admitted To</th>
//  <th>Clinical Discharge Date</th> 
//  <th>Physical Discharge Date</th> 
//  <th>Discharged To</th> 
//  <th>Mortality</th>
//  <th>Delay of discharge</th>
//  <th>Primary Consultant</th>
//  </tr>
//  ';
//  while($row = mysqli_fetch_array($result))
//  {
//  $export .= '
//  <tr>
//  <td>'.$row["MRN"].'</td> 
//  <td>'.$row["age"].'</td> 
//  <td>'.$row["gender"].'</td> 
//  <td>'.$row["nationality"].'</td>
//  <td>';


//  $decodedadmissiondx=json_decode($row['admissiondiagnosis']);
//  if (is_array($decodedadmissiondx)){
                                                   
//     foreach($decodedadmissiondx as $key => $value)
// {
// $formationSQL = "SELECT * FROM icd10 WHERE id='".$value."'";
// $result1 = $mysqli->query($formationSQL);
// $dxlist = $result1 -> fetch_array(MYSQLI_ASSOC);
 

// $export .=  $dxlist['name']. '  ||  ';
// }}




//  $export .= '</td>
//  <td>'.$row["ADMDATE"].'</td>
//  <td>'.$row["ADMFROM"].'</td>
//  <td>'.$row["current_location"].'</td>
//  <td>'.$row["med_DISDATE"].'</td> 
//  <td>'.$row["DISDATE"].'</td> 
//  <td>'.$row["DISTO"].'</td> 
//  <td>'.$row["MORTALITY"].'</td> 
//  <td>'.$row["delay"].'</td>';


//  $con_id= $row['consultant_id'];
//  $formationSQL = "SELECT * FROM members WHERE member_id='".$con_id."'";
//  $result1 = $mysqli->query($formationSQL);
//  $doctor1 = $result1 -> fetch_array(MYSQLI_ASSOC);
//     if ($doctor1){
//         $export .= '
//         <td>'.$doctor1['full_name'].'</td>
//         </tr>
//         ';
//     }else{
//         $export .= '
//         <td>not assigned</td>
//         </tr>
//         ';
//     } 






//  }
//  $export .= '</table>';
//  $fileName = "itemdata-".date('d-m-Y').".xls";
//  header("Content-type:application/octet-stream");
//  header("Accept-Ranges:bytes");
//  header("Content-type:application/vnd.ms-excel");
//  header('Content-Disposition: attachment; filename='.$fileName);
//  header("Pragma: no-cache");
//  header("Expires: 0");
//  echo $export;
//  }
// }

if(mysqli_num_rows($result) > 0)
  {
    $xlsx = new XlsxWriter();
    $xlsx->setHeaderRows(1); // bold the header row
    $xlsx->addRow([
        'MRN', 'Age', 'Gender', 'Nationality', 'Diagnosis', 'Admission Date',
        'Admission From', 'Admission To', 'Clinical Discharge Date', 'Physical Discharge Date',
        'Discharged To', 'Mortality', 'Delay of discharge', 'Primary Consultant',
    ]);

    // Prepare the per-row lookups once (not re-prepared every row).
    $dxstmt  = $mysqli->prepare("SELECT name FROM icd10 WHERE id=?");
    $constmt = $mysqli->prepare("SELECT full_name FROM members WHERE member_id=?");

    $up = static function ($v) { return mb_strtoupper((string) $v, 'UTF-8'); };

    while ($row = $result->fetch_assoc()) {
        // Diagnosis names (reset each row — the old code left a stale value when the JSON
        // wasn't an array; reset up front so a missing diagnosis is blank, not carried over).
        $diagnosis = "";
        $decodedadmissiondx = json_decode($row['admissiondiagnosis']);
        if (is_array($decodedadmissiondx)) {
            foreach ($decodedadmissiondx as $value) {
                $dxstmt->bind_param('s', $value);
                $dxstmt->execute();
                $dxlist = $dxstmt->get_result()->fetch_assoc();
                if ($dxlist) { $diagnosis .= $dxlist['name'] . '  ||  '; }
            }
        }

        // Primary consultant name.
        $doctorname = "not assigned";
        $con_id = $row['consultant_id'];
        if ($con_id !== null && $con_id !== '') {
            $constmt->bind_param('i', $con_id);
            $constmt->execute();
            $doctor1 = $constmt->get_result()->fetch_assoc();
            if ($doctor1) { $doctorname = $doctor1['full_name']; }
        }

        $xlsx->addRow([
            $up($row['MRN']), $up($row['age']), $up($row['gender']), $up($row['nationality']),
            $up($diagnosis), $up($row['ADMDATE']), $up($row['ADMFROM']), $up($row['current_location']),
            $up($row['med_DISDATE']), $up($row['DISDATE']), $up($row['DISTO']), $up($row['MORTALITY']),
            $up($row['delay']), $up($doctorname),
        ]);
    }

    $xlsx->download("Export-" . date('d-m-Y') . ".xlsx");
}
?>