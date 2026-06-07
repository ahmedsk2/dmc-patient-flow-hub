<?php
require_once __DIR__ . '/../guard.php'; require_role([0, 2, 3, 4]); require_capability('modify_patient');

require_once ('../dbconnect.php');
   $id = $_REQUEST['bookId'];


   $stmt = $mysqli->prepare("SELECT * FROM picupatients WHERE ID = ?");
   $stmt->bind_param("i", $id);
   $stmt->execute();
   $patient = $stmt->get_result()->fetch_array(MYSQLI_ASSOC);

   $formationSQL = "SELECT * FROM countries";
   $result1 = $mysqli->query($formationSQL);
   $countries = $result1 -> fetch_all(MYSQLI_ASSOC);
// print_r($activepicupatints);

// echo "<div id='pdetailsdiv'>ahmed<div>";
?>

<script>

function updatepatient(button) {

// var rowname= "row";
// rowname+=value;
// row = document.getElementById(rowname);
//   var id = value;+
id_modify=document.getElementById('id_modify').value;
bed_modify=document.getElementById('bed_modify').value;
mrn_modify=document.getElementById('mrn_modify').value;
pname_modify=document.getElementById('pname_modify').value;
gender_modify=document.getElementById('gender_modify').value;
age_modify=document.getElementById('age_modify').value;
nationality_modify=document.getElementById('nationality_modify').value;
primary_modify=document.getElementById('primary_modify').value;
admdate_modify=document.getElementById('admdate_modify').value;
current_location_modify=document.getElementById('current_location_modify').value;
var admfrom_modify1 = document.getElementById("admfrom_modify1");
var admfrom_modify = admfrom_modify1.options[admfrom_modify1.selectedIndex].value;

var checked = document.querySelectorAll('#admissiondiagnosis_modify :checked');
var admissiondiagnosis_modify = [...checked].map(option => option.value);


if(id_modify=="" || bed_modify=="" || mrn_modify=="" || pname_modify=="" || gender_modify=="" ||
nationality_modify=="" || admdate_modify=="" || age_modify=="" || admfrom_modify=="" || admissiondiagnosis_modify==""
|| primary_modify=="" || current_location_modify=="" ){
            //do something

            document.getElementById("message111").innerHTML ="<p style='color:red'>Fill All Please</p>";
            return false;
            //this will not submit your form

            

}
else{
    // W1: editing here is intentional, but changing patient IDENTITY (MRN / name) is high-stakes — confirm it.
    var ORIG_MRN  = <?php echo json_encode($patient['MRN']); ?>;
    var ORIG_NAME = <?php echo json_encode($patient['PNAME']); ?>;
    if (String(mrn_modify) !== String(ORIG_MRN) || String(pname_modify) !== String(ORIG_NAME)) {
      if (!window.dmcConfirm("You are changing the patient identity (MRN / name). Save these changes?", pname_modify, mrn_modify)) {
        return false;
      }
    }
          data = {id_modify:id_modify, bed_modify: bed_modify,primary_modify:primary_modify, mrn_modify: mrn_modify, age_modify:age_modify, gender_modify: gender_modify, pname_modify: pname_modify, nationality_modify: nationality_modify,
           admdate_modify:admdate_modify,admfrom_modify:admfrom_modify, admissiondiagnosis_modify:admissiondiagnosis_modify, current_location_modify:current_location_modify};
            // alert(mrn_modify);
          
          button.disabled = true;
          button.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"><span class="sr-only">Loading...</span></div>';

            $.post('patients/dmc-patients-modify.php', data)
              .done(function(data){
                // W1: only treat as saved when the server confirms success.
                if (window.dmcOk(data)) {
                  location.reload();
                } else {
                  document.getElementById("message111").innerHTML = "<p style='color:red'>Update failed — changes were NOT saved.</p>";
                  button.disabled = false;
                  button.innerHTML = 'Update Patient';
                }
              })
              .fail(function(){
                document.getElementById("message111").innerHTML = "<p style='color:red'>Update failed (server error) — changes were NOT saved.</p>";
                button.disabled = false;
                button.innerHTML = 'Update Patient';
              });
}
// alert(data);
//This will submit your form.
        
// alert(patientId);
//   row.style.display = "none";
  return false; // prevent native form submit; the AJAX flow drives navigation
  }


</script>
<form autocomplete="off">
<input type="hidden" id='id_modify' value='<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>'>
<label>Bed Number</label>
<input class='txtdata' id='bed_modify' value='<?php echo htmlspecialchars($patient['BED'], ENT_QUOTES, 'UTF-8'); ?>' style='text-align: center;' required>
<label>Patient Location</label>
<select class='txtdata' id='current_location_modify' style='width: 100%; padding: 4px;text-align: center;' required>
      <option selected value='<?php echo htmlspecialchars($patient['current_location'], ENT_QUOTES, 'UTF-8'); ?>'> <?php echo htmlspecialchars($patient['current_location'], ENT_QUOTES, 'UTF-8'); ?></option>
      <option value='Ward'>Ward</option>
      <option value='ICU'>ICU</option>
      <option value='ER'>ER</option>
    </select>
<label>MRNs</label>
<input class='txtdata' id='mrn_modify' value='<?php echo htmlspecialchars($patient['MRN'], ENT_QUOTES, 'UTF-8'); ?>' style='text-align: center;' required>
<label>Patient Name</label>
<input class='txtdata' id='pname_modify' value='<?php echo htmlspecialchars($patient['PNAME'], ENT_QUOTES, 'UTF-8'); ?>' style='text-align: center;' required>
<label>Age</label>
<input class='txtdata' id='age_modify' value='<?php echo htmlspecialchars($patient['age'], ENT_QUOTES, 'UTF-8'); ?>' style='text-align: center;' required>
<label>Gender</label>
<select class='txtdata' id='gender_modify' style="width: 100%;text-align: center;padding: 4px;" required>
<option selected value='<?php echo htmlspecialchars($patient['gender'], ENT_QUOTES, 'UTF-8'); ?>'> <?php echo htmlspecialchars($patient['gender'], ENT_QUOTES, 'UTF-8'); ?></option>
<option value='Male'>Male</option>
<option value='Female'>Female</option>
</select>
<label>Nationality</label>
<select class='select2_modify txtdata' id='nationality_modify' style='text-align: center;' required>
<option selected value='<?php echo htmlspecialchars($patient['nationality'], ENT_QUOTES, 'UTF-8'); ?>'> <?php echo htmlspecialchars($patient['nationality'], ENT_QUOTES, 'UTF-8'); ?></option>
 <?php
foreach ($countries as $country)
    echo '<option value="' . htmlspecialchars($country['name'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($country['name'], ENT_QUOTES, 'UTF-8') . '</option>';
  ?>

</select>

<label>Admission date</label>
<input value='<?php echo htmlspecialchars($patient['ADMDATE'], ENT_QUOTES, 'UTF-8'); ?>' class='txtdata' id ="admdate_modify"  data-date-format="DD-MM-YYYY" type="text"  name='admdate' style="text-align: center;padding: 0px;" required readonly>


<label>Admitted from</label>
<select class='txtdata' id ="admfrom_modify1" style="width: 100%;text-align: center;padding: 4px;" required>
<option selected value='<?php echo htmlspecialchars($patient['ADMFROM'], ENT_QUOTES, 'UTF-8'); ?>'><?php echo htmlspecialchars($patient['ADMFROM'], ENT_QUOTES, 'UTF-8'); ?></option>
<option value='ER'>ER</option>
<option value='ICU'>ICU</option>
<option value='OPD'>OPD</option>
<option value='OR'>OR</option>
<option value='Referral'>Referral</option>
</select>

<label>Primary Consultant</label>
<select class='txtdata' id ="primary_modify" style="width: 100%;text-align: center;padding: 4px;" required>

<?php
$formationSQL = "SELECT * FROM members WHERE on_service = '1'";
$result1 = $mysqli->query($formationSQL);
$consultant = $result1 -> fetch_all(MYSQLI_ASSOC);

foreach ($consultant as $con) {
    $selected = ($con['member_id'] == $patient['consultant_id']) ? ' selected' : '';
    echo '<option' . $selected . ' value="' . htmlspecialchars($con['member_id'], ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($con['full_name'], ENT_QUOTES, 'UTF-8') . '</option>';
}
?>


</select>

<?php
$decodedadmissiondx=json_decode($patient['admissiondiagnosis']);
?>

<label>Diagnosis</label>
<select class='txtdata ddxname_modify form-control' style='width: 100%;'  oninput='auto_grow(this)'  multiple='multiple' id='admissiondiagnosis_modify' required>
<?php

if (is_array($decodedadmissiondx)){

        foreach($decodedadmissiondx as $key => $value)
  {
    $dxstmt = $mysqli->prepare("SELECT * FROM icd10 WHERE id = ?");
		$dxstmt->bind_param("s", $value);
		$dxstmt->execute();
		$dxlist = $dxstmt->get_result()->fetch_array(MYSQLI_ASSOC);

      echo '<option selected value="' . htmlspecialchars($dxlist['id'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($dxlist['name'], ENT_QUOTES, 'UTF-8') . '</option>';
  }}

  ?>

</select>

<div class="modal-footer" style="text-align: center;display: block;">
<div color='green' style="color:forestgreen;" id="message111"></div>
<button type='submit' value='submit' class='btn btn-success'  onclick="return updatepatient(this)">Update Patient</button>

<button type="button" class="btn btn-default" style=" color: black; " data-bs-dismiss="modal">Close</button>

</div>

</form>


  
  <script type="text/javascript">
      $(document).ready(function() {
        $('.select2_modify').select2({
      placeholder: 'Select',
      width: '100%',
      dropdownParent: $("#details_modal")

    } );
        $('.ddxname_modify').select2({
            placeholder: 'Select',
            minimumInputLength: 4,
            ajax: {
                url: '../fetchicd10.php',
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

      

$(function() {
  $('input[name="admdate"]').daterangepicker({
    singleDatePicker: true,
    timePicker: false,
    timePicker24Hour: false,
    showButtonPanel: false,
    autoUpdateInput: false,
    autoApply: true,
    showDropdowns: true,
    minYear: 2010,
    maxYear: parseInt(moment().format('YYYY'),10),
    locale: {
            format: 'YYYY-MM-DD'
        }
  }, ).on("apply.daterangepicker", function (e, picker) {
        picker.element.val(picker.startDate.format(picker.locale.format));
    });
});


    </script>

    