<?php
require_once ('../dbconnect.php');
 $id = $_REQUEST['bookId']; 


   $formationSQL = "SELECT * FROM consultations WHERE id='".$id."'";
   $result1 = $mysqli->query($formationSQL);
   $patient = $result1 -> fetch_array(MYSQLI_ASSOC);

   $formationSQL = "SELECT * FROM countries";
   $result1 = $mysqli->query($formationSQL);
   $countries = $result1 -> fetch_all(MYSQLI_ASSOC);
// print_r($activepicupatints);

// echo "<div id='pdetailsdiv'>ahmed<div>";
?>

 <form autocomplete="off">
        <label>Consultation Location</label>
        <select class='' id='current_location_modify' style='width: 100%; padding: 4px;text-align: center;' required>
        <option selected value='<?php echo $patient['current_location']; ?>'> <?php echo $patient['current_location']; ?></option>
      <option value='Ward'>Ward</option>
      <option value='ICU'>ICU</option>
      <option value='ER'>ER</option>
    </select>
        <label>Bed Number</label>
        <input class='' id='bed_modify' value='<?php echo $patient['BED']; ?>' style='text-align: center;' required>
        <label>MRNs</label>
        <input class='' id='mrn_modify' value='<?php echo $patient['MRN']; ?>' style='text-align: center;' required>
        <label>Patient Name</label>
        <input class=''  id='pname_modify' value='<?php echo $patient['PNAME']; ?>' style='text-align: center;' required>
        <label>Age</label>
        <input class='' id='age_modify' style='text-align: center;' value='<?php echo $patient['age']; ?>' required>
        
    <label>Consultation Indication</label>
    <select class='select22' style='width: 100%;'  oninput='auto_grow(this)'  multiple='multiple' id='indication_modify' required>
<?php
// Decode the indications for the current patient
$decodedIndications = json_decode($patient['indication']);

// Fetch all consultation reasons
$formationSQL = "SELECT * FROM consultation_reason";
$result1 = $mysqli->query($formationSQL);
$consultationReasons = $result1->fetch_all(MYSQLI_ASSOC);

// Create an array to store selected indications
$selectedIndications = is_array($decodedIndications) ? $decodedIndications : [];

// Display the options with selected ones marked
foreach ($consultationReasons as $reason) {
    $selected = in_array($reason['id'], $selectedIndications) ? 'selected' : '';
    echo '<option ' . $selected . ' value="' . $reason['id'] . '">' . $reason['consultation_reason'] . '</option>';
}
?>
</select>


<div id="other1" style="margin-top:2%;">
<label>Write Indications</label>
<input class='' value='<?php echo $patient['other_ind']; ?>' id='other_indication_modify' style='text-align: center;'>
    </div>


<div id='messsssage'></div>
      </div>
    
      <div class="modal-footer">
      
<?php
     echo" <button type='button' class='btn btn-success'  onclick='modifyconsult(this)'>Modify Consultation</button>";
  ?>
      <button type="button" class="btn btn-default" style=" color: black; " data-bs-dismiss="modal">Close</button>
      </div>

          </form>

    

<script type="text/javascript">
function modifyconsult(button) {
    var id = '<?php echo $id; ?>';
    var bed_modify = document.getElementById('bed_modify').value;
    var mrn_modify = document.getElementById('mrn_modify').value;
    var pname_modify = document.getElementById('pname_modify').value;
    var age_modify = document.getElementById('age_modify').value;
    var current_location_modify = document.getElementById('current_location_modify').value;
    var checked = document.querySelectorAll('#indication_modify :checked');
    var indication_modify = [...checked].map(option => option.value);
    var parent = document.getElementById('messsssage');
    var other_indication_modify = document.getElementById('other_indication_modify').value || "none";

    if (!bed_modify || !mrn_modify || !pname_modify || !age_modify || !indication_modify.length || !current_location_modify || !other_indication_modify) {
        parent.innerHTML = "<p style='color:red'>Please fill all required informations</p>";
        return false;
    }

    var data = {
        id: id,
        bed_modify: bed_modify,
        mrn_modify: mrn_modify,
        pname_modify: pname_modify,
        current_location_modify: current_location_modify,
        age_modify: age_modify,
        indication_modify: indication_modify,
        other_indication_modify: other_indication_modify
    };

    // console.log('Data being sent:', data);  // Add this line
 button.disabled = true;
    button.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"><span class="sr-only">Loading...</span></div>';
    $.post('consultations/dmc-consultation-modify.php', data, function(response) {
                // console.log('Response:', response);

        $(parent).html(response);
            setTimeout(function() {
                    button.disabled = false;
                    button.innerHTML = 'Modify Consultation'
         }, 3000); // Simulate a delay of 3 seconds
    });

    return false;
}


$(document).ready(function() {
    $('.select22').select2({
        placeholder: 'Select',
    });
});
</script>

  <script>

      $(document).ready(function() {
        $('.select22').select2({
      placeholder: 'Select',
      
    } );

      });

      

    </script>

