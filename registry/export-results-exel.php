<?php
require_once __DIR__ . '/../guard.php'; require_role([0]);

require ('../dbconnect.php');
include('../vendor/PHPExcel.php');
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
if(isset($_POST['search_keyword_btn'])){
                    
                $keyword=$_REQUEST['keyword'];

                $icd10q = "SELECT * FROM icd10 WHERE name like '%$keyword%'";
                $result1 = $mysqli->query($icd10q);
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
                                        $q .= "JSON_CONTAINS(admissiondiagnosis, '[\"$dd\"]')";
                                        }else{
                                        $q .= "JSON_CONTAINS(admissiondiagnosis, '[\"$dd\"]') OR ";
                                        }
                                    }
                                    $q .= ")";
                            
                        
                }
}else if(isset($_POST["search_btn"])){

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
}

$items = array();
$result = $mysqli->query($q);
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
$objPHPExcel    =   new PHPExcel();
 
$objPHPExcel->setActiveSheetIndex(0);
 

$objPHPExcel->getActiveSheet()->SetCellValue('A1', 'MRN');
$objPHPExcel->getActiveSheet()->SetCellValue('B1', 'Age');
$objPHPExcel->getActiveSheet()->SetCellValue('C1', 'Gender');
$objPHPExcel->getActiveSheet()->SetCellValue('D1', 'Nationality');
$objPHPExcel->getActiveSheet()->SetCellValue('E1', 'Diagnosis');
$objPHPExcel->getActiveSheet()->SetCellValue('F1', 'Admission Date');
$objPHPExcel->getActiveSheet()->SetCellValue('G1', 'Admission From');
$objPHPExcel->getActiveSheet()->SetCellValue('H1', 'Admission To');
$objPHPExcel->getActiveSheet()->SetCellValue('I1', 'Clinical Discharge Date');
$objPHPExcel->getActiveSheet()->SetCellValue('J1', 'Physical Discharge Date');
$objPHPExcel->getActiveSheet()->SetCellValue('K1', 'Discharged To');
$objPHPExcel->getActiveSheet()->SetCellValue('L1', 'Mortality');
$objPHPExcel->getActiveSheet()->SetCellValue('M1', 'Delay of discharge');
$objPHPExcel->getActiveSheet()->SetCellValue('N1', 'Primary Consultant');

 
$objPHPExcel->getActiveSheet()->getStyle("A1:O1")->getFont()->setBold(true);
 
$rowCount   =   2;


while($row  =   $result->fetch_assoc()){
    $objPHPExcel->getActiveSheet()->SetCellValue('A'.$rowCount, mb_strtoupper($row['MRN'],'UTF-8'));
    $objPHPExcel->getActiveSheet()->SetCellValue('B'.$rowCount, mb_strtoupper($row['age'],'UTF-8'));
    $objPHPExcel->getActiveSheet()->SetCellValue('C'.$rowCount, mb_strtoupper($row['gender'],'UTF-8'));
    $objPHPExcel->getActiveSheet()->SetCellValue('D'.$rowCount, mb_strtoupper($row['nationality'],'UTF-8'));
 
 $decodedadmissiondx=json_decode($row['admissiondiagnosis']);
 if (is_array($decodedadmissiondx)){
    $diagnosis="";                                 
    foreach($decodedadmissiondx as $key => $value)
{
$formationSQL = "SELECT * FROM icd10 WHERE id='".$value."'";
$result1 = $mysqli->query($formationSQL);
$dxlist = $result1 -> fetch_array(MYSQLI_ASSOC);
 

$diagnosis .=  $dxlist['name']. '  ||  ';
}}
    $objPHPExcel->getActiveSheet()->SetCellValue('E'.$rowCount, mb_strtoupper($diagnosis,'UTF-8'));
    $objPHPExcel->getActiveSheet()->SetCellValue('F'.$rowCount, mb_strtoupper($row['ADMDATE'],'UTF-8'));
    $objPHPExcel->getActiveSheet()->SetCellValue('G'.$rowCount, mb_strtoupper($row['ADMFROM'],'UTF-8'));
    $objPHPExcel->getActiveSheet()->SetCellValue('H'.$rowCount, mb_strtoupper($row['current_location'],'UTF-8'));
    $objPHPExcel->getActiveSheet()->SetCellValue('I'.$rowCount, mb_strtoupper($row['med_DISDATE'],'UTF-8'));
    $objPHPExcel->getActiveSheet()->SetCellValue('J'.$rowCount, mb_strtoupper($row['DISDATE'],'UTF-8'));
    $objPHPExcel->getActiveSheet()->SetCellValue('K'.$rowCount, mb_strtoupper($row['DISTO'],'UTF-8'));
    $objPHPExcel->getActiveSheet()->SetCellValue('L'.$rowCount, mb_strtoupper($row['MORTALITY'],'UTF-8'));
    $objPHPExcel->getActiveSheet()->SetCellValue('M'.$rowCount, mb_strtoupper($row['delay'],'UTF-8'));

    
 $con_id= $row['consultant_id'];
 $formationSQL = "SELECT * FROM members WHERE member_id='".$con_id."'";
 $result1 = $mysqli->query($formationSQL);
 $doctor1 = $result1 -> fetch_array(MYSQLI_ASSOC);
 $doctorname="";
    if ($doctor1){
        $doctorname=$doctor1['full_name'];

    }else{

        $doctorname="not assigned";
 
    } 

    $objPHPExcel->getActiveSheet()->SetCellValue('N'.$rowCount, mb_strtoupper( $doctorname,'UTF-8'));
    $rowCount++;
}
 
 
for($col = 'A'; $col !== 'N'; $col++) {
    $objPHPExcel->getActiveSheet()
        ->getColumnDimension($col)
        ->setAutoSize(true);
}

$objWriter  =   new PHPExcel_Writer_Excel2007($objPHPExcel);
 
$fileName = "Export-".date('d-m-Y').".xlsx";
header('Content-Type: application/vnd.ms-excel'); //mime type
header('Content-Disposition: attachment;filename='.$fileName); //tell browser what's the file name
header('Cache-Control: max-age=0'); //no cache
$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel2007');  
$objWriter->save('php://output');
}
?>