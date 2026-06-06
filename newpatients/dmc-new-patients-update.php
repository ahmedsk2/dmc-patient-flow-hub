<?php
require_once __DIR__ . '/../guard.php'; require_login();
csrf_verify();
 
require_once ('../dbconnect.php');
$id = $_REQUEST['id1'];
$bed = $_REQUEST['bed']; 
$mrn = trim($_REQUEST['mrn']); 
$name = $_REQUEST['name'];
$age = $_REQUEST['age'];
$gender = $_REQUEST['gender'];
$current_location = $_REQUEST['current_location'];
$nationality = $_REQUEST['nationality'];
$admfrom = $_REQUEST['admfrom'];
// $admfrom = $_REQUEST['admfrom']; 

$admdate1 = $_REQUEST['admdate']; 
if (!empty($admdate1)){
  $admdate=date("Y-m-d",strtotime($admdate1));
  }else{
    $admdate=null;
  }

  $admissiondiagnosis1 = $_REQUEST['admissiondiagnosis'];
  $admissiondiagnosis = json_encode($admissiondiagnosis1);

// W3: validate only the field that actually changed (avoids blocking edits on unchanged data).
$attrib = $_REQUEST['attribChanged'] ?? '';
$verr = '';
if ($attrib === 'mrn')         { $verr = v_len($mrn, 'MRN', 50); }
elseif ($attrib === 'name')    { $verr = v_len($name, 'Patient name', 100); }
elseif ($attrib === 'age')     { $verr = v_int_range($age, 'Age', 0, 150); }
elseif ($attrib === 'gender')  { $verr = v_in($gender, 'Gender', ['Male', 'Female']); }
elseif ($attrib === 'admdate') { $verr = v_date_ymd($admdate, 'Admission date'); }
if ($verr !== '') { echo "Error: " . $verr; exit; }


 $sql = "UPDATE picupatients SET current_location=?,BED=?,MRN=?, PNAME=?,  ADMFROM=?,age=?,gender=?,nationality=?,ADMFROM=?,
 ADMDATE=?
 , admissiondiagnosis=? WHERE ID=?";

                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("sssssisssssi", $current_location, $bed, $mrn, $name, $admfrom, $age, $gender, $nationality, $admfrom, $admdate, $admissiondiagnosis, $id);
                if ($stmt->execute() === TRUE) {
                  $message= "Record updated successfully";
                  audit_log('patient.update','picupatients',$id, ['field'=>$attrib]);
                } else {
                 error_log(__FILE__ . ": update " . $mysqli->error); $message= "Error updating record.";
                }

echo 
$message . $age
;


              

?>
