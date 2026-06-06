<?php
session_start();
require_once ('../dbconnect.php');
   $id = $_REQUEST['bookId']; 
  $userid = $_REQUEST['userid']; 
  
   $formationSQL = "SELECT * FROM picupatients WHERE ID='".$id."'";
   $result1 = $mysqli->query($formationSQL);
   $patient = $result1 -> fetch_array(MYSQLI_ASSOC);

   $formationSQL = "SELECT * FROM countries";
   $result1 = $mysqli->query($formationSQL);
   $countries = $result1 -> fetch_all(MYSQLI_ASSOC);
// print_r($activepicupatints);

// echo "<div id='pdetailsdiv'>ahmed<div>";
?>
<style>
/* Customize the label (the container) */
.container {
  display: block;
  position: relative;
  padding-left: 35px;
  cursor: pointer;

  -webkit-user-select: none;
  -moz-user-select: none;
  -ms-user-select: none;
  user-select: none;
}

/* Hide the browser's default radio button */
.container input {
  position: absolute;
  opacity: 0;
  cursor: pointer;
  height: 0;
  width: 0;
}

/* Create a custom radio button */
.checkmark {
  position: absolute;
  height: 100%;
  /* width: 5%; */
  background-color: #eee;
  border-radius: 50%;
}

/* On mouse-over, add a grey background color */
.container:hover input ~ .checkmark {
  background-color: #ccc;
}

/* When the radio button is checked, add a blue background */
.container input:checked ~ .checkmark {
  background-color: #2196F3;
}

/* Create the indicator (the dot/circle - hidden when not checked) */
.checkmark:after {
  content: "";
  position: absolute;
  display: none;
}

/* Show the indicator (dot/circle) when checked */
.container input:checked ~ .checkmark:after {
  display: block;
}

/* Style the indicator (dot/circle) */
.container .checkmark:after {
  top: 7px;
  left: 7px;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: white;
}

</style>

<script>

function dischargepatient(button) {
 
// var rowname= "row";
// rowname+=value;
// row = document.getElementById(rowname);
//   var id = value;+
id_modify=document.getElementById('id_modify').value;
bed_modify=document.getElementById('bed_modify').value;
mrn_modify=document.getElementById('mrn_modify').value;
pname_modify=document.getElementById('pname_modify').value;
gender_modify=document.getElementById('gender_modify').value;
age=document.getElementById('age_modify').value;
nationality_modify=document.getElementById('nationality_modify').value;
admdate=document.getElementById('admdate_modify').value;
admfrom_modify=document.getElementById('admfrom_modify').value;

var checked = document.querySelectorAll('#admissiondiagnosis_modify :checked');
var admissiondiagnosis_modify = [...checked].map(option => option.value);
var userid=<?php echo $userid; ?>;
   
var disdate=document.getElementById('disdate').value;
var disstatus=document.getElementById('disstatus').value;
var disto=document.getElementById('disto').value;

var discahrge_type1=document.querySelector('input[name=discahrge_type]:checked');

if(discahrge_type1 !== null){
 var discahrge_type=document.querySelector('input[name=discahrge_type]:checked').value;
}else{
  discahrge_type=""; 
}

// alert (discahrge_type);

if(id_modify=="" || bed_modify=="" || mrn_modify=="" || pname_modify=="" || gender_modify=="" ||
nationality_modify=="" || admdate=="" || age=="" || discahrge_type=="" || admfrom_modify=="" || admissiondiagnosis_modify==""
|| disdate=="" || disstatus=="" || disto==""  ){
            //do something
            // alert("missing");
            document.getElementById("message").innerHTML ="<p style='color:red'>Fill All Please</p>";
            return false;
            //this will not submit your form

            

}
else{
// alert("test");
//  return false;
          data = {id_modify:id_modify, bed_modify: bed_modify, mrn_modify: mrn_modify, gender_modify: gender_modify, age: age, pname_modify: pname_modify, nationality_modify: nationality_modify,
           admdate:admdate,admfrom_modify:admfrom_modify, admissiondiagnosis_modify:admissiondiagnosis_modify,discahrge_type:discahrge_type
           ,disdate:disdate,disstatus:disstatus, disto:disto ,userid:userid };
        //  alert("not missing");
         button.disabled = true;
         button.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"><span class="sr-only">Loading...</span></div>';


            $.post('patients/dmc-patients-discharge-submit.php', data, function(data){
              
                $('#message').html(data);
                           // Simulate an AJAX call to transfer the patient
                setTimeout(function() {
                        button.disabled = false;
                        button.innerHTML = 'Discharge Patient'
                }, 3000); // Simulate a delay of 2 seconds
                            // return false;
location.reload();
});
}
// alert(data);
//This will submit your form.
        
// alert(patientId);
//   row.style.display = "none";
  }


</script>

<form autocomplete="off">
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
<select class='txtdata' id='gender_modify' style="width: 100%;text-align: center;padding: 4px;" required>
<option selected value='<?php echo $patient['gender']; ?>'> <?php echo $patient['gender']; ?></option>
<option value='Male'>Male</option>
<option value='Female'>Female</option>
</select>
<label>Nationality</label>
<select class='select2_discharge txtdata' id='nationality_modify' style='text-align: center;' required>
<option selected value='<?php echo $patient['nationality']; ?>'> <?php echo $patient['nationality']; ?></option>
 <?php  

foreach($countries as $country)
    echo"
    <option value='".$country['name']."'>".$country['name']."</option>";
  ?>

</select>
<label>Admittion date</label>
<input value='<?php echo $patient['ADMDATE']; ?>' class='txtdata' id ="admdate_modify"  data-date-format="DD-MM-YYYY" type="text"  name='admdate' style="text-align: center;padding: 0px;" required disabled>


<label>Admitted from</label>
<select class='txtdata' id ="admfrom_modify" style="width: 100%;text-align: center;padding: 4px;" required>
<option selected value='<?php echo $patient['ADMFROM']; ?>'><?php echo $patient['ADMFROM']; ?></option>
<option value='ER'>ER</option>
<option value='ICU'>ICU</option>
<option value='OPD'>OPD</option>
<option value='OR'>OR</option>
<option value='Referral'>Referral</option>
</select>

<?php
 

$decodedadmissiondx=json_decode($patient['admissiondiagnosis']);

?>

<label>Diagnosis</label>
<select class='txtdata ddxname_discharge form-control' style='width: 100%;'  oninput='auto_grow(this)'  multiple='multiple' id='admissiondiagnosis_modify' required>
<?php

if (is_array($decodedadmissiondx)){
        
        foreach($decodedadmissiondx as $key => $value)
  {
    $formationSQL = "SELECT * FROM icd10 WHERE id='".$value."'";
		$result1 = $mysqli->query($formationSQL);
		$dxlist = $result1 -> fetch_array(MYSQLI_ASSOC);
     

      echo '<option selected value="' . $dxlist['id'] . '">'.  $dxlist['name']. '</option>';
  }}
echo"
  </select>";
   
  
   ?>



</select>
<h3><label>Discharge Details</label></h3>
<label class="container">Discharge still in
  <input  class="discahrge_type" type="radio"   value="medical" name="discahrge_type" id="discahrge_type" required>
  <span class="checkmark"></span>
</label>
<label class="container">Complete Discharge
<input  class="discahrge_type" type="radio"   value="both" name="discahrge_type" id="discahrge_type">
  <span class="checkmark"></span>
</label>

        <label>Discharge Date</label>
        <input class='txtdata' id ="disdate"  data-date-format="DD-MM-YYYY" type="text"  name='disdate' style="text-align: center;padding: 0px;" required readonly>
        
        <label>Status at discharge</label>
      <select class='txtdata disstatus' id ="disstatus"  name='disstatus' style="width: 100%;text-align: center;padding: 4px;" required>
      <option selected disabled value=''>Select</option>"
      <option value='Alive'>Alive</option>
      <option value='Dead'>Dead</option>
    </select>

        <label id ="dischargedlabel">Discharged to</label>
        <select class='txtdata' id ="disto"  name='disto' style="width: 100%;text-align: center;padding: 4px;" required>
        <option selected disabled value=''>Select</option>"
        <option value='Home'>Home</option>
        <option value='Other Facility'>Other Facility</option>
        <option value='LAMA'>LAMA</option>
        <option value='Absconded'>Absconded</option>
    </select> 






</div>

<div class="modal-footer" style="text-align: center;display: block;">
<div color='green' style="color:forestgreen;" id="message"></div>
<button type='submit' value='submit' class='btn btn-danger'  onclick="dischargepatient(this)">Discharge Patient</button>

<a type="button" class="btn btn-default" style=" color: black; " data-bs-dismiss="modal">Close</a>

</div>

</form>


  
  <script type="text/javascript">
$('.discahrge_type').change(function(){

  var disto = document.getElementById('disto');
  var disstatus = document.getElementById('disstatus');
  dischargedlabel = document.getElementById('dischargedlabel');
  if($(this).val() == 'medical'){

    // disto.style.display = "none";
    disto.innerHTML ="<option value='physical'>Physical</option><option value='system'>System</option>";
    disstatus.innerHTML ="<option value='Alive'>Alive</option>";
    dischargedlabel.innerHTML ="Reason of still in";
    // mortuary.style.display = "block";

  } else{
    disto.innerHTML ="<option value='Home'>Home</option><option value='Other Facility'>Other Facility</option><option value='LAMA'>LAMA</option><option value='Absconded'>Absconded</option>";
    disstatus.innerHTML ="<option value='Alive'>Alive</option><option value='Dead'>Dead</option>";
    dischargedlabel.innerHTML ="Discharge To";

  }
});



    
$('.disstatus').change(function(){
  
  var disto = document.getElementById('disto');
  
  if($(this).val() == 'Dead'){
    

    // disto.style.display = "none";
    disto.innerHTML ="<option value='Mortuary'>Mortuary</option>";
    // mortuary.style.display = "block";

  } else {
    disto.innerHTML ="<option value='Home'>Home</option><option value='Other Facility'>Other Facility</option><option value='LAMA'>LAMA</option><option value='Absconded'>Absconded</option>";
    // mortuary.style.display = "none";
  }
});
      $(document).ready(function() {
        $('.select2_discharge').select2({
      placeholder: 'Select',
            width: '100%',
      dropdownParent: $("#my_modal")
    } );
        $('.ddxname_discharge').select2({
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
  $('input[name="disdate"]').daterangepicker({
    singleDatePicker: true,
    timePicker: false,
    timePicker24Hour: false,
    autoUpdateInput: false,
    showButtonPanel: false,
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

    