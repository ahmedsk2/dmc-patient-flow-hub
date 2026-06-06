<?php

require ('dbconnect.php');
   $id = $_REQUEST['bookId']; 


   $formationSQL = "SELECT * FROM picupatients_temp WHERE ID='".$id."'";
   $result1 = $mysqli->query($formationSQL);
   $patient = $result1 -> fetch_array(MYSQLI_ASSOC);

   $formationSQL = "SELECT * FROM countries";
   $result1 = $mysqli->query($formationSQL);
   $countries = $result1 -> fetch_all(MYSQLI_ASSOC);
// print_r($activepicupatints);

// echo "<div id='pdetailsdiv'>ahmed<div>";
?>

<form method="post" autocomplete="off" action="dmc-old-patients.php">
<label>MRNs</label>
<input class='txtdata' name='mrn_modify' style='text-align: center;' value='<?php echo  $patient['MRN']; ?>' required>
<label>Patient Name</label>
<input class='txtdata' name='pname_modify'  style='text-align: center;' value='<?php echo  $patient['PNAME']; ?>' required>
<label>Age</label>
<input class='txtdata' name='age_modify' style='text-align: center;' value='<?php echo  $patient['age']; ?>' required>
<label>Gender</label>
<select class='txtdata' name='gender_modify' style='text-align: center;' value='<?php echo  $patient['gender']; ?>' required>
<option value='Male'>Male</option>
<option value='Female'>Female</option>
</select>
<label>Nationality</label>
///////////////////////////////////////////////
<select class='select2_modify txtdata' name='nationality_modify' style='text-align: center;' required>
 <?php  

foreach($countries as $country)
    echo"
    <option value='".$country['name']."'>".$country['name']."</option>";
  ?>

</select>

<label>Admittion date</label>
<input  class='txtdata' id="admdate"  data-date-format="DD-MM-YYYY" type="text"  name='admdate_modify' style="text-align: center;padding: 0px;"  value='<?php echo  $patient['ADMDATE']; ?>' required>

/////////////////////////////////////////////////////
<label>Admitted from</label>
<select class='txtdata' name="admfrom" style="width: 100%;text-align: center;padding: 4px;" required>
<option value='ER'>ER</option>
<option value='ICU'>ICU</option>
<option value='OPD'>OPD</option>
<option value='OR'>OR</option>
<option value='Referral'>Referral</option>
</select>

//////////////////////////////////////////////
<label>Primary Consultant</label>
<select class='select2_modify txtdata' name="primary" style="width: 100%;text-align: center;padding: 4px;" required>

<?php
$formationSQL = "SELECT * FROM members";
$result1 = $mysqli->query($formationSQL);
$consultant = $result1 -> fetch_all(MYSQLI_ASSOC);

foreach ($consultant as $con){
  echo "<option value='".$con['member_id']."'>".$con['full_name']."</option>";
}
?>


</select>

<label>Diagnosis</label>
<select class='txtdata ddxname_modify form-control' style='width: 100%;'  oninput='auto_grow(this)'  multiple='multiple' name='admissiondiagnosis_modify[]' required>
</select>

<label>Current location</label>
<select class='txtdata' name='current_location_modify' style="width: 100%;text-align: center;padding: 4px;" required>
<?php
                        
                            $formationSQL =  "SELECT DISTINCT(current_location) FROM picupatients";
                            $result1 = $mysqli->query($formationSQL);
                            $current_location = $result1 -> fetch_all(MYSQLI_ASSOC);
                            
                            foreach ($current_location as $a){
                                echo  "<option value='".$a['current_location']."'>".$a['current_location']."</option>";
                            }
                        ?>
</select>

<label>Long term?</label>
<select class='txtdata' name='longterm_modify' style="width: 100%;text-align: center;padding: 4px;">
<?php
                        
                            $formationSQL =  "SELECT DISTINCT(longterm) FROM picupatients";
                            $result1 = $mysqli->query($formationSQL);
                            $longterm = $result1 -> fetch_all(MYSQLI_ASSOC);
                            
                            foreach ($longterm as $a){
                                echo  "<option value='".$a['longterm']."'>".$a['longterm']."</option>";
                            }
                        ?>
</select>

<label>Trans Discharge status</label>
<select class='txtdata' name='trans_discharge_modify' style="width: 100%;text-align: center;padding: 4px;" required>
<?php
                        
                            $formationSQL =  "SELECT DISTINCT(trans_discharge) FROM picupatients";
                            $result1 = $mysqli->query($formationSQL);
                            $trans_discharge = $result1 -> fetch_all(MYSQLI_ASSOC);
                            
                            foreach ($trans_discharge as $a){
                                echo  "<option value='".$a['trans_discharge']."'>".$a['trans_discharge']."</option>";
                            }
                        ?>
</select>

<label>Discharge date</label>
<input  class='txtdata' id ="admdate"  data-date-format="DD-MM-YYYY" type="text"  name='med_disdate_modify' style="text-align: center;padding: 0px;" required>
<label>Close file date</label>
<input  class='txtdata' id ="admdate"  data-date-format="DD-MM-YYYY" type="text"  name='disdate_modify' style="text-align: center;padding: 0px;" required>
<label>Delay</label>
<select class='txtdata' name='delay_modify' style="width: 100%;text-align: center;padding: 4px;">
<?php
                        
                            $formationSQL =  "SELECT DISTINCT(delay) FROM picupatients";
                            $result1 = $mysqli->query($formationSQL);
                            $delay = $result1 -> fetch_all(MYSQLI_ASSOC);
                            
                            foreach ($delay as $a){
                                echo  "<option value='".$a['delay']."'>".$a['delay']."</option>";
                            }
                        ?>
</select>

<label>Discharge to</label>

<select class='txtdata' id ="disto" name='disto_modify' style="width: 100%;text-align: center;padding: 4px;" required>
<?php
                        
                            $formationSQL =  "SELECT DISTINCT(DISTO) FROM picupatients";
                            $result1 = $mysqli->query($formationSQL);
                            $disto = $result1 -> fetch_all(MYSQLI_ASSOC);
                            
                            foreach ($disto as $a){
                                echo  "<option value='".$a['DISTO']."'>".$a['DISTO']."</option>";
                            }
                        ?>
</select>

<label>Mortality</label>
<select class='txtdata' name='mortality_modify' style="width: 100%;text-align: center;padding: 4px;" required>
<option value='Alive'>Alive</option>
<option value='Dead'>Dead</option>
</select>

</div>

<div class="modal-footer" style="text-align: center;display: block;">
<button type='submit' value='submit' class='btn btn-success' name='modif_pt_btn'>Modify Patient</button>

<button type="button" class="btn btn-default" style=" color: black; " data-dismiss="modal">Close</button>

</div>

</form>


  
  <script type="text/javascript">
      $(document).ready(function() {
        $('.select2_modify').select2({
      placeholder: 'Select',
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

      

$(function() {
  $('input[name="admdate_modify"]').daterangepicker({
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

<script>
$(function() {
  $('input[name="admdate_modify"]').daterangepicker({
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

$(function() {
  $('input[name="disdate_modify"]').daterangepicker({
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

$(function() {
  $('input[name="med_disdate_modify"]').daterangepicker({
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
    