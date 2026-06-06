<?php
session_start();
require('../dbconnect.php');
$id = $_REQUEST['bookId'];
$userid = $_REQUEST['userid'];

$formationSQL = "SELECT * FROM picupatients WHERE ID='" . $id . "'";
$result1 = $mysqli->query($formationSQL);
$patient = $result1->fetch_array(MYSQLI_ASSOC);

$formationSQL = "SELECT * FROM countries";
$result1 = $mysqli->query($formationSQL);
$countries = $result1->fetch_all(MYSQLI_ASSOC);
?>

<script>
function dischargepatient(button) {
  var id_modify = document.getElementById('id_modify').value;
  var bed_modify = document.getElementById('bed_modify').value;
  var mrn_modify = document.getElementById('mrn_modify').value;
  var pname_modify = document.getElementById('pname_modify').value;
  var gender_modify = document.getElementById('gender_modify').value;
  var age = document.getElementById('age_modify').value;
  var nationality_modify = document.getElementById('nationality_modify').value;
  var admdate = document.getElementById('admdate_modify').value;
  var admfrom_modify = document.getElementById('admfrom_modify').value;

  var checked = document.querySelectorAll('#admissiondiagnosis_modify :checked');
  var admissiondiagnosis_modify = [...checked].map(option => option.value);
  var userid = <?php echo $userid; ?>;

  var disdate = document.getElementById('disdate').value;
  var disstatus = document.getElementById('disstatus').value;
  var disto = document.getElementById('disto').value;

  if (id_modify == "" || bed_modify == "" || mrn_modify == "" || pname_modify == "" || gender_modify == "" ||
    nationality_modify == "" || admdate == "" || age == "" || admfrom_modify == "" || admissiondiagnosis_modify == "" ||
    disdate == "" || disstatus == "" || disto == "") {
    document.getElementById("message").innerHTML = "<p style='color:red'>Fill All Please</p>";
    return false;
  } else {
    
    button.disabled = true;
    button.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"><span class="sr-only">Loading...</span></div>';
    data = {
      id_modify: id_modify,
      bed_modify: bed_modify,
      mrn_modify: mrn_modify,
      gender_modify: gender_modify,
      age: age,
      pname_modify: pname_modify,
      nationality_modify: nationality_modify,
      admdate: admdate,
      admfrom_modify: admfrom_modify,
      admissiondiagnosis_modify: admissiondiagnosis_modify,
      disdate: disdate,
      disstatus: disstatus,
      disto: disto,
      userid: userid
    };
    $.post('patients/dmc-patients-icu-discharge-submit.php', data, function(data) {
      $('#message').html(data);
          setTimeout(function() {
            button.disabled = false;
            button.innerHTML = 'Discharge Patient'
     }, 3000); // Simulate a delay of 2 seconds
                          location.reload();
    });
  }
}
</script>

<form autocomplete="off">
  <input type="hidden" id='id_modify' value='<?php echo $id; ?>'>
  <label> In <?php echo $patient['current_location']; ?> Bed Number</label>
  <input class='txtdata' id='bed_modify' value='<?php echo $patient['BED']; ?>' style='text-align: center;' required>
  <label>MRNs</label>
  <input class='txtdata' id='mrn_modify' value='<?php echo $patient['MRN']; ?>' style='text-align: center;' required>
  <label>Patient Name</label>
  <input class='txtdata' id='pname_modify' value='<?php echo $patient['PNAME']; ?>' style='text-align: center;' required>
  <label>Age</label>
  <input class='txtdata' id='age_modify' value='<?php echo $patient['age']; ?>' style='text-align: center;' required>
  <label>Gender</label>
  <select class='txtdata' id='gender_modify' style='text-align: center;' required>
    <option selected value='<?php echo $patient['gender']; ?>'> <?php echo $patient['gender']; ?></option>
    <option value='Male'>Male</option>
    <option value='Female'>Female</option>
    <option value='Unknown'>Unknown</option>
  </select>
  <label>Nationality</label>
  <select class='select2_discharge1 txtdata' id='nationality_modify' style='text-align: center;' required>
    <option selected value='<?php echo $patient['nationality']; ?>'> <?php echo $patient['nationality']; ?></option>
    <?php foreach ($countries as $country): ?>
      <option value='<?php echo $country['name']; ?>'><?php echo $country['name']; ?></option>
    <?php endforeach; ?>
  </select>
  <label>Admission Date</label>
  <input value='<?php echo $patient['ADMDATE']; ?>' class='txtdata' id="admdate_modify" data-date-format="YYYY-MM-DD" type="text" name='admdate' style="text-align: center;padding: 0px;" required disabled>
  <label>Admitted from</label>
  <select class='txtdata' id="admfrom_modify" style="width: 100%;text-align: center;padding: 4px;" required>
    <option selected value='<?php echo $patient['ADMFROM']; ?>'><?php echo $patient['ADMFROM']; ?></option>
    <option value='ER'>ER</option>
    <option value='ICU'>ICU</option>
    <option value='OPD'>OPD</option>
    <option value='OR'>OR</option>
    <option value='Referral'>Referral</option>
  </select>

  <?php
  $decodedadmissiondx = json_decode($patient['admissiondiagnosis']);
  ?>

  <label>Diagnosis</label>
  <select class='txtdata ddxname_discharge1 form-control' style='width: 100%;' multiple='multiple' id='admissiondiagnosis_modify' required>
    <?php
    if (is_array($decodedadmissiondx)) {
      foreach ($decodedadmissiondx as $value) {
        $formationSQL = "SELECT * FROM icd10 WHERE id='" . $value . "'";
        $result1 = $mysqli->query($formationSQL);
        $dxlist = $result1->fetch_array(MYSQLI_ASSOC);
        echo '<option selected value="' . $dxlist['id'] . '">' . $dxlist['name'] . '</option>';
      }
    }
    ?>
  </select>

  <h3><label>Discharge Details</label></h3>
  <label>Discharge Date</label>
  <input class='txtdata' id="disdate" data-date-format="YYYY-MM-DD" type="text" name='disdate' style="text-align: center;padding: 0px;" required readonly>
  <label>Status at discharge</label>
  <select class='txtdata disstatus' id="disstatus" name='disstatus' style="width: 100%;text-align: center;padding: 4px;" required>
    <option selected disabled value=''>Select</option>
    <option value='Alive'>Alive</option>
    <option value='Dead'>Dead</option>
  </select>
  <label>Discharged to</label>
  <select class='txtdata' id="disto" name='disto' style="width: 100%;text-align: center;padding: 4px;" required>
    <option selected disabled value=''>Select</option>
    <option value='Home'>Home</option>
    <option value='Mortuary'>Mortuary</option>
    <option value='Other Facility'>Other Facility</option>
  </select>

  <div class="modal-footer" style="text-align: center;display: block;">
    <div id="message" style="color: forestgreen;"></div>
    <button type='submit' class='btn btn-danger' onclick="dischargepatient(this)">Discharge Patient</button>
    <button type="button" class="btn btn-default" style="color: black;" data-bs-dismiss="modal">Close</button>
  </div>
</form>

<script type="text/javascript">
  $('.disstatus').change(function() {
    var disto = document.getElementById('disto');
    if ($(this).val() == 'Dead') {
      disto.innerHTML = "<option value='Mortuary'>Mortuary</option>";
    } else {
      disto.innerHTML = "<option value='Home'>Home</option><option value='Other Facility'>Other Facility</option><option value='LAMA'>LAMA</option><option value='Absconded'>Absconded</option>";
    }
  });

  $(document).ready(function() {
    $('.select2_discharge1').select2({
      placeholder: 'Select',
      allowClear: true,
      width: '100%'
    });

    $('.ddxname_discharge1').select2({
      placeholder: 'Select',
      minimumInputLength: 4,
      ajax: {
        url: '../fetchicd10.php',
        dataType: 'json',
        delay: 250,
        data: function (params) {
          return {
            searchTerm: params.term
          };
        },
        processResults: function (response) {
          return {
            results: response
          };
        },
        cache: true
      }
    });
  });

  $(function() {
    $('input[name="disdate"], input[name="admdate"]').daterangepicker({
      singleDatePicker: true,
      autoUpdateInput: false,
      autoApply: true,
      showDropdowns: true,
      minYear: 2010,
      maxYear: parseInt(moment().format('YYYY'), 10),
      locale: {
        format: 'YYYY-MM-DD'
      }
    }).on("apply.daterangepicker", function(e, picker) {
      picker.element.val(picker.startDate.format(picker.locale.format));
    });
  });
</script>
