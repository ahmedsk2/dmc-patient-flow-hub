<?php
require_once ('../dbconnect.php');
$consultant_id = $_REQUEST['bookId']; 
$formationSQL = "SELECT * FROM picupatients WHERE DISDATE IS NULL AND consultant_id = ?";
$stmt = $mysqli->prepare($formationSQL);
$stmt->bind_param("i", $consultant_id);
$stmt->execute();
$result1 = $stmt->get_result();
$activepicupatints = $result1->fetch_all(MYSQLI_ASSOC);
// var_dump($activepicupatints);


?>
<script>
function changeconsultant(button) {
    let activePatientIds = <?php echo json_encode(array_column($activepicupatints, 'ID')); ?>;
    let primary_modify = document.getElementById('primary_modify').value;
    if (primary_modify == "") {
        document.getElementById("message111").innerHTML = "<p style='color:red'>Fill All Please</p>";
        return false;
    } else {
        let data = { primary_modify: primary_modify, patient_ids: activePatientIds };
        button.disabled = true;
        button.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"><span class="sr-only">Loading...</span></div>';

        $.post('patients/dmc-patients-submit-changeconsultant.php', data)
            .done(function(response) {
                let result = JSON.parse(response);
                if (result.success) {
                    location.reload();
                } else {
                      setTimeout(function() {
                            button.disabled = false;
                            button.innerHTML = 'Change Consultant'
                    }, 3000); // Simulate a delay of 2 seconds
                    alert("Error occurred: " + result.message);
                }
            })
            .fail(function(jqXHR, textStatus, errorThrown) {
                // Error Handling
                alert("Request failed: " + textStatus + " - " + errorThrown);
                    setTimeout(function() {
                            button.disabled = false;
                            button.innerHTML = 'Change Consultant'
                    }, 3000); // Simulate a delay of 2 seconds
            });
    }
}
</script>

<form autocomplete="off">
    <label>Assign to Primary Consultant</label>
    <select class='txtdata' id="primary_modify" style="width: 100%; text-align: center; padding: 4px;" required>
        <?php
        $formationSQL = "SELECT * FROM members WHERE on_service = '1'";
        $result1 = $mysqli->query($formationSQL);
        $consultant = $result1->fetch_all(MYSQLI_ASSOC);

        foreach ($consultant as $con) {
            $selected = ($con['member_id'] == $activepicupatints[0]['consultant_id']) ? "selected" : "";
            echo "<option value='" . $con['member_id'] . "' $selected>" . $con['full_name'] . "</option>";
        }
        ?>
    </select>

    <div class="modal-footer" style="text-align: center; display: block;">
        <div style="color: forestgreen;" id="message111"></div>
        <button type="button" class="btn btn-success" onclick="changeconsultant(this)">Change Consultant</button>
        <button type="button" class="btn btn-default" style="color: black;" data-bs-dismiss="modal">Close</button>
    </div>
</form>