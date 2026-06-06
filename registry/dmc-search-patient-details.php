<?php
require_once __DIR__ . '/../guard.php'; require_role([0]);


require ('../dbconnect.php');
   $id = $_REQUEST['bookId']; 


   $formationSQL = "SELECT * FROM picupatients WHERE ID=?";
   $stmt = $mysqli->prepare($formationSQL);
   $stmt->bind_param('i', $id);
   $stmt->execute();
   $result1 = $stmt->get_result();
   $patient = $result1 -> fetch_array(MYSQLI_ASSOC);

   $formationSQL = "SELECT * FROM countries";
   $result1 = $mysqli->query($formationSQL);
   $countries = $result1 -> fetch_all(MYSQLI_ASSOC);
// print_r($activepicupatints);

// echo "<div id='pdetailsdiv'>ahmed<div>";
?>

<script>

function updatepatient() {
    event.preventDefault(); // Prevent the default form submission

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
var checked = document.querySelectorAll('#admissiondiagnosis_modify :checked');
var admissiondiagnosis_modify = [...checked].map(option => option.value);


if(id_modify=="" || bed_modify=="" || mrn_modify=="" || pname_modify=="" || gender_modify=="" ||
nationality_modify=="" || age_modify=="" || admissiondiagnosis_modify==""){
            //do something

            document.getElementById("message111").innerHTML ="<p style='color:red'>Fill All Please</p>";
            return false;
            //this will not submit your form

            

}
else{
          data = {id_modify:id_modify, bed_modify: bed_modify, mrn_modify: mrn_modify, age_modify:age_modify, gender_modify: gender_modify, pname_modify: pname_modify, nationality_modify: nationality_modify,
            admissiondiagnosis_modify:admissiondiagnosis_modify};
            // alert(mrn_modify);
          
            $.post('registry/dmc-search-patients-modify.php', data, function(data){
                // alert(admissiondiagnosis_modify);
                $('#pdetailsdiv').html(data);
return false;
// location.reload();
});
}
// alert(data);
//This will submit your form.
        
// alert(patientId);
//   row.style.display = "none";
  }


</script>
<form autocomplete="off"  onsubmit="return updatepatient(event)">
<input type="hidden" id='id_modify' value='<?php echo $id; ?>'>
<label>Bed Number</label>
<input class='txtdata' id='bed_modify' value='<?php echo $patient['BED']; ?>' style='text-align: center;' required>
<label>MRNs</label>
<input class='txtdata' id='mrn_modify' value='<?php echo $patient['MRN']; ?>' style='text-align: center;' required>
<label>Patient Name</label>
<input class='txtdata' id='pname_modify' value='<?php echo $patient['PNAME']; ?>' style='text-align: center;' required>
<label>Age</label>
<input class='txtdata' id='age_modify' value='<?php echo $patient['age']; ?>' style='text-align: center;' required>
<label>Gender</label>
<select class='txtdata' id='gender_modify' style='text-align: center; width: 100%; padding: 4px;' required>
<option selected value='<?php echo $patient['gender']; ?>'> <?php echo $patient['gender']; ?></option>
<option value='Male'>Male</option>
<option value='Female'>Female</option>
</select>
<label>Nationality</label>
<select class='select2_modify txtdata' id='nationality_modify' style='text-align: center;' required>
<option selected value='<?php echo $patient['nationality']; ?>'> <?php echo $patient['nationality']; ?></option>
 <?php  

foreach($countries as $country)
    echo"
    <option value='".$country['name']."'>".$country['name']."</option>";
  ?>

</select>


<?php
$decodedadmissiondx=json_decode($patient['admissiondiagnosis']);
?>

</select>
<label>Diagnosis</label>
<select class='txtdata ddxname_modify form-control' style='width: 100%;'  oninput='auto_grow(this)'  multiple='multiple' id='admissiondiagnosis_modify' required>
<?php

if (is_array($decodedadmissiondx)){

        $formationSQL = "SELECT * FROM icd10 WHERE id=?";
        $stmt = $mysqli->prepare($formationSQL);
        foreach($decodedadmissiondx as $key => $value)
  {
    $stmt->bind_param('s', $value);
		$stmt->execute();
		$result1 = $stmt->get_result();
		$dxlist = $result1 -> fetch_array(MYSQLI_ASSOC);
      // $selected = in_array($key, $decodedP) ? 'selected ' : '';

      echo '<option selected value="' . $dxlist['id'] . '">'.  $dxlist['name']. '</option>';
  }}

  ?>

</select>
</div>

<div class="modal-footer" style="text-align: center;display: block;">
<div color='green' style="color:forestgreen;" id="message111"></div>
<button type='submit' value='submit' class='btn btn-success'  onclick="updatepatient()">Update Patient</button>

<button type="button" class="btn btn-default" style=" color: black; " data-bs-dismiss="modal">Close</button>

</div>

</form>


  
  <script type="text/javascript">
      $(document).ready(function() {
        $('.select2_modify').select2({
      placeholder: 'Select',
            width: '100%',
      dropdownParent: $("#modify_modal")
    } );
        $('.ddxname_modify').select2({
            placeholder: 'Select',
            minimumInputLength: 4,
            ajax: {
                url: 'fetchicd10.php',
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

      

    </script>

    