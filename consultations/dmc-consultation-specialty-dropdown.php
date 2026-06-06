<?php
require_once __DIR__ . '/../guard.php'; require_login();
 
require ('../dbconnect.php');
$consultation_to_service = $_REQUEST['consultation_to_service'];
// echo $specialty_transfer;

$formationSQL = "SELECT * FROM other_specialities";
$result1 = $mysqli->query($formationSQL);
$other_specialities = $result1 -> fetch_all(MYSQLI_ASSOC);
//  var_dump($other_specialities);
//  echo $specialty_inner;
if((array_search($consultation_to_service, array_column($other_specialities, 'specilaity')) !== false)){
}else{

$formationSQL = "SELECT * FROM members WHERE specialty_id = ? AND on_service = '1'";
$stmt = $mysqli->prepare($formationSQL);
$stmt->bind_param("i", $consultation_to_service);
$stmt->execute();
$result1 = $stmt->get_result();
$consultant = $result1 -> fetch_all(MYSQLI_ASSOC);

// var_dump($consultant);
    echo "
    <label>Consultant Name</label>
    <select class='select21 txtdata' style='width: 100%;height: 30px;border: solid 0.5px lightgrey;'  id='consultant_new' name='consultant_new' style='text-align: center;' required>
    <option disabled> Select Consultant</option>
    ";

    foreach($consultant as $con){
    echo"
    <option value='".htmlspecialchars($con['member_id'], ENT_QUOTES, 'UTF-8')."'>".htmlspecialchars($con['full_name'], ENT_QUOTES, 'UTF-8')."</option>";
}

  echo"  </select>
    ";

}

?>

<script type="text/javascript">
      $(document).ready(function() {
        $('.select21').select2({
          placeholder: "Select",
  
    } );

      });
      </script>
