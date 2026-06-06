<?php
require_once __DIR__ . '/../guard.php'; require_role([0, 2, 3, 4]); require_patient_access($_REQUEST['myBookId1'] ?? 0); // owner / Can-Manage / Admin only

require ('../dbconnect.php');
$id = $_REQUEST['myBookId1']; 

$formationSQL = "SELECT * FROM speciality";
$result1 = $mysqli->query($formationSQL);
$speciality = $result1->fetch_all(MYSQLI_ASSOC);

$formationSQL = "SELECT * FROM other_specialities";
$result1 = $mysqli->query($formationSQL);
$other_specialities = $result1->fetch_all(MYSQLI_ASSOC);
?>

    <form method="post" autocomplete="off" action="dmc-patients.php">
    <?php echo csrf_field(); ?>
        <input type="hidden" name="id" value="<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>">
        <label>Transfer To Specialty</label>
        <select class='select2_modify txtdata' style="width: 100%;" id='specialty_transfer' name='specialty_transfer' style='text-align: center;' required>
            <option selected disabled> Select</option>
            <?php
            foreach ($speciality as $spec) {
                echo "<option value='".htmlspecialchars($spec['id'], ENT_QUOTES, 'UTF-8')."'>".htmlspecialchars($spec['specilaity'], ENT_QUOTES, 'UTF-8')."</option>";
            }
            foreach ($other_specialities as $ospec) {
                echo "<option value='".htmlspecialchars($ospec['specilaity'], ENT_QUOTES, 'UTF-8')."'>".htmlspecialchars($ospec['specilaity'], ENT_QUOTES, 'UTF-8')."</option>";
            }
            ?>
        </select>
        <div id="dropdown" style="display: none; margin-top:2%;">
            <select class='select2_modify txtdata' id='constulant_transfer' name='constulant_transfer' style='text-align: center;' required> </select>
        </div>
        <div class="modal-footer" style="text-align: center; display: block;">
            <button type='button' value='submit' class='btn btn-success' name='transfer_pt_btn' onclick='transferPtBtn(this)'>Transfer Patient</button>
            <button type="button" class="btn btn-default" style="color: black;" data-bs-dismiss="modal">Close</button>
        </div>
    </form>

    <script type="text/javascript">
    function transferPtBtn(button) {
        // W4: confirm transferring the patient off the current service.
        if (!window.dmcConfirm("Transfer this patient to another specialty/consultant?", "", "")) { return; }
        // Disable the button and show loading spinner
        button.disabled = true;
        button.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"><span class="sr-only">Loading...</span></div>';
        // Create a hidden input with the same name as the button to include it in the form submission
        var hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = button.name;
        hiddenInput.value = button.value;
        button.form.appendChild(hiddenInput);

        // Allow the form to be submitted
        button.form.submit();
    }

    $(document).ready(function() {
        $('.select2_modify').select2({
            placeholder: "Select",
                  placeholder: 'Select',
      width: '100%',
      dropdownParent: $("#transfer_modal")
        });

        $('.txtdata').on('change', function() {
            var specialty_transfer = document.getElementById("specialty_transfer").value;
            document.getElementById("dropdown").style.display = "block";
            var data = {specialty_transfer: specialty_transfer};
            $.post('patients/dmc-specialty-transfer-dropdown.php', data, function(data) {
                $("#dropdown").html(data);
            });
        });
    });
    </script>

