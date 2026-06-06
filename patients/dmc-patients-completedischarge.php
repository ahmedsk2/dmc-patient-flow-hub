<?php
require_once __DIR__ . '/../guard.php'; require_login();

require_once ('../dbconnect.php');
   $id = $_REQUEST['bookId']; 
  $userid = $_REQUEST['userid']; 
  
   $formationSQL = "SELECT * FROM picupatients WHERE ID=?";
   $stmt = $mysqli->prepare($formationSQL);
   $stmt->bind_param("i", $id);
   $stmt->execute();
   $result1 = $stmt->get_result();
   $patient = $result1 -> fetch_array(MYSQLI_ASSOC);
// print_r($activepicupatints);

// echo "<div id='pdetailsdiv'>ahmed<div>";
?>

<script>

function completedischargepatient(button) {
 
// var rowname= "row";
// rowname+=value;
// row = document.getElementById(rowname);
//   var id = value;+
id_modify=document.getElementById('id_modify').value;
var disdate=document.getElementById('disdate').value;
var disstatus=document.getElementById('disstatus').value;
var disto=document.getElementById('disto').value;
var userid=<?php echo json_encode($userid); ?>;
// alert (discahrge_type);

if( disdate=="" || disstatus=="" || disto==""  ){
            //do something
            // alert("missing");
            document.getElementById("message").innerHTML ="<p style='color:red'>Fill All Please</p>";
            return false;
            //this will not submit your form

            

}
else{
    // W4: confirm closing the patient file (complete discharge) with the patient identity.
    if (!window.dmcConfirm("Complete discharge (close the patient file)?\nThis finalizes the record and removes the patient from the active census.", <?php echo json_encode($patient['PNAME']); ?>, <?php echo json_encode($patient['MRN']); ?>)) { return false; }
// alert("test");
//  return false;
button.disabled = true;
    button.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"><span class="sr-only">Loading...</span></div>';

          data = {id_modify:id_modify,disdate:disdate,disstatus:disstatus, disto:disto ,userid:userid };
        //  alert("not missing");
        
            $.post('patients/dmc-patients-complete-discharge-submit.php', data)
              .done(function(data){
                $('#message').html(data);
                // W1: only reload (treat as done) when the server confirms success.
                if (window.dmcOk(data)) {
                  location.reload();
                } else {
                  button.disabled = false;
                  button.innerHTML = 'Discharge Patient';
                }
              })
              .fail(function(){
                $('#message').html("<p style='color:red'>Complete discharge failed (server error). Please try again.</p>");
                button.disabled = false;
                button.innerHTML = 'Discharge Patient';
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


</select>
<h3><label>Discharge Details</label></h3>

        <label>Close File Date</label>
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
<button type='submit' value='submit' class='btn btn-danger'  onclick="return completedischargepatient(this)">Discharge Patient</button>

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

    