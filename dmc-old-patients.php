<?php
require_once 'sidebar.php';

// SEC-20: the import handlers below run before the page's Admin gate, so enforce it here.
if ((isset($_POST['confirm_pt_btn']) || isset($_POST['add_pt_btn'])) && !in_array($user['position'], $access_PICU_control)) {
    http_response_code(403);
    exit('Forbidden.');
}

if (isset($_POST['confirm_pt_btn'])) {
    csrf_verify();
    $formationSQL = "SELECT * FROM picupatients_temp";
    $result1 = $mysqli->query($formationSQL);
    $temppicupatints = $result1->fetch_all(MYSQLI_ASSOC);

    foreach ($temppicupatints as $t) {
        $tempid = $t['ID'];
        // R2: copy into the live table and remove from staging atomically. Previously the DELETE
        // ran even when the INSERT failed, which could LOSE the staged patient.
        $mysqli->begin_transaction();
        $ok = true;

        $sql = "INSERT INTO picupatients (MRN, PNAME, ADMDATE, DISDATE, ADMFROM, DISTO, MORTALITY, admissiondiagnosis, nationality, gender, age, consultant_id, trans_discharge, current_location, med_DISDATE, delay, longterm)
                SELECT MRN, PNAME, ADMDATE, DISDATE, ADMFROM, DISTO, MORTALITY, admissiondiagnosis, nationality, gender, age, consultant_id, trans_discharge, current_location, med_DISDATE, delay, longterm
                FROM picupatients_temp WHERE ID=?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('i', $tempid);
        if ($stmt->execute() !== TRUE) {
            error_log("import_confirm INSERT failed (tempid $tempid): " . $mysqli->error);
            $ok = false;
        }

        if ($ok) {
            $sql = "DELETE FROM picupatients_temp WHERE ID=?";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param('i', $tempid);
            if ($stmt->execute() !== TRUE) {
                error_log("import_confirm DELETE failed (tempid $tempid): " . $mysqli->error);
                $ok = false;
            }
        }

        if ($ok) {
            $mysqli->commit();
            audit_log('patient.import_confirm','picupatients',$tempid);
            $message = "Record transferred successfully";
        } else {
            $mysqli->rollback();
            $message = "Error transferring record";
        }
    }
    // Redirect once, after every staged patient has been processed.
    echo "<script language='javascript'>window.location.href = 'dmc-old-patients.php';</script>";
}

if (isset($_POST['add_pt_btn'])) {
    csrf_verify();
    $mrn = $_POST['mrn'];
    $pname = $_POST['pname'];
    $admdate = $_POST['admdate'];
    $disdate = $_POST['disdate'];
    $admfrom = $_POST['admfrom'];
    $disto = $_POST['disto'];
    $mortality = $_POST['mortality'];
    $admissiondiagnosis = json_encode($_POST['admissiondiagnosis']);
    $nationality = $_POST['nationality'];
    $gender = $_POST['gender'];
    $consultant_id = $_POST['primary'];
    $age = $_POST['age'];
    $trans_discharge = $_POST['trans_discharge'];
    $current_location = $_POST['current_location'];
    $med_disdate = $_POST['med_disdate'];
    $delay = $_POST['delay'];
    $longterm = $_POST['longterm'];

    $admdate = ($admdate == NULL ? NULL : $admdate);
    $disdate = ($disdate == NULL ? NULL : $disdate);
    $sql = "INSERT INTO picupatients_temp SET MRN=?, PNAME=?, ADMDATE=?,
            DISDATE=?, ADMFROM=?, DISTO=?, MORTALITY=?,
            admissiondiagnosis=?, nationality=?, gender=?, age=?, consultant_id=?,
            trans_discharge=?, current_location=?, med_DISDATE=?, delay=?, longterm=?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('ssssssssssiisssss', $mrn, $pname, $admdate, $disdate, $admfrom, $disto, $mortality, $admissiondiagnosis, $nationality, $gender, $age, $consultant_id, $trans_discharge, $current_location, $med_disdate, $delay, $longterm);

    if ($stmt->execute() === TRUE) {
        $message = "Record added successfully";
        audit_log('patient.import_add','picupatients_temp',$mysqli->insert_id);
        echo "<script language='javascript'>\n";
        echo "window.location.href = 'dmc-old-patients.php';";
        echo "</script>\n";
    } else {
        $message = "Error adding record: " . $mysqli->error;
    }

    echo "<a>" . $message . "</a>";
}

if (!in_array($user['position'], $access_PICU_control)) {
    echo "
    <div class='content-wrapper'>
        <section class='content'>
            <div class='container-fluid'>  
                <div class='alert alert-danger' role='alert'>You don't have permission to access this page. Contact your manager if you need to.
                </div>
            </div>
        </section>
    </div>";
    require 'footer.php';
    exit();
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
    <?php
    $formationSQL = "SELECT * FROM picupatients_temp";
    $result1 = $mysqli->query($formationSQL);
    $temppicupatints = $result1->fetch_all(MYSQLI_ASSOC);

    $formationSQL = "SELECT * FROM countries";
    $result1 = $mysqli->query($formationSQL);
    $countries = $result1->fetch_all(MYSQLI_ASSOC);

    $userid = $user['member_id'];

    $formationSQL = "SELECT dx_id FROM tb_list";
    $result1 = $mysqli->query($formationSQL);
    $tb_list1 = $result1->fetch_all(MYSQLI_ASSOC);
    $tb_list = array();
    foreach ($tb_list1 as $tb) {
        $tb_list[] = $tb['dx_id'];
    }

    $query = "SELECT * FROM settings";
    $result1 = $mysqli->query($query);
    $settings = $result1->fetch_array(MYSQLI_ASSOC);

    $shortlos = $settings['short_los'];
    $longlos = $settings['long_los'];
    ?>

    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark">Add old patients</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item active">Add old patients</li>
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
                            <h3 class="card-title">Old Patient List</h3>
                            <div id="addbtn" class='eachrow' style='float: right; text-align: center; display: flex;'>
                                <?php
                                if ($user['add_new_patient'] == '1') {
                                    echo "<a class='btn btn-success' href='#admiting_modal' data-bs-toggle='modal' style='color: aliceblue; line-height: 2; padding: 0px 15px; margin-bottom: 2%;'>New Admission</a>";
                                    echo "
                                    <form method='post' action='dmc-old-patients.php'>".csrf_field()."
                                    <button type='submit' value='submit' name='confirm_pt_btn' class='btn btn-danger' style='color: aliceblue; line-height: 2; padding: 0px 15px; margin-bottom: 2%;' onclick='confirmpt(this)'>Confirm Patients</button>
                                    </form>";
                                }
                                ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php
                                foreach ($temppicupatints as $s) {
                                    $formationSQL = "SELECT * FROM picupatients WHERE DISDATE + INTERVAL 3 DAY > NOW() AND trans_discharge IS NULL AND MRN=? LIMIT 1";
                                    $stmt = $mysqli->prepare($formationSQL);
                                    $stmt->bind_param('s', $s['MRN']);
                                    $stmt->execute();
                                    $result1 = $stmt->get_result();
                                    $recentadmission = $result1->fetch_all(MYSQLI_ASSOC);

                                    $decodedadmissiondx = json_decode($s['admissiondiagnosis']);
                                    if ($decodedadmissiondx) {
                                        $tb_patient = array_intersect($decodedadmissiondx, $tb_list);
                                    } else {
                                        $tb_patient = array();
                                    }

                                    $today = date("Y-m-d");
                                    $today1 = strtotime(date("Y-m-d"));
                                    if ($s['med_DISDATE']) {
                                        $timeDiff = abs(strtotime($s['med_DISDATE']) - strtotime($s['ADMDATE']));
                                        $LOS = $timeDiff / 86400; 
                                    } else {
                                        $timeDiff = abs($today1 - strtotime($s['ADMDATE']));
                                        $LOS = $timeDiff / 86400; 
                                    }

                                    echo "<div class='col-sm-4'>
                                            <div class='eachrow card' id='row" . htmlspecialchars($s['ID'], ENT_QUOTES, 'UTF-8') . "'>
                                                <div style='margin: 1%; text-align: center; display: inline;' class='eachcol bed card-header' scope='row'>";
                                    if ($s['newassign'] == '1' AND $s['assigned_on'] == $today) {
                                        echo "<p style='color: red; display: contents;'><strong>New</strong></p>";
                                    }

                                    if ($tb_patient) {
                                        echo "<div style='background: #01ff70'><strong> TB Patient</strong></div>";
                                    }
                                    if ($s['delay'] !== Null) {
                                        echo "<div style='background: gold'><strong> Delayed Discharge</strong></div>";
                                    }
                                    if (!empty($s['longterm'])) {
                                        echo "<div style='background: #87503e; color: white;'><strong> Long Term Patient</strong></div>";
                                    }

                                    if ($s['med_DISDATE'] && $s['DISDATE'] == NULL && $s['trans_discharge'] == NULL) {
                                        echo "<label style='text-align: center; margin-bottom: 0px;'>Discharge Still in</label>";
                                    } elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'discharge from ward') {
                                        echo "<label style='text-align: center; margin-bottom: 0px;'>Discharged</label>";
                                    } elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'transfer to other speciality') {
                                        echo "<label style='text-align: center; margin-bottom: 0px;'>Transferred Intradepartment</label>";
                                    } elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'other transfer') {
                                        if ($s['DISTO'] == 'Intensive Care (ICU)') {
                                            echo "<label style='text-align: center; margin-bottom: 0px;'>Transferred to ICU</label>";
                                        } else {
                                            echo "<label style='text-align: center; margin-bottom: 0px;'>Transferred out department</label>";
                                        }
                                    } elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'discharge from ICU') {
                                        echo "<label style='text-align: center; margin-bottom: 0px;'>Discharged from ICU</label>";
                                    } elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'Transfer from ICU') {
                                        echo "<label style='text-align: center; margin-bottom: 0px;'>Transferred back from ICU</label>";
                                    } else {
                                        echo "<label style='text-align: center; margin-bottom: 0px;'>Admitted In " . htmlspecialchars($s['current_location'], ENT_QUOTES, 'UTF-8') . " Bed #</label>
                                            <input disabled class='txtdata' name='bed' placeholder='Bed Number' value='" . htmlspecialchars($s['BED'], ENT_QUOTES, 'UTF-8') . "' style='text-align: center;'>
                                            <input disabled class='txtdata' type='hidden' name='id' id='id' value='" . htmlspecialchars($s['ID'], ENT_QUOTES, 'UTF-8') . "' style='text-align: center; width: 85%;'>";
                                    }

                                    echo "</div>
                                        <div style='margin: 1%;' class='eachcol mrn'>
                                            <table style='width: 100%;'>
                                                <tr>
                                                    <td style='width: 50%; border-right: solid 0.5px;'>
                                                        <label style='text-align: center; margin-bottom: 0px;'>MRN</label>
                                                        <p style='text-align: center;'>" . htmlspecialchars($s['MRN'], ENT_QUOTES, 'UTF-8') . "</p>
                                                    </td>
                                                    <td style='width: 50%;'>
                                                        <label style='text-align: center; margin-bottom: 0px;'>Age</label>
                                                        <p style='text-align: center;'>" . htmlspecialchars($s['age'], ENT_QUOTES, 'UTF-8') . "</p>
                                                    </td>
                                                </tr>
                                            </table>
                                        </div>
                                        <div style='margin: 1%;' class='eachcol name'>
                                            <table style='width: 100%;'>
                                                <tr>
                                                    <td style='width: 50%; border-right: solid 0.5px;'>
                                                        <label style='text-align: center; margin-bottom: 0px;'>Patient Name</label>
                                                        <p style='text-align: center;'>" . htmlspecialchars($s['PNAME'], ENT_QUOTES, 'UTF-8') . "</p>
                                                    </td>
                                                    <td style='width: 50%;'>
                                                        <label style='text-align: center; margin-bottom: 0px;'>Gender</label>
                                                        <p style='text-align: center;'>" . htmlspecialchars($s['gender'], ENT_QUOTES, 'UTF-8') . "</p>
                                                    </td>
                                                </tr>
                                            </table>
                                        </div>
                                        <div style='margin: 1%;' class='eachcol admfrom' scope='row'>
                                            <table style='width: 100%;'>
                                                <tr>
                                                    <td style='width: 50%; border-right: solid 0.5px;'>
                                                        <label style='text-align: center; margin-bottom: 0px;'>Admission From</label>
                                                        <p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($s['ADMFROM'], ENT_QUOTES, 'UTF-8') . "</p>
                                                    </td>
                                                    <td style='width: 50%;'>
                                                        <label style='text-align: center; margin-bottom: 0px;'>Admitted By</label>";
                                    $mem_id = $s['admitted_by'];
                                    $formationSQL = "SELECT * FROM members WHERE member_id=?";
                                    $stmt = $mysqli->prepare($formationSQL);
                                    $stmt->bind_param('i', $mem_id);
                                    $stmt->execute();
                                    $result1 = $stmt->get_result();
                                    $doctor = $result1->fetch_array(MYSQLI_ASSOC);

                                    echo "<p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($doctor['full_name'], ENT_QUOTES, 'UTF-8') . "</p>
                                                    </td>
                                                </tr>
                                            </table>
                                        </div>
                                        <div style='margin: 1%;' class='eachcol admdate' scope='row'>
                                            <label style='text-align: center; margin-bottom: 0px;'>Admission Date</label>
                                            <p style='text-align: center;'>" . htmlspecialchars($s['ADMDATE'], ENT_QUOTES, 'UTF-8') . "</p>
                                        </div>
                                        <div style='margin: 1%;' class='eachcol admdate' scope='row'>
                                            <label style='text-align: center; margin-bottom: 0px;'>Nationality</label>
                                            <p style='text-align: center;'>" . htmlspecialchars($s['nationality'], ENT_QUOTES, 'UTF-8') . "</p>
                                        </div>";

                                    if ($s['current_location'] == 'ICU') {
                                        echo "<div style='margin: 1%; background: royalblue; color: white;' class='eachcol' scope='row'>
                                                <p style='text-align: center; margin-bottom: 0px;'>ICU patient</p>
                                              </div>";
                                    } else {
                                        if ($LOS < $shortlos) {
                                            echo "<div style='margin: 1%; background: #d4edda;' class='eachcol admdate' scope='row'>";
                                        } elseif ($LOS > $longlos) {
                                            echo "<div style='margin: 1%; background: #f8d7da;' class='eachcol admdate' scope='row'>";
                                        } elseif ($LOS >= $shortlos) {
                                            echo "<div style='margin: 1%; background: #fff3cd;' class='eachcol admdate' scope='row'>";
                                        }

                                        echo "<label style='text-align: center; margin-bottom: 0px;'>Duration of Admission: " . htmlspecialchars($LOS, ENT_QUOTES, 'UTF-8') . " Days</label>
                                              </div>";
                                    }

                                    echo "<div style='margin: 1%;' class='eachcol admissiondiagnosis'>
                                            <label style='text-align: center; margin-bottom: 0px;'>Diagnosis</label>
                                            <ul style='list-style-position: inside; margin: 1% 0% 1%;'>";
                                    if (is_array($decodedadmissiondx)) {
                                        foreach ($decodedadmissiondx as $key => $value) {
                                            $formationSQL = "SELECT * FROM icd10 WHERE id=?";
                                            $stmt = $mysqli->prepare($formationSQL);
                                            $stmt->bind_param('s', $value);
                                            $stmt->execute();
                                            $result1 = $stmt->get_result();
                                            $dxlist = $result1->fetch_array(MYSQLI_ASSOC);
                                            echo '<li>' . htmlspecialchars($dxlist['name'], ENT_QUOTES, 'UTF-8') . '</li>';
                                        }
                                    }
                                    echo "</ul></div>
                                        <div style='margin: 1%;'>
                                            <label style='text-align: center; margin-bottom: 0px;'>Primary Consultant</label>";
                                    $con_id = $s['consultant_id'];
                                    $formationSQL = "SELECT * FROM members WHERE member_id=?";
                                    $stmt = $mysqli->prepare($formationSQL);
                                    $stmt->bind_param('i', $con_id);
                                    $stmt->execute();
                                    $result1 = $stmt->get_result();
                                    $doctor1 = $result1->fetch_array(MYSQLI_ASSOC);
                                    if ($doctor1) {
                                        echo "<p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($doctor1['full_name'], ENT_QUOTES, 'UTF-8') . "</p>";
                                    } else {
                                        echo "<p style='text-align: center; margin-bottom: 0px;'>Not Assigned Yet</p>";
                                    }

                                    echo "</div>";

                                    if ($s['med_DISDATE'] && $s['DISDATE'] == NULL && $s['trans_discharge'] == NULL) {
                                        echo "<div style='margin: 1%; border-top: solid 0.5px;'>
                                                <table style='width: 100%;'>
                                                    <tr>
                                                        <td style='width: 50%; border-right: solid 0.5px;'>
                                                            <label style='text-align: center; margin-bottom: 0px;'>Discharge Status</label>
                                                            <p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($s['MORTALITY'], ENT_QUOTES, 'UTF-8') ."</p>
                                                        </td>
                                                        <td style='width: 50%;'>
                                                            <label style='text-align: center; margin-bottom: 0px;'>Discharged By</label>";
                                        $mem_id2 = $s['trans_discharge_by'];
                                        $formationSQL = "SELECT * FROM members WHERE member_id=?";
                                        $stmt = $mysqli->prepare($formationSQL);
                                        $stmt->bind_param('i', $mem_id2);
                                        $stmt->execute();
                                        $result1 = $stmt->get_result();
                                        $doctor2 = $result1->fetch_array(MYSQLI_ASSOC);

                                        echo "<p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($doctor2['full_name'], ENT_QUOTES, 'UTF-8') ."</p>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </div>
                                            <div style='margin: 1%;'>
                                                <table style='width: 100%;'>
                                                    <tr>
                                                        <td style='width: 50%; border-right: solid 0.5px;'>
                                                            <label style='text-align: center; margin-bottom: 0px;'>Discharge Date</label>
                                                            <p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($s['med_DISDATE'], ENT_QUOTES, 'UTF-8') ."</p>
                                                        </td>
                                                        <td style='width: 50%;'>
                                                            <label style='text-align: center; margin-bottom: 0px; color: red;'>Delay Due To</label>
                                                            <p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($s['delay'], ENT_QUOTES, 'UTF-8') ."</p>
                                                        </td>
                                                    </tr>
                                                </table>
                                                <label style='text-align: center; margin-bottom: 0px; color: red;'>File Not Closed Yet</label>
                                            </div>";
                                    } elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'discharge from ward') {
                                        echo "<div style='margin: 1%; border-top: solid 0.5px;'>
                                                <table style='width: 100%;'>
                                                    <tr>
                                                        <td style='width: 50%; border-right: solid 0.5px;'>
                                                            <label style='text-align: center; margin-bottom: 0px;'>Discharge Status</label>
                                                            <p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($s['MORTALITY'], ENT_QUOTES, 'UTF-8') ."</p>
                                                        </td>
                                                        <td style='width: 50%;'>
                                                            <label style='text-align: center; margin-bottom: 0px;'>Discharged By</label>";
                                        $mem_id2 = $s['trans_discharge_by'];
                                        $formationSQL = "SELECT * FROM members WHERE member_id=?";
                                        $stmt = $mysqli->prepare($formationSQL);
                                        $stmt->bind_param('i', $mem_id2);
                                        $stmt->execute();
                                        $result1 = $stmt->get_result();
                                        $doctor2 = $result1->fetch_array(MYSQLI_ASSOC);

                                        echo "<p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($doctor2['full_name'], ENT_QUOTES, 'UTF-8') ."</p>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </div>
                                            <div style='margin: 1%;'>
                                                <table style='width: 100%;'>
                                                    <tr>
                                                        <td style='width: 50%; border-right: solid 0.5px;'>
                                                            <label style='text-align: center; margin-bottom: 0px;'>Discharge Date</label>
                                                            <p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($s['med_DISDATE'], ENT_QUOTES, 'UTF-8') ."</p>
                                                        </td>
                                                        <td style='width: 50%;'>
                                                            <label style='text-align: center; margin-bottom: 0px;'>Discharged To</label>
                                                            <p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($s['DISTO'], ENT_QUOTES, 'UTF-8') ."</p>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </div>";
                                        if ($s['delay']) {
                                            echo "<div style='margin: 1%;'>
                                                    <table style='width: 100%;'>
                                                        <tr>
                                                            <td style='width: 50%; border-right: solid 0.5px;'>
                                                                <label style='text-align: center; margin-bottom: 0px; color: red;'>File Closed At</label>
                                                                <p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($s['DISDATE'], ENT_QUOTES, 'UTF-8') ."</p>
                                                            </td>
                                                            <td style='width: 50%;'>
                                                                <label style='text-align: center; margin-bottom: 0px; color: red;'>Delay Due To</label>
                                                                <p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($s['delay'], ENT_QUOTES, 'UTF-8') ."</p>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                  </div>";
                                        }
                                    } elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'transfer to other speciality') {
                                        echo "<div style='margin: 1%; border-top: solid 0.5px;'>
                                                <label style='text-align: center; margin-bottom: 0px;'>Transfer to Other Specialty</label>
                                                <table style='width: 100%;'>
                                                    <tr>
                                                        <td style='width: 50%; border-right: solid 0.5px;'>
                                                            <label style='text-align: center; margin-bottom: 0px;'>Transfer Status</label>
                                                            <p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($s['MORTALITY'], ENT_QUOTES, 'UTF-8') ."</p>
                                                        </td>
                                                        <td style='width: 50%;'>
                                                            <label style='text-align: center; margin-bottom: 0px;'>Transfer By</label>";
                                        $mem_id2 = $s['trans_discharge_by'];
                                        $formationSQL = "SELECT * FROM members WHERE member_id=?";
                                        $stmt = $mysqli->prepare($formationSQL);
                                        $stmt->bind_param('i', $mem_id2);
                                        $stmt->execute();
                                        $result1 = $stmt->get_result();
                                        $doctor2 = $result1->fetch_array(MYSQLI_ASSOC);

                                        echo "<p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($doctor2['full_name'], ENT_QUOTES, 'UTF-8') ."</p>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </div>
                                            <div style='margin: 1%;'>
                                                <table style='width: 100%;'>
                                                    <tr>
                                                        <td style='width: 50%; border-right: solid 0.5px;'>
                                                            <label style='text-align: center; margin-bottom: 0px;'>Transfer At</label>
                                                            <p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($s['med_DISDATE'], ENT_QUOTES, 'UTF-8') ."</p>
                                                        </td>
                                                        <td style='width: 50%;'>
                                                            <label style='text-align: center; margin-bottom: 0px;'>Transfer To</label>
                                                            <p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($s['DISTO'], ENT_QUOTES, 'UTF-8') ."</p>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </div>";
                                    } elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'other transfer') {
                                        echo "<div style='margin: 1%; border-top: solid 0.5px;'>";
                                        if ($s['DISTO'] == 'Intensive Care (ICU)') {
                                            echo "<label style='text-align: center; margin-bottom: 0px;'>Transferred to ICU</label>";
                                        } else {
                                            echo "<label style='text-align: center; margin-bottom: 0px;'>Transfer to Other Specialty</label>";
                                        }
                                        echo "<table style='width: 100%;'>
                                                <tr>
                                                    <td style='width: 50%; border-right: solid 0.5px;'>
                                                        <label style='text-align: center; margin-bottom: 0px;'>Transfer Status</label>
                                                        <p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($s['MORTALITY'], ENT_QUOTES, 'UTF-8') ."</p>
                                                    </td>
                                                    <td style='width: 50%;'>
                                                        <label style='text-align: center; margin-bottom: 0px;'>Transfer By</label>";
                                        $mem_id2 = $s['trans_discharge_by'];
                                        $formationSQL = "SELECT * FROM members WHERE member_id=?";
                                        $stmt = $mysqli->prepare($formationSQL);
                                        $stmt->bind_param('i', $mem_id2);
                                        $stmt->execute();
                                        $result1 = $stmt->get_result();
                                        $doctor2 = $result1->fetch_array(MYSQLI_ASSOC);

                                        echo "<p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($doctor2['full_name'], ENT_QUOTES, 'UTF-8') ."</p>
                                                    </td>
                                                </tr>
                                              </table>
                                            </div>
                                            <div style='margin: 1%;'>
                                                <table style='width: 100%;'>
                                                    <tr>
                                                        <td style='width: 50%; border-right: solid 0.5px;'>
                                                            <label style='text-align: center; margin-bottom: 0px;'>Transfer At</label>
                                                            <p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($s['med_DISDATE'], ENT_QUOTES, 'UTF-8') ."</p>
                                                        </td>
                                                        <td style='width: 50%;'>
                                                            <label style='text-align: center; margin-bottom: 0px;'>Transfer To</label>
                                                            <p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($s['DISTO'], ENT_QUOTES, 'UTF-8') ."</p>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </div>";
                                    } elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'discharge from ICU') {
                                        echo "<div style='margin: 1%; border-top: solid 0.5px;'>
                                                <label style='text-align: center; margin-bottom: 0px;'>Discharged from ICU</label>
                                                <table style='width: 100%;'>
                                                    <tr>
                                                        <td style='width: 50%; border-right: solid 0.5px;'>
                                                            <label style='text-align: center; margin-bottom: 0px;'>Discharge Status</label>
                                                            <p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($s['MORTALITY'], ENT_QUOTES, 'UTF-8') ."</p>
                                                        </td>
                                                        <td style='width: 50%;'>
                                                            <label style='text-align: center; margin-bottom: 0px;'>Discharged By</label>";
                                        $mem_id2 = $s['trans_discharge_by'];
                                        $formationSQL = "SELECT * FROM members WHERE member_id=?";
                                        $stmt = $mysqli->prepare($formationSQL);
                                        $stmt->bind_param('i', $mem_id2);
                                        $stmt->execute();
                                        $result1 = $stmt->get_result();
                                        $doctor2 = $result1->fetch_array(MYSQLI_ASSOC);

                                        echo "<p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($doctor2['full_name'], ENT_QUOTES, 'UTF-8') ."</p>
                                                    </td>
                                                </tr>
                                              </table>
                                            </div>
                                            <div style='margin: 1%;'>
                                                <table style='width: 100%;'>
                                                    <tr>
                                                        <td style='width: 50%; border-right: solid 0.5px;'>
                                                            <label style='text-align: center; margin-bottom: 0px;'>Discharged At</label>
                                                            <p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($s['med_DISDATE'], ENT_QUOTES, 'UTF-8') ."</p>
                                                        </td>
                                                        <td style='width: 50%;'>
                                                            <label style='text-align: center; margin-bottom: 0px;'>Discharged To</label>
                                                            <p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($s['DISTO'], ENT_QUOTES, 'UTF-8') ."</p>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </div>";
                                    } elseif ($s['med_DISDATE'] && $s['DISDATE'] && $s['trans_discharge'] == 'Transfer from ICU') {
                                        echo "<div style='margin: 1%; border-top: solid 0.5px;'>
                                                <label style='text-align: center; margin-bottom: 0px;'>Transferred Back from ICU</label>
                                                <table style='width: 100%;'>
                                                    <tr>
                                                        <td style='width: 50%; border-right: solid 0.5px;'>
                                                            <label style='text-align: center; margin-bottom: 0px;'>Transfer Status</label>
                                                            <p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($s['MORTALITY'], ENT_QUOTES, 'UTF-8') ."</p>
                                                        </td>
                                                        <td style='width: 50%;'>
                                                            <label style='text-align: center; margin-bottom: 0px;'>Transfer By</label>";
                                        $mem_id2 = $s['trans_discharge_by'];
                                        $formationSQL = "SELECT * FROM members WHERE member_id=?";
                                        $stmt = $mysqli->prepare($formationSQL);
                                        $stmt->bind_param('i', $mem_id2);
                                        $stmt->execute();
                                        $result1 = $stmt->get_result();
                                        $doctor2 = $result1->fetch_array(MYSQLI_ASSOC);

                                        echo "<p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($doctor2['full_name'], ENT_QUOTES, 'UTF-8') ."</p>
                                                    </td>
                                                </tr>
                                              </table>
                                            </div>
                                            <div style='margin: 1%;'>
                                                <table style='width: 100%;'>
                                                    <tr>
                                                        <td style='width: 50%; border-right: solid 0.5px;'>
                                                            <label style='text-align: center; margin-bottom: 0px;'>Transfer At</label>
                                                            <p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($s['med_DISDATE'], ENT_QUOTES, 'UTF-8') ."</p>
                                                        </td>
                                                        <td style='width: 50%;'>
                                                            <label style='text-align: center; margin-bottom: 0px;'>Transfer To</label>
                                                            <p style='text-align: center; margin-bottom: 0px;'>" . htmlspecialchars($s['DISTO'], ENT_QUOTES, 'UTF-8') ."</p>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </div>";
                                    }

                                    echo "</div></div>";
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="modal" id="details_modal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">Patient Details</h4>
                    <button type="button" class="close" data-bs-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="pdetailsdiv"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal" id="admiting_modal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">Add Patient</h4>
                    <button type="button" class="close" data-bs-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <form id="admitPatientForm" method="post" autocomplete="off" action="dmc-old-patients.php">
                        <?php echo csrf_field(); ?>
                        <label>MRN</label>
                        <input class='txtdata' name='mrn' style='text-align: center;' required>
                        <label>Patient Name</label>
                        <input class='txtdata' name='pname' style='text-align: center;' required>
                        <label>Age</label>
                        <input class='txtdata' name='age' style='text-align: center;' required>
                        <label>Gender</label>
                        <select class='txtdata' name='gender' style="width: 100%; text-align: center; padding: 4px;" required>
                            <option value='Male'>Male</option>
                            <option value='Female'>Female</option>
                        </select>
                        <label>Nationality</label>
                        <select class='select2 txtdata' name='nationality' style="width: 100%; text-align: center; padding: 4px;" required>
                            <?php
                            foreach ($countries as $country)
                                echo "<option value='" . htmlspecialchars($country['name'], ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($country['name'], ENT_QUOTES, 'UTF-8') . "</option>";
                            ?>
                        </select>
                        <label>Admission Date</label>
                        <input class='txtdata' id="admdate" data-date-format="YYYY-MM-DD" type="text" name='admdate' style="text-align: center; padding: 0px;" required readonly>
                        <label>Admitted From</label>
                        <select class='txtdata' name="admfrom" style="width: 100%; text-align: center; padding: 4px;" required>
                            <option value='ER'>ER</option>
                            <option value='ICU'>ICU</option>
                            <option value='OPD'>OPD</option>
                            <option value='OR'>OR</option>
                            <option value='Referral'>Referral</option>
                        </select>
                        <label>Primary Consultant</label>
                        <select class='select2 txtdata' name="primary" style="width: 100%; text-align: center; padding: 4px;" required>
                            <option disabled>select</option>
                            <?php
                            $formationSQL = "SELECT * FROM members";
                            $result1 = $mysqli->query($formationSQL);
                            $consultant = $result1->fetch_all(MYSQLI_ASSOC);

                            foreach ($consultant as $con) {
                                echo "<option value='" . htmlspecialchars($con['member_id'], ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($con['full_name'], ENT_QUOTES, 'UTF-8') . "</option>";
                            }
                            ?>
                        </select>
                        <label>Diagnosis</label>
                        <select class='txtdata ddxname form-control' style='width: 100%;' multiple='multiple' name='admissiondiagnosis[]' required>
                        </select>
                        <label>Current Location</label>
                        <select class='txtdata' name='current_location' style="width: 100%; text-align: center; padding: 4px;" required>
                            <?php
                            $formationSQL = "SELECT DISTINCT(current_location) FROM picupatients";
                            $result1 = $mysqli->query($formationSQL);
                            $current_location = $result1->fetch_all(MYSQLI_ASSOC);

                            foreach ($current_location as $a) {
                                echo "<option value='" . htmlspecialchars($a['current_location'], ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($a['current_location'], ENT_QUOTES, 'UTF-8') . "</option>";
                            }
                            ?>
                        </select>
                        <label>Long Term?</label>
                        <select class='txtdata' name='longterm' style="width: 100%; text-align: center; padding: 4px;">
                            <?php
                            $formationSQL = "SELECT DISTINCT(longterm) FROM picupatients";
                            $result1 = $mysqli->query($formationSQL);
                            $longterm = $result1->fetch_all(MYSQLI_ASSOC);

                            foreach ($longterm as $a) {
                                echo "<option value='" . htmlspecialchars($a['longterm'], ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($a['longterm'], ENT_QUOTES, 'UTF-8') . "</option>";
                            }
                            ?>
                        </select>
                        <label>Trans Discharge Status</label>
                        <select class='txtdata' name='trans_discharge' style="width: 100%; text-align: center; padding: 4px;" required>
                            <?php
                            $formationSQL = "SELECT DISTINCT(trans_discharge) FROM picupatients";
                            $result1 = $mysqli->query($formationSQL);
                            $trans_discharge = $result1->fetch_all(MYSQLI_ASSOC);

                            foreach ($trans_discharge as $a) {
                                echo "<option value='" . htmlspecialchars($a['trans_discharge'], ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($a['trans_discharge'], ENT_QUOTES, 'UTF-8') . "</option>";
                            }
                            ?>
                        </select>
                        <label>Discharge Date</label>
                        <input class='txtdata' id="disdate" data-date-format="YYYY-MM-DD" type="text" name='med_disdate' style="text-align: center; padding: 0px;" required readonly>
                        <label>Close File Date</label>
                        <input class='txtdata' id="filedate" data-date-format="YYYY-MM-DD" type="text" name='disdate' style="text-align: center; padding: 0px;" required readonly>
                        <label>Delay</label>
                        <select class='txtdata' name='delay' style="width: 100%; text-align: center; padding: 4px;">
                            <?php
                            $formationSQL = "SELECT DISTINCT(delay) FROM picupatients";
                            $result1 = $mysqli->query($formationSQL);
                            $delay = $result1->fetch_all(MYSQLI_ASSOC);

                            foreach ($delay as $a) {
                                echo "<option value='" . htmlspecialchars($a['delay'], ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($a['delay'], ENT_QUOTES, 'UTF-8') . "</option>";
                            }
                            ?>
                        </select>
                        <label>Discharge To</label>
                        <select class='txtdata' name='disto' style="width: 100%; text-align: center; padding: 4px;" required>
                            <?php
                            $formationSQL = "SELECT DISTINCT(DISTO) FROM picupatients";
                            $result1 = $mysqli->query($formationSQL);
                            $disto = $result1->fetch_all(MYSQLI_ASSOC);

                            foreach ($disto as $a) {
                                echo "<option value='" . htmlspecialchars($a['DISTO'], ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($a['DISTO'], ENT_QUOTES, 'UTF-8') . "</option>";
                            }
                            ?>
                        </select>
                        <label>Mortality</label>
                        <select class='txtdata' name='mortality' style="width: 100%; text-align: center; padding: 4px;" required>
                            <option value='Alive'>Alive</option>
                            <option value='Dead'>Dead</option>
                        </select>
                        <div class="modal-footer" style="text-align: center; display: block;">
                            <button type='submit' value='submit' class='btn btn-success' name='add_pt_btn' onclick='addpatient(this)'>Add Patient</button>
                            <button type="button" class="btn btn-default" style="color: black;" data-bs-dismiss="modal">Close</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require 'footer.php';
?>

<script src="vendor/select2/4.0.13/select2.min.js"></script>
<script src="vendor/moments.js/2.30.1/moment.min.js"></script>
<script src="vendor/daterangepicker/3.1.0/daterangepicker.min.js"></script>

<script>
function confirmpt(button) {
    button.disabled = true;
    button.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"><span class="sr-only">Loading...</span></div>';
    var hiddenInput = document.createElement('input');
    hiddenInput.type = 'hidden';
    hiddenInput.name = button.name;
    hiddenInput.value = button.value;
    button.form.appendChild(hiddenInput);
    button.form.submit();
}

function addpatient(button) {
    // Reference to the form
    var form = button.form;
    
    // Validate required fields
    var requiredFields = form.querySelectorAll('[required]');
    var allValid = true;
    requiredFields.forEach(function(field) {
        if (!field.value) {
            allValid = false;
            field.style.border = '2px solid red';
        } else {
            field.style.border = '';
        }
    });

    // If all fields are valid, proceed with form submission
    if (allValid) {
        button.disabled = true;
        button.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"><span class="sr-only">Loading...</span></div>';
        var hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = button.name;
        hiddenInput.value = button.value;
        button.form.appendChild(hiddenInput);
        button.form.submit();
    } else {
        // alert('Please fill in all required fields.');
    }
}

function auto_grow(element) {
    element.style.height = "5px";
    element.style.height = (element.scrollHeight) + "px";
}

$(document).ready(function() {
    $('.select2').select2({
        placeholder: 'Select',
      placeholder: 'Select',
    width: '100%',

        dropdownParent: $("#admiting_modal")
    });

    $('.ddxname').select2({
        placeholder: 'Select',
        minimumInputLength: 4,
        ajax: {
            url: 'fetchicd10.php',
            dataType: 'json',
            delay: 250,
            data: function(data) {
                return {
                    searchTerm: data.term
                };
            },
            processResults: function(response) {
                return {
                    results: response
                };
            },
            cache: true
        }
    });
});

$('#details_modal').on('show.bs.modal', function(e) {
    var bookId = $(e.relatedTarget).data('book-id');
    data = { bookId: bookId };
    $.post('dmc-old-patients-details.php', data, function(data) {
        $('#pdetailsdiv').html(data);
    });
});

$("textarea").each(function() {
    $(this).height($(this)[0].scrollHeight);
});

$(function() {
    $('input[name="admdate"]').daterangepicker({
        singleDatePicker: true,
        timePicker: false,
        showButtonPanel: false,
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

    $('input[name="disdate"]').daterangepicker({
        singleDatePicker: true,
        timePicker: false,
        showButtonPanel: false,
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

    $('input[name="med_disdate"]').daterangepicker({
        singleDatePicker: true,
        timePicker: false,
        showButtonPanel: false,
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

function showfunction(v) {
    var aaa = document.getElementById(v);
    aaa.classList.toggle('SeeMore2');
    if (aaa.classList.contains('SeeMore2')) {
        aaa.innerHTML = '+';
    } else {
        aaa.innerHTML = '-';
    }
}

// Add form validation before submission
document.getElementById('admitPatientForm').addEventListener('submit', function(event) {
    var form = this;
    var requiredFields = form.querySelectorAll('[required]');
    var allValid = true;
    requiredFields.forEach(function(field) {
        if (!field.value) {
            allValid = false;
            field.style.border = '2px solid red';
        } else {
            field.style.border = '';
        }
    });

    if (!allValid) {
        event.preventDefault();
        alert('Please fill in all required fields.');
    }
});
</script>
