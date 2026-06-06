<?php
require_once __DIR__ . '/../guard.php'; require_login();
 
require_once ('../dbconnect.php');
$specialty_transfer = $_REQUEST['specialty_transfer'];
// echo $specialty_transfer;

$formationSQL = "SELECT * FROM other_specialities";
$result1 = $mysqli->query($formationSQL);
$other_specialities = $result1 -> fetch_all(MYSQLI_ASSOC);
//  var_dump($other_specialities);
//  echo $specialty_inner;
if((array_search($specialty_transfer, array_column($other_specialities, 'specilaity')) !== false)){
}else{

$formationSQL = "SELECT * FROM members WHERE specialty_id = $specialty_transfer AND on_service = '1'";
$result1 = $mysqli->query($formationSQL);
$consultant = $result1 -> fetch_all(MYSQLI_ASSOC);

// var_dump($consultant);
    echo "
    <label>Consultant Name</label>
    <select class='select21 txtdata' style='width: 100%;height: 30px;border: solid 0.5px lightgrey;'  id='constulant_transfer' name='constulant_transfer' style='text-align: center;' required>
    <option disabled> Select Consultant</option>
    ";

    foreach($consultant as $con){
    echo"
    <option value='".$con['member_id']."'>".$con['full_name']."</option>";
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
