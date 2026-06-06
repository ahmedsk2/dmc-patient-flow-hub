<?php
require_once 'sidebar.php';

$userid = $user['member_id'];

if (in_array($user['position'], $access_PICU_endorsement)) {
    $formationSQL = "SELECT member_id, full_name FROM members WHERE position = '3'";
    $result1 = $mysqli->query($formationSQL);
} else {
    $formationSQL = "SELECT member_id, full_name FROM members WHERE member_id = ?";
    $stmt = $mysqli->prepare($formationSQL);
    $stmt->bind_param('i', $userid);
    $stmt->execute();
    $result1 = $stmt->get_result();
}
$consultants = $result1->fetch_all(MYSQLI_ASSOC);

if (!in_array($user['position'], $access_PICU_patients)) {
    echo "
    <div class='content-wrapper'>
        <section class='content'>
            <div class='container-fluid'>  
                <div class='alert alert-danger' role='alert'> You don't have permission to access this page. Contact your manager if you need to.</div>
            </div>
        </section>
    </div>";
    require 'footer.php';
    exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['signoff_btn'])) {
        csrf_verify();
        if (isset($_POST['consultid'])) {
            $consultid = $_POST['consultid'];
            require_consultation_access($consultid); // own consultation / Can-Manage / Admin only (S1)

            $query = "UPDATE consultations SET signoff_date=CURDATE() WHERE ID=?";
            $stmt = $mysqli->prepare($query);
            $stmt->bind_param('i', $consultid);

            if (!$stmt->execute()) {
                error_log(__FILE__ . ": consultation signoff failed: " . $mysqli->error); echo "A database error occurred.";
            } else {
                audit_log('consultation.signoff','consultations',$consultid);
                echo "<script>window.location.href = 'dmc-new-consultation.php';</script>";
                exit();
            }
        }
    }
    exit();  // Ensure this stops further processing
}
?>

<style>
    .hidden {
        display: none;
    }
    textarea {
        resize: none;
        overflow: hidden;
    }
</style>

<div class="content-wrapper">
    <!-- Add your content here -->
    <?php
    // Fetch speciality and member details once
    $specialities = [];
    $specialityResults = $mysqli->query("SELECT * FROM speciality");
    while ($row = $specialityResults->fetch_assoc()) {
        $specialities[$row['id']] = $row['specilaity'];
    }

    $consultationReasons = [];
    $reasonResults = $mysqli->query("SELECT * FROM consultation_reason");
    while ($row = $reasonResults->fetch_assoc()) {
        $consultationReasons[$row['id']] = $row['consultation_reason'];
    }

if (in_array($user['position'], $access_PICU_endorsement)) {
    $formationSQL = "SELECT * FROM consultations WHERE signoff_date IS NULL";
} else {
    $formationSQL = "SELECT * FROM consultations WHERE signoff_date IS NULL and consultant_id = $userid";
}

    $formationSQL = "SELECT * FROM consultations WHERE signoff_date IS NULL";
    $result1 = $mysqli->query($formationSQL);
    $activeConsultations = $result1->fetch_all(MYSQLI_ASSOC);
    ?>

    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark">Active Consultations</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item active">Active Consultations</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid">
            <div class="row">
                <div id="mypresentersTable" class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-user-tie text-info"></i> Active Consultations List: 
                            <?php
                            $sub = null;
                            $total = count($activeConsultations);
                            $subtotal = 0;

                            if (!in_array($user['position'], $access_PICU_endorsement)) {
                                foreach ($consultants as $consultant) {
                                    $consultantId = $consultant['member_id'];
                                    $subtotal = array_reduce($activeConsultations, function($carry, $consultation) use ($consultantId) {
                                        return $carry + ($consultation['consultant_id'] == $consultantId ? 1 : 0);
                                    }, 0);
                                    $sub = $subtotal . " out of ";
                                }
                            }
                            echo $sub . $total;
                            ?>
                            Consultation</h3>
                            <div id="addbtn" class='eachrow' style='float: right;'>
                                <?php if (in_array($user['position'], $access_PICU_patients)) { ?>
                                    <a class='btn btn-success' href='#new_consultation_modal' data-bs-toggle='modal' style='color: aliceblue; line-height: 2; padding: 0px 15px;'>New Consultation</a>
                                <?php } ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php

                                        // Function to get full name by ID
                                        function getFullNameById($id, $users) {
                                            foreach ($users as $user) {
                                                if ($user['member_id'] == $id) {
                                                    return $user['full_name'];
                                                }
                                            }
                                            return null;
                                        }

                                    foreach ($activeConsultations as $consultation) {
                                        $consultant_name = getFullNameById($consultation["consultant_id"], $consultants);
                                        if (is_null($consultation["signoff_date"])) {
                                            $decodedIndications = json_decode($consultation['indication']);
                                            ?>
                                            <div class='col-sm-3'>
                                                <div class='eachrow card' style='text-align: center;' id='row<?= htmlspecialchars($consultation['id'], ENT_QUOTES, 'UTF-8') ?>'>
                                                    <div style='margin: 2%; display: none;' class='eachcol id' scope='row'>
                                                        <input class='txtdata' type='hidden' name='id' id='id' value='<?= htmlspecialchars($consultation['id'], ENT_QUOTES, 'UTF-8') ?>' style='text-align: center;width: 85%;'>
                                                    </div>
                                                    <div style='margin: 2%; display: inline;' class='eachcol bed card-header' scope='row'>
                                                        <?php if (in_array($user['position'], $access_PICU_control)) { ?>
                                                            <a type='button' class='btn-tool' style='display: contents;' onclick='del(<?= htmlspecialchars(json_encode($consultation['id']), ENT_QUOTES, 'UTF-8') ?>)'><i class='fa fa-trash'></i></a>
                                                        <?php } ?>
                                                        <label style='text-align: center;margin-bottom: 0px;'>Consultation Location</label>
                                                        <input class='txtdata' name='current_location' placeholder='Current Location' value='<?= htmlspecialchars($consultation['current_location'], ENT_QUOTES, 'UTF-8') ?>' style='text-align: center;' disabled>
                                                        <label style='text-align: center;margin-bottom: 0px;'>Bed</label>
                                                        <input class='txtdata' name='bed' placeholder='Bed Number' value='<?= htmlspecialchars($consultation['BED'], ENT_QUOTES, 'UTF-8') ?>' style='text-align: center;' disabled>
                                                    </div>
                                                    <div style='margin: 2%; display: inline;' class='eachcol mrn'>
                                                        <label style='text-align: center;margin-bottom: 0px;'>MRN</label>
                                                        <input class='txtdata' name='mrn' value='<?= htmlspecialchars($consultation['MRN'], ENT_QUOTES, 'UTF-8') ?>' style='text-align: center;' disabled>
                                                    </div>
                                                    <div style='margin: 2%; display: inline;' class='eachcol name'>
                                                        <label style='text-align: center;margin-bottom: 0px;'>Patient Name</label>
                                                        <input class='txtdata' name='name' value='<?= htmlspecialchars($consultation['PNAME'], ENT_QUOTES, 'UTF-8') ?>' style='text-align: center;' disabled>
                                                    </div>
                                                    <div style='margin: 2%; display: inline;' class='eachcol age'>
                                                        <label style='text-align: center;margin-bottom: 0px;'>Age</label>
                                                        <input class='txtdata' name='age' value='<?= htmlspecialchars($consultation['age'], ENT_QUOTES, 'UTF-8') ?>' style='text-align: center;' disabled>
                                                    </div>
                                                    <div style='margin: 2%; display: inline;' class='eachcol consultationdate'>
                                                        <label style='text-align: center;margin-bottom: 0px;'>Consulted On</label>
                                                        <input class='txtdata' name='consultation_date' value='<?= htmlspecialchars($consultation['consultation_date'], ENT_QUOTES, 'UTF-8') ?>' style='text-align: center;' disabled>
                                                    </div>
                                                    <div style='margin: 2%; display: inline;' class='eachcol consultationfrom'>
                                                        <label style='text-align: center;margin-bottom: 0px;'>Consultation From</label>
                                                        <input class='txtdata' name='consultation_from' value='<?= htmlspecialchars($consultation['consultation_from'], ENT_QUOTES, 'UTF-8') ?>' style='text-align: center;' disabled>
                                                    </div>
                                                    <div style='margin: 2%; display: inline;' class='eachcol consultationtoservice'>
                                                        <label style='text-align: center;margin-bottom: 0px;'>Consultation To Service</label>
                                                        <?php
                                                        $consultationToService = is_numeric($consultation['consultation_to_service']) 
                                                            ? $specialities[$consultation['consultation_to_service']] 
                                                            : $consultation['consultation_to_service'];
                                                        ?>
                                                        <input class='txtdata' name='consultation_to_service' value='<?= htmlspecialchars($consultationToService, ENT_QUOTES, 'UTF-8') ?>' style='text-align: center;' disabled>
                                                    </div>
                                                    <div style='margin: 2%; display: inline;' class='eachcol consultationto'>
                                                        <label style='text-align: center;margin-bottom: 0px;'>Consulted Doctor</label>
                                                        <input class='txtdata' name='consultationto' value='<?= htmlspecialchars($consultant_name, ENT_QUOTES, 'UTF-8') ?>' style='text-align: center;' disabled>
                                                    </div>
                                                    <div style='margin: 2%; display: inline;' class='eachcol indications'>
                                                        <label style='text-align: center;margin-bottom: 0px;'>Indications</label>
                                                        <ul style='list-style-position: inside;'>
                                                            <?php
                                                            if (is_array($decodedIndications)) {
                                                                foreach ($decodedIndications as $value) {
                                                                    echo "<li>" . htmlspecialchars($consultationReasons[$value], ENT_QUOTES, 'UTF-8') . "</li>";
                                                                }
                                                            }
                                                            ?>
                                                        </ul>
                                                    </div>
                                                    <div style='margin: 2%; display: inline;' class='eachcol other_indications'>
                                                        <label style='text-align: center;margin-bottom: 0px;'>Other Indications</label>
                                                        <p style='text-align: center;'><?= htmlspecialchars($consultation['other_ind'], ENT_QUOTES, 'UTF-8') ?></p>
                                                    </div>
                                                    <?php if ($user['member_id'] == $consultation['consultant_id'] || $user['manage_patient'] == '1') { ?>
<form method='post' name='signoff' action='dmc-new-consultation.php' id="signoff_form_<?= htmlspecialchars($consultation['id'], ENT_QUOTES, 'UTF-8') ?>">
<?php echo csrf_field(); ?>
    <input type='hidden' name='consultid' value='<?= htmlspecialchars($consultation['id'], ENT_QUOTES, 'UTF-8') ?>'>
    <button type='submit' name='signoff_btn' class='btn btn-danger' style='color: aliceblue;line-height: 2;margin-top: 3%;padding: 0px 10%;width: 100%;' onclick='return signOff(this)'>Sign Off</button>
</form>
                                                        <a class='btn btn-info' href='#details_modal' data-book-id='<?= htmlspecialchars($consultation['id'], ENT_QUOTES, 'UTF-8') ?>' data-bs-toggle='modal' style='color: aliceblue;line-height: 2;margin-top: 3%;padding: 0px 10%;width: 100%;'>Modify</a>
                                                    <?php } ?>
                                                </div>
                                            </div>
                                            <?php
                                        }
                                    }
                                
                                ?> 
                            </div>
                        </div>
                    </div>
                </div>
            </div> <!--row -->
        </div>
    </section>

    <!-- Modal patient details -->
    <div class="modal" id="details_modal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">Consultation Details</h4>
                    <button type="button" class="close" data-bs-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="cdetailsdiv"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for admitting patient -->
    <div class="modal" id="new_consultation_modal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">Add New Consultation</h4>
                    <button type="button" class="close" data-bs-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <p>Kindly complete the required information</p>
                    <form autocomplete="off" method="POST">
                        <label>Consultation Location</label>
                        <select class='' id='current_location_new' style='width: 100%; padding: 4px; text-align: center;' required>
                            <option value='Ward'>Ward</option>
                            <option value='ICU'>ICU</option>
                            <option value='ER'>ER</option>
                        </select>
                        <label>Bed Number</label>
                        <input class='' id='bed_new' value='' style='text-align: center;' required>
                        <label>MRNs</label>
                        <input class='' id='mrn_new' value='' style='text-align: center;' required>
                        <label>Patient Name</label>
                        <input class='' id='pname_new' value='' style='text-align: center;' required>
                        <label>Age</label>
                        <input class='' id='age_new' style='text-align: center;' required>
                        <label>Consultation date</label>
                        <input value='' class='' id="consultdate_new" data-date-format="DD-MM-YYYY" type="text" name='consultdate_new' style="text-align: center; padding: 0px;" required readonly>
                        <label>Consultation Indication</label>
                        <select class='txtdata1 select2' style='width: 100%;' oninput='auto_grow(this)' multiple='multiple' id='indication_new' required>
                            <?php
                            foreach ($consultationReasons as $id => $reason) {
                                echo "<option value='" . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . "</option>";
                            }
                            ?>
                        </select>
                        <div id="other" style="display: none; margin-top:2%;">
                            <label>Write Indications</label>
                            <input class='' id='other_indication' style='text-align: center;'>
                        </div>
                        <label>Consultation From</label>
                        <select class='select2' style='width: 100%;' id='consultfrom_new' required>
                            <option disabled selected>Select</option>
                            <?php
                            foreach ($specialities as $speciality) {
                                echo "<option value='" . htmlspecialchars($speciality, ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($speciality, ENT_QUOTES, 'UTF-8') . "</option>";
                            }
                                  $formationSQL = "SELECT * FROM other_specialities";
                            $result1 = $mysqli->query($formationSQL);
                            $otherspeciality = $result1 -> fetch_all(MYSQLI_ASSOC);
                                foreach($otherspeciality as $osp)
                                {
                                    echo "<option value='".htmlspecialchars($osp['specilaity'], ENT_QUOTES, 'UTF-8')."'>".htmlspecialchars($osp['specilaity'], ENT_QUOTES, 'UTF-8')."</option>";

                                }
                            ?>
                        </select>
                        <label>Consultation To Service</label>
                        <select class='select2 txtdata11' style="width: 100%;" id='consultation_to_service' name='consultation_to_service' style='text-align: center;' required>
                            <option selected disabled>Select</option>
                            <?php
                            foreach ($specialities as $id => $speciality) {
                                echo "<option value='" . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($speciality, ENT_QUOTES, 'UTF-8') . "</option>";
                            }
                                                            foreach($otherspeciality as $osp)
                                {
                                    echo "<option value='".htmlspecialchars($osp['specilaity'], ENT_QUOTES, 'UTF-8')."'>".htmlspecialchars($osp['specilaity'], ENT_QUOTES, 'UTF-8')."</option>";

                                }
                            ?>
                        </select>
                        <div id="dropdown" style="display: none; margin-top:2%;">
                            <label>Consultation To</label>
                            <select class='select2_modify txtdata' id='consultant_new' name='consultant_new' style='text-align: center;' required></select>
                        </div>
                        <div id='messsssage'></div>
                </div>
                <div class="modal-footer">
                    <button type='button' class='btn btn-success' onclick='addconsult(this)'>Add Consultation</button>
                    <button type="button" class="btn btn-default" style="color: black;" data-bs-dismiss="modal">Close</button>
                </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require 'footer.php'; ?>

<!-- Select2 JS -->
<script src="vendor/select2/4.0.13/select2.min.js"></script>
<!-- Daterangepicker JS -->
<script src="vendor/moments.js/2.30.1/moment.min.js"></script>
<script src="vendor/daterangepicker/3.1.0/daterangepicker.min.js"></script>

<script>

function signOff(button) {
    // W4: confirm sign-off with the patient identity from the consultation row.
    var idy = window.dmcRowIdentity(button);
    if (!window.dmcConfirm("Sign off this consultation?", idy.name, idy.mrn)) { return false; }
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
    return false;
}


function auto_grow(element) {
    element.style.height = "5px";
    element.style.height = (element.scrollHeight) + "px";
}

function del(value) {
    var rowname = "row" + value;
    var row = document.getElementById(rowname);

    // W4: confirm with the patient identity; W1: only remove the row on confirmed success.
    var idy = window.dmcRowIdentity(row);
    if (!window.dmcConfirm("Permanently DELETE this consultation?", idy.name, idy.mrn)) {
        return false;
    }

    var id = value;
    data = { id: id };
    $.post('consultations/dmc-consultation-delete.php', data)
      .done(function(resp){
        if (window.dmcOk(resp)) { row.style.display = "none"; }
        else { alert("Delete failed — the consultation was NOT removed. Please try again."); }
      })
      .fail(function(){ alert("Delete failed (network/server error) — the consultation was NOT removed."); });
}

function addconsult(button) {
   
    var bed_new = document.getElementById('bed_new').value;
    var mrn_new = document.getElementById('mrn_new').value;
    var pname_new = document.getElementById('pname_new').value;
    var age_new = document.getElementById('age_new').value;
    var consultdate_new = document.getElementById('consultdate_new').value;
    var consultfrom_new = document.getElementById('consultfrom_new').value;
    var current_location_new = document.getElementById('current_location_new').value;
    var entered_by = <?= json_encode($user['member_id']) ?>;
    var checked = document.querySelectorAll('#indication_new :checked');
    var indication_new = [...checked].map(option => option.value);
    var consultation_to_service = document.getElementById('consultation_to_service').value;
    var consultant_new = isNaN(consultation_to_service) ? 'N/A' : document.getElementById('consultant_new').value;
    var parent = document.getElementById('messsssage');
    var other_indication = indication_new.includes('0') ? document.getElementById('other_indication').value : "none";

    if (!bed_new || !mrn_new || !pname_new || !age_new || !consultdate_new || !consultfrom_new || !indication_new.length || !current_location_new || !consultation_to_service || (!consultant_new && isNaN(consultation_to_service)) || !other_indication) {
        parent.innerHTML = "<p style='color:red'>Please fill all required information</p>";
        return false;
    }
 button.disabled = true;
    button.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"><span class="sr-only">Loading...</span></div>';
    data = { bed_new, mrn_new, pname_new, current_location_new, consultant_new, age_new, consultdate_new, consultfrom_new, entered_by, indication_new, other_indication, consultation_to_service };
    $.post('consultations/dmc-consultation-add.php', data, function(data) {
        $(parent).html(data);
            // Simulate an AJAX call to transfer the patient
    setTimeout(function() {
            button.disabled = false;
            button.innerHTML = 'Add Consultation'
     }, 3000); // Simulate a delay of 2 seconds
        return false;
    });
}

$(document).ready(function() {
    $('.select2').select2({
        placeholder: 'Select',
    });

    $('.txtdata1').on('change', function() {
        var indication_new = Array.from(document.getElementById("indication_new").selectedOptions).map(option => option.value);
        if (indication_new.includes('0')) {
            document.getElementById("other").style.display = 'block';
        }
    });

    $('.txtdata11').on('change', function() {
        var consultation_to_service = document.getElementById("consultation_to_service").value;
        document.getElementById("dropdown").style.display = "block";
        $.post('consultations/dmc-consultation-specialty-dropdown.php', { consultation_to_service }, function(data) {
            $("#dropdown").html(data);
        });
    });

    $('#details_modal').on('show.bs.modal', function(e) {
        var bookId = $(e.relatedTarget).data('book-id');
        $.post('consultations/dmc-consultation-details.php', { bookId }, function(data) {
            $('#cdetailsdiv').html(data);
        });
    });

    $("textarea").each(function() {
        $(this).height($(this)[0].scrollHeight);
    });

    $('input[name="consultdate_new"]').daterangepicker({
        singleDatePicker: true,
        timePicker: false,
        showDropdowns: true,
        autoUpdateInput: false,
        autoApply: true,
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
