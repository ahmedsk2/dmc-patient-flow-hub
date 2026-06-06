<?php
require_once 'sidebar.php';

if (!in_array($user['position'], $access_PICU_control)) {
    echo "
    <div class='content-wrapper'>
        <section class='content'>
            <div class='container-fluid'>
                <div class='alert alert-danger' role='alert'>You don't have permission to access this page. Contact your manager if you need to.</div>
            </div>
        </section>
    </div>";
    require 'footer.php';
    exit();
}

function fetchAllData($mysqli, $query) {
    $stmt = $mysqli->prepare($query);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$members = fetchAllData($mysqli, "SELECT * FROM members");
$positions = fetchAllData($mysqli, "SELECT * FROM position");
$specialities = fetchAllData($mysqli, "SELECT * FROM speciality");
$settings = fetchAllData($mysqli, "SELECT * FROM settings")[0];

function updateSettings($mysqli, $data) {
    $stmt = $mysqli->prepare("UPDATE settings SET long_los=?, max_hospitalist=?, max_subs=?, min_hospitalist=?, min_subs=?, short_los=? WHERE id=0");
    $stmt->bind_param("iiiiii", $data['long_los'], $data['max_hospitalist'], $data['max_sub'], $data['min_hospitalist'], $data['min_sub'], $data['short_los']);
    return $stmt->execute();
}

function insertSpeciality($mysqli, $speciality) {
    $stmt = $mysqli->prepare("INSERT INTO speciality (specilaity) VALUES (?)");
    $stmt->bind_param("s", $speciality);
    return $stmt->execute();
}

function insertOtherSpeciality($mysqli, $other_speciality) {
    $stmt = $mysqli->prepare("INSERT INTO other_specialities (specilaity) VALUES (?)");
    $stmt->bind_param("s", $other_speciality);
    return $stmt->execute();
}

function insertConsultationReason($mysqli, $reason) {
    $stmt = $mysqli->prepare("INSERT INTO consultation_reason (consultation_reason) VALUES (?)");
    $stmt->bind_param("s", $reason);
    return $stmt->execute();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['submitlimits'])) {
        csrf_verify();
        if (!empty($_POST['min_hospitalist']) && !empty($_POST['max_hospitalist']) && !empty($_POST['min_sub']) && !empty($_POST['max_sub']) && !empty($_POST['short_los']) && !empty($_POST['long_los'])) {
            $data = [
                'min_hospitalist' => $_POST['min_hospitalist'],
                'max_hospitalist' => $_POST['max_hospitalist'],
                'min_sub' => $_POST['min_sub'],
                'max_sub' => $_POST['max_sub'],
                'short_los' => $_POST['short_los'],
                'long_los' => $_POST['long_los']
            ];
            if (updateSettings($mysqli, $data)) {
                $_SESSION['message'] = "Updated successfully.";
            } else {
                $_SESSION['message'] = "Update failed.";
            }
        } else {
            $_SESSION['message'] = "Please, fill all the form.";
        }
    }

    if (isset($_POST['submitspecialty'])) {
        csrf_verify();
        if (!empty($_POST['specialty'])) {
            $speciality = $_POST['specialty'];
            if (insertSpeciality($mysqli, $speciality)) {
                $_SESSION['message'] = "Specialty added successfully.";
            } else {
                $_SESSION['message'] = "Failed to add specialty.";
            }
        } else {
            $_SESSION['message'] = "Please, fill all the form.";
        }
    }

    if (isset($_POST['submitother_specialities'])) {
        csrf_verify();
        if (!empty($_POST['other_specialities'])) {
            $other_speciality = $_POST['other_specialities'];
            if (insertOtherSpeciality($mysqli, $other_speciality)) {
                $_SESSION['message'] = "Allied specialty added successfully.";
            } else {
                $_SESSION['message'] = "Failed to add allied specialty.";
            }
        } else {
            $_SESSION['message'] = "Please, fill all the form.";
        }
    }

    if (isset($_POST['submitindication'])) {
        csrf_verify();
        if (!empty($_POST['indication_name'])) {
            $indication = $_POST['indication_name'];
            if (insertConsultationReason($mysqli, $indication)) {
                $_SESSION['message'] = "Indication added successfully.";
            } else {
                $_SESSION['message'] = "Failed to add indication.";
            }
        } else {
            $_SESSION['message'] = "Please, fill all the form.";
        }
    }
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

<!-- Include HTML file for the page content -->

<div class="content-wrapper">
    <!-- Content Header -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark">Control Dash</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item active">Control Dash</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <section class="content">
        <div class="container-fluid">
            <!-- Display Message -->
            <?php if (isset($_SESSION['message'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($_SESSION['message']) ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php unset($_SESSION['message']); ?>
            <?php endif; ?>
            <div class="row">
                <!-- Limits Form -->
                <div id="limits" class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-procedures text-info"></i> Limits</h3>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="row">
                                    <div class="col-6">
                                        <div class="form-group">
                                            <label for="min_hospitalist">Minimum Patients for Hospitalist</label>
                                            <input class="form-control" type="text" id="min_hospitalist" name="min_hospitalist" value="<?= htmlspecialchars($settings['min_hospitalist'], ENT_QUOTES, 'UTF-8') ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="max_hospitalist">Maximum Patients for Hospitalist</label>
                                            <input class="form-control" type="text" id="max_hospitalist" name="max_hospitalist" value="<?= htmlspecialchars($settings['max_hospitalist'], ENT_QUOTES, 'UTF-8') ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="form-group">
                                            <label for="min_sub">Minimum Patients for Subspeciality</label>
                                            <input class="form-control" type="text" id="min_sub" name="min_sub" value="<?= htmlspecialchars($settings['min_subs'], ENT_QUOTES, 'UTF-8') ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="max_sub">Maximum Patients for Subspeciality</label>
                                            <input class="form-control" type="text" id="max_sub" name="max_sub" value="<?= htmlspecialchars($settings['max_subs'], ENT_QUOTES, 'UTF-8') ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="form-group">
                                            <label for="short_los">Short Duration of Admission Less Than</label>
                                            <input class="form-control" type="text" id="short_los" name="short_los" value="<?= htmlspecialchars($settings['short_los'], ENT_QUOTES, 'UTF-8') ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="long_los">Long Duration of Admission More Than</label>
                                            <input class="form-control" type="text" id="long_los" name="long_los" value="<?= htmlspecialchars($settings['long_los'], ENT_QUOTES, 'UTF-8') ?>" required>
                                        </div>
                                    </div>
                                </div>
                                <?php echo csrf_field(); ?> <button class="btn btn-info" type="submit" name="submitlimits" onclick='loading(this)'>Update</button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Add Specialty Form -->
                <div id="specialty" class="col-md-3">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-diagnoses text-info"></i> Add Specialty</h3>
                        </div>
                        <div class="card-body">
                            <div class="input-group">
                                    <label class="label">Specialty Added</label>
                                    <?php
                                    
                                    $query = "select * from speciality";
                                    $speciality = $mysqli->query($query);
                                    
                                   echo" <ul style='list-style-position: inside;'>
                                    ";
                                      
                                      foreach($speciality as $ss)
                                {
                              
                                    echo '<li>'.  htmlspecialchars($ss['specilaity'], ENT_QUOTES, 'UTF-8'). '</li>';
                                }
           
                                    
                                    ?>
                                     </ul>
                            </div>
                            <form method="POST">
                                <div class="form-group">
                                    <label for="specialty">Specialty Name</label>
                                    <input class="form-control" type="text" id="specialty" name="specialty" required>
                                </div>
                                <?php echo csrf_field(); ?> <button class="btn btn-info" type="submit" name="submitspecialty" onclick='loading(this)'>Add Specialty</button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Add Allied Specialty Form -->
                <div id="other_specialities" class="col-md-3">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-diagnoses text-info"></i> Add Allied Specialty</h3>
                        </div>
                        <div class="card-body">
                            <div class="input-group">
                                    <label class="label">Allied Specialty Added</label>
                                    <?php
                                    
                                    $query = "select * from other_specialities";
                                    $other_specialities = $mysqli->query($query);
                                    
                                   echo" <ul style='list-style-position: inside;'>
                                    ";
                                      
                                      foreach($other_specialities as $ss)
                                {
                              
                                    echo '<li>'.  htmlspecialchars($ss['specilaity'], ENT_QUOTES, 'UTF-8'). '</li>';
                                }
           
                                    
                                    ?>
                                     </ul>
                                    
                            </div>
                            <form method="POST">
                                <div class="form-group">
                                    <label for="other_specialities">Allied Specialty Name</label>
                                    <input class="form-control" type="text" id="other_specialities" name="other_specialities" required>
                                </div>
                                <?php echo csrf_field(); ?> <button class="btn btn-info" type="submit" name="submitother_specialities" onclick='loading(this)'>Add Allied Specialty</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Add Consultation Indication Form -->
                <div id="consultation" class="col-md-3">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-diagnoses text-info"></i> Add Consultation Indication</h3>
                        </div>
                        <div class="card-body">
                            <div class="input-group">
                                    <label class="label">Indications Added</label>
                                    <?php
                                    
                                    $query = "select * from consultation_reason";
                                    $indications = $mysqli->query($query);
                                    
                                   echo" <ul style='list-style-position: inside;'>
                                    ";
                                      
                                      foreach($indications as $ind)
                                {
                              
                                    echo '<li>'.  htmlspecialchars($ind['consultation_reason'], ENT_QUOTES, 'UTF-8'). '</li>';
                                }
           
                                    
                                    ?>
                                     </ul>
                                    
                            </div>
                            <form method="POST">
                                <div class="form-group">
                                    <label for="indication_name">Indication Name</label>
                                    <input class="form-control" type="text" id="indication_name" name="indication_name" required>
                                </div>
                                <?php echo csrf_field(); ?> <button class="btn btn-info" type="submit" name="submitindication" onclick='loading(this)'>Add Indication</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Manage Active Users -->
                <div id="mypresentersTable" class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-user-tie text-info"></i> Manage Active Users</h3>
                        </div>
                        <div class="card-body">
                            <input class="form-control mb-3" id="search" type="text" placeholder="Search..">
                            <div class="table-responsive">
                                <table id="myTable" class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th onclick="sortTable(0)" scope="col">Full Name <i class="fa fa-sort" aria-hidden="true"></i></th>
                                            <th scope="col">Login Name</th>
                                            <th scope="col">Email</th>
                                            <th scope="col">Position</th>
                                            <th scope="col">Speciality</th>
                                            <th scope="col">Status</th>
                                            <th onclick="sortTable(5)" scope="col">On Service <i class="fa fa-sort" aria-hidden="true"></i></th>
                                            <th scope="col">Assign patients</th>
                                            <th scope="col">Add patients</th>
                                            <th scope="col">Manage patients</th>
                                            <th scope="col">Modify patients</th>
                                        </tr>
                                    </thead>
                                    <tbody id="searchtable">
                                        <?php
                                        usort($members, function ($a, $b) {
                                            return $a['active'] <=> $b['active'];
                                        });
                                        foreach ($members as $member):
                                            if ($member['active'] == '1'):
                                                ?>
                                                <tr class='eachrow' id='row<?= htmlspecialchars($member['member_id'], ENT_QUOTES, 'UTF-8') ?>'>
                                                    <td class='eachcol full_name'>
                                                        <input class='txtdata' name='full_name' value='<?= htmlspecialchars($member['full_name'], ENT_QUOTES, 'UTF-8') ?>' style='text-align: center;'>
                                                        <input type='hidden' name='member_id' value='<?= htmlspecialchars($member['member_id'], ENT_QUOTES, 'UTF-8') ?>'>
                                                        <p style='display:none;'><?= htmlspecialchars($member['full_name'], ENT_QUOTES, 'UTF-8') ?></p>
                                                        <?php
                                                        foreach ($positions as $position) {
                                                            if ($member['position'] == $position['id']) {
                                                                echo "<p style='display:none;'>" . htmlspecialchars($position['position'], ENT_QUOTES, 'UTF-8') . "</p>";
                                                            }
                                                        }
                                                        foreach ($specialities as $speciality) {
                                                            if ($member['specialty_id'] == $speciality['id']) {
                                                                echo "<p style='display:none;'>" . htmlspecialchars($speciality['specilaity'], ENT_QUOTES, 'UTF-8') . "</p>";
                                                            }
                                                        }
                                                        ?>
                                                    </td>
                                                    <td class='eachcol member_name'><p><?= htmlspecialchars($member['member_name'], ENT_QUOTES, 'UTF-8') ?></p></td>
                                                    <td class='eachcol member_email'>
                                                        <p><?= htmlspecialchars($member['member_email'], ENT_QUOTES, 'UTF-8') ?></p>
                                                        <?php if (in_array($user['position'], $access_PICU_control)): ?>
                                                            <a href="send-reset-pass-by-admin.php?email=<?= rawurlencode($member['member_email']) ?>">Send Reset Password Email</a>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class='eachcol position'>
                                                        <select class='txtdata position form-control' name='position'>
                                                            <?php
                                                            foreach ($positions as $position) {
                                                                $selected = ($member['position'] == $position['id']) ? 'selected' : '';
                                                                echo "<option value='" . htmlspecialchars($position['id'], ENT_QUOTES, 'UTF-8') . "' {$selected}>" . htmlspecialchars($position['position'], ENT_QUOTES, 'UTF-8') . "</option>";
                                                            }
                                                            ?>
                                                            <option value="0" <?= ($member['position'] == '0') ? 'selected' : '' ?>>Administrator</option>
                                                        </select>
                                                    </td>
                                                    <td class='eachcol speciality'>
                                                        <?php if ($member['position'] == '3'): ?>
                                                            <select class='txtdata speciality form-control' name='speciality'>
                                                                <?php
                                                                foreach ($specialities as $speciality) {
                                                                    $selected = ($member['specialty_id'] == $speciality['id']) ? 'selected' : '';
                                                                    echo "<option value='" . htmlspecialchars($speciality['id'], ENT_QUOTES, 'UTF-8') . "' {$selected}>" . htmlspecialchars($speciality['specilaity'], ENT_QUOTES, 'UTF-8') . "</option>";
                                                                }
                                                                ?>
                                                            </select>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class='eachcol activate'>
                                                        <?php
                                                           if ($member['active'] == 1){
                                                                $stmt = $mysqli->prepare("SELECT * FROM picupatients WHERE DISDATE IS NULL AND consultant_id=? ");
                                                                $stmt->bind_param("i", $member['member_id']);
                                                                $stmt->execute();
                                                                $result1 = $stmt->get_result();
                                                                $pcount = mysqli_num_rows($result1);
                                                                if ($pcount !== 0 || $member['on_service'] == 1 || $member['assign_access'] == 1 || $member['add_new_patient'] == 1 || $member['manage_patient'] == 1 || $member['modify_patient'] == 1) {
                                                                    echo "<input style='width: auto;' type='checkbox' name='activate' value='1' checked disabled>";
                                                                } else {
                                                                    echo "<input style='width: auto;' class='txtdata' type='checkbox' name='activate' value='1' checked>";
                                                                }
                                                           }else{
                                                                echo "<input style='width: auto;' class='txtdata' type='checkbox' name='activate'>";

                                                           }
                                                        ?>
                                                            <label for='activate' style='display: contents;'> Active</label>
                                                    </td>
                                                    <td class='eachcol service'>
                                                        <input style='width: auto;' class='txtdata' type='checkbox' name='service' value='1' <?= ($member['on_service'] == 1) ? 'checked' : '' ?>>
                                                        <label for='service' style='display: contents;'> On Service</label>
                                                    </td>
                                                    <td class='eachcol assignaccess'>
                                                        <input style='width: auto;' class='txtdata' type='checkbox' name='assignaccess' value='1' <?= ($member['assign_access'] == 1) ? 'checked' : '' ?>>
                                                        <label for='assignaccess' style='display: contents;'> Allowed</label>
                                                    </td>
                                                    <td class='eachcol addpatient'>
                                                        <input style='width: auto;' class='txtdata' type='checkbox' name='addpatient' value='1' <?= ($member['add_new_patient'] == 1) ? 'checked' : '' ?>>
                                                        <label for='addpatient' style='display: contents;'> Allowed</label>
                                                    </td>
                                                    <td class='eachcol managepatient'>
                                                        <input style='width: auto;' class='txtdata' type='checkbox' name='managepatient' value='1' <?= ($member['manage_patient'] == 1) ? 'checked' : '' ?>>
                                                        <label for='managepatient' style='display: contents;'> Allowed</label>
                                                    </td>
                                                    <td class='eachcol modifypatient'>
                                                        <input style='width: auto;' class='txtdata' type='checkbox' name='modifypatient' value='1' <?= ($member['modify_patient'] == 1) ? 'checked' : '' ?>>
                                                        <label for='modifypatient' style='display: contents;'> Allowed</label>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <p>* You can only deactivate if all permissions are removed and there are no active patients under the account.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Manage Inactive Users -->
                <div id="mypresentersTable" class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-user-tie text-info"></i> Manage Inactive Users</h3>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="myTable" class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th onclick="sortTable(0)" scope="col">Full Name <i class="fa fa-sort" aria-hidden="true"></i></th>
                                            <th scope="col">Login Name</th>
                                            <th scope="col">Email</th>
                                            <th scope="col">Position</th>
                                            <th scope="col">Speciality</th>
                                            <th scope="col">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="searchtable">
                                        <?php
                                        foreach ($members as $member):
                                            if ($member['active'] != '1'):
                                                ?>
                                                <tr class='eachrow' id='row<?= htmlspecialchars($member['member_id'], ENT_QUOTES, 'UTF-8') ?>'>
                                                    <td class='eachcol full_name'>
                                                        <input class='txtdata' name='full_name' value='<?= htmlspecialchars($member['full_name'], ENT_QUOTES, 'UTF-8') ?>' style='text-align: center;'>
                                                        <input type='hidden' name='member_id' value='<?= htmlspecialchars($member['member_id'], ENT_QUOTES, 'UTF-8') ?>'>
                                                        <p style='display:none;'><?= htmlspecialchars($member['full_name'], ENT_QUOTES, 'UTF-8') ?></p>
                                                        <?php
                                                        foreach ($positions as $position) {
                                                            if ($member['position'] == $position['id']) {
                                                                echo "<p style='display:none;'>" . htmlspecialchars($position['position'], ENT_QUOTES, 'UTF-8') . "</p>";
                                                            }
                                                        }
                                                        foreach ($specialities as $speciality) {
                                                            if ($member['specialty_id'] == $speciality['id']) {
                                                                echo "<p style='display:none;'>" . htmlspecialchars($speciality['specilaity'], ENT_QUOTES, 'UTF-8') . "</p>";
                                                            }
                                                        }
                                                        ?>
                                                    </td>
                                                    <td class='eachcol member_name'><p><?= htmlspecialchars($member['member_name'], ENT_QUOTES, 'UTF-8') ?></p></td>
                                                    <td class='eachcol member_email'>
                                                        <p><?= htmlspecialchars($member['member_email'], ENT_QUOTES, 'UTF-8') ?></p>
                                                        <?php if (in_array($user['position'], $access_PICU_control)): ?>
                                                            <a href="send-reset-pass-by-admin.php?email=<?= rawurlencode($member['member_email']) ?>">Send Reset Password Email</a>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class='eachcol position'>
                                                        <select class='txtdata position form-control' name='position'>
                                                            <?php
                                                            foreach ($positions as $position) {
                                                                $selected = ($member['position'] == $position['id']) ? 'selected' : '';
                                                                echo "<option value='" . htmlspecialchars($position['id'], ENT_QUOTES, 'UTF-8') . "' {$selected}>" . htmlspecialchars($position['position'], ENT_QUOTES, 'UTF-8') . "</option>";
                                                            }
                                                            ?>
                                                            <option value="0" <?= ($member['position'] == '0') ? 'selected' : '' ?>>Administrator</option>
                                                        </select>
                                                    </td>
                                                    <td class='eachcol speciality'>
                                                        <?php if ($member['position'] == '3'): ?>
                                                            <select class='txtdata speciality form-control' name='speciality'>
                                                                <?php
                                                                foreach ($specialities as $speciality) {
                                                                    $selected = ($member['specialty_id'] == $speciality['id']) ? 'selected' : '';
                                                                    echo "<option value='" . htmlspecialchars($speciality['id'], ENT_QUOTES, 'UTF-8') . "' {$selected}>" . htmlspecialchars($speciality['specilaity'], ENT_QUOTES, 'UTF-8') . "</option>";
                                                                }
                                                                ?>
                                                            </select>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class='eachcol activate'>
                                                        <input style='width: auto;' class='txtdata' type='checkbox' name='activate' value='1' <?= ($member['active'] == 1) ? 'checked' : '' ?>>
                                                        <label for='activate' style='display: contents;'> Active</label>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Include Footer -->
<?php require 'footer.php'; ?>

<script>
function sortTable(n) {
    var table, rows, switching, i, x, y, shouldSwitch, dir, switchcount = 0;
    table = document.getElementById("myTable");
    switching = true;
    dir = "asc";
    while (switching) {
        switching = false;
        rows = table.getElementsByTagName("TR");
        for (i = 1; i < rows.length - 1; i++) {
            shouldSwitch = false;
            x = rows[i].getElementsByTagName("TD")[n];
            y = rows[i + 1].getElementsByTagName("TD")[n];
            if (dir == "asc") {
                if (x.innerHTML.toLowerCase() > y.innerHTML.toLowerCase()) {
                    shouldSwitch = true;
                    break;
                }
            } else if (dir == "desc") {
                if (x.innerHTML.toLowerCase() < y.innerHTML.toLowerCase()) {
                    shouldSwitch = true;
                    break;
                }
            }
        }
        if (shouldSwitch) {
            rows[i].parentNode.insertBefore(rows[i + 1], rows[i]);
            switching = true;
            switchcount++;
        } else {
            if (switchcount == 0 && dir == "asc") {
                dir = "desc";
                switching = true;
            }
        }
    }
}

function loading(button) {
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

function del(value) {
    if (!confirm("Do you really want to delete this user?")) {
        return false;
    }
    var rowname = "row" + value;
    var row = document.getElementById(rowname);
    var id = value;
    var data = {id: id};
    $.post('dmc-users-delete.php', data, function() {
        row.style.display = "none";
    });
}

$(document).ready(function() {
    $('.txtdata').on('change', function() {
        var parent = $(this).closest('.eachrow');
        var id = parent.find('input[name="member_id"]').val();
        var full_name = parent.find('input[name="full_name"]').val();
        var speciality = parent.find('select[name="speciality"]').val();
        var position = parent.find('select[name="position"]').val();
        var activate = parent.find('input[name="activate"]').prop('checked') ? 1 : 0;
        var service = parent.find('input[name="service"]').prop('checked') ? 1 : 0;
        var accessbox = parent.find('input[name="assignaccess"]').prop('checked') ? 1 : 0;
        var addbox = parent.find('input[name="addpatient"]').prop('checked') ? 1 : 0;
        var managebox = parent.find('input[name="managepatient"]').prop('checked') ? 1 : 0;
        var modifybox = parent.find('input[name="modifypatient"]').prop('checked') ? 1 : 0;

        var data = {
            id: id, position: position, speciality: speciality, activate: activate,
            full_name: full_name, service: service, accessbox: accessbox,
            managebox: managebox, modifybox: modifybox, addbox: addbox
        };


        $.post('dmc-users-update.php', data, function() {
            $(this).closest('.eachcol').css("backgroundColor", "#90EE90");
        }.bind(this));
    });


    $("#search").on("keyup", function() {
        var value = $(this).val().toLowerCase();
        $("#searchtable tr").filter(function() {
            $(this).toggle($(this).find('td:eq(0), td:eq(1), td:eq(2)').text().toLowerCase().indexOf(value) > -1);
        });
    });
});

</script>