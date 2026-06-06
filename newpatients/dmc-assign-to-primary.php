<?php
require_once __DIR__ . '/../guard.php'; require_login();

session_start();
require_once ('../dbconnect.php');
   $id = $_REQUEST['bookId']; 
  

?>

<form autocomplete='off' id='assignconsultant' method='POST' action='dmc-new-admissions.php'>
<input class='txtdata' type='hidden' name='patientid1' value='<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?> style='text-align: center;' required>


<label>Primary Consultant</label>
<select class='txtdata'  name='consultantid'  style="width: 100%;text-align: center;padding: 4px;" required>

<?php
$formationSQL = "SELECT * FROM members WHERE on_service = '1'";
$result1 = $mysqli->query($formationSQL);
$consultant = $result1 -> fetch_all(MYSQLI_ASSOC);

foreach ($consultant as $con){
  echo "<option value='".htmlspecialchars($con['member_id'], ENT_QUOTES, 'UTF-8')."'>".htmlspecialchars($con['full_name'], ENT_QUOTES, 'UTF-8')."</option>";
}
?>


</select>
<div style=' margin: 2%; ' class='eachcol newpt'>
<input style='width: auto;' class='txtdata'  type='checkbox' name='newpt' value='1'>
<label for='newpt' style=' display: contents; '> New Patient?</label>
</div>


<div color='green' style="color:forestgreen;" id="message"></div>
<div class="modal-footer">

<button  class='btn btn-success' type='submit' name='assign_to_consultant_btn' onclick='assign_to_consultant_btn(this)' style='color: aliceblue; line-height: 2;padding: 0px 15px;' form='assignconsultant' value='Submit'>Assign to Consultant</button>
<a type="button" class="btn btn-default" style=" color: black; " data-bs-dismiss="modal">Close</a>

</div>

</form>


  
  <script type="text/javascript">
      function assign_to_consultant_btn(button) {
    // Disable the button and show loading spinner
    button.disabled = true;
    button.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"><span class="sr-only">Loading...</span></div>';
    // Allow the form to be submitted
    // Create a hidden input with the same name as the button to include it in the form submission
    var hiddenInput = document.createElement('input');
    hiddenInput.type = 'hidden';
    hiddenInput.name = button.name;
    hiddenInput.value = button.value;
    button.form.appendChild(hiddenInput);

    // Allow the form to be submitted
    button.form.submit();
}


    </script>

    