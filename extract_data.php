<?php
require_once 'sidebar.php';

if (!in_array($user['position'], $access_PICU_control)) {
    echo "<div class='content-wrapper'>
        <section class='content'>
        <div class='container-fluid'>  
        <div class='alert alert-danger' role='alert'>You don't have permission to access this page. Contact your manager if you need to.</div>
        </div>
        </section>
        </div>";
    require 'footer.php';
    exit();
}

?>


<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark">Extract ICU / Non-ICU Patient Data</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item active">Extract Data</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-file-export text-info"></i> Download Excel Report</h3>
                </div>
                <div class="card-body">
                  <form method="POST" action="export_patients.php" onsubmit="return showLoader();">
                          <div class="row">
                            <div class="col-md-4">
                                <label>Select Year:</label>
                                <select name="selected_year" class="form-control" required>
                                    <option value="">-- Select Year --</option>
                                    <?php
                                    $formationSQL = "SELECT DISTINCT YEAR(ADMDATE) AS y FROM picupatients ORDER BY y DESC";
                                    $result1 = $mysqli->query($formationSQL);
                                    while ($row = $result1->fetch_assoc()) {
                                        echo "<option value='" . $row['y'] . "'>" . $row['y'] . "</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label>Patient Type:</label>
                                <select name="patient_type" class="form-control" required>
                                    <option value="">-- Select Type --</option>
                                    <option value="ICU">ICU Patients</option>
                                    <option value="Non-ICU">Non-ICU Patients</option>
                                </select>
                            </div>
                            <div class="col-md-4 align-self-end">
                                <button type="submit" class="btn btn-success">Download Excel</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>
</div>


<?php require 'footer.php'; ?>
