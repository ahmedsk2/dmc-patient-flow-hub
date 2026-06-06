<?php
require_once 'sidebar.php';

if (!in_array($user['position'], $access_PICU_patients)) {
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

function fetch_all_data($mysqli, $query) {
    $result = $mysqli->query($query);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

$activepicupatints = fetch_all_data($mysqli, "SELECT * FROM picupatients WHERE DISDATE IS NULL");
$consultants = fetch_all_data($mysqli, "SELECT member_id, full_name FROM members WHERE position = '3'");
$settings = fetch_all_data($mysqli, "SELECT short_los, long_los FROM settings")[0];

$shortlos = $settings['short_los'];
$longlos = $settings['long_los'];

$tb_list = array_column(fetch_all_data($mysqli, "SELECT dx_id FROM tb_list"), 'dx_id');

// Pre-fetch all ICD-10 codes and names
$icd10_result = $mysqli->query("SELECT id, name FROM icd10");
$icd10_names = [];
while ($row = $icd10_result->fetch_assoc()) {
    $icd10_names[$row['id']] = $row['name'];
}

$tb_patient = [];
foreach ($activepicupatints as $patient) {
    $decodedadmissiondx = json_decode($patient['admissiondiagnosis'], true);
    if ($decodedadmissiondx && array_intersect($decodedadmissiondx, $tb_list)) {
        $tb_patient[$patient['ID']] = $patient;
    }
}
?>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark">Active TB Patients</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item active">Active TB Patients</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid">
        <div class="row">
            <div id="mypresentersTable" class="col-md-12">
                <?php foreach ($consultants as $consultant):
                    $consultant_tb_patients = array_filter($tb_patient, function ($patient) use ($consultant) {
                        return $patient['consultant_id'] == $consultant['member_id'];
                    });

                    if (empty($consultant_tb_patients)) continue;
                ?>
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-user-tie text-info"></i> Dr. <?= htmlspecialchars($consultant['full_name']) ?> Consultations List</h3>
                        </div>
                        <div class="card-body">
                            <div class="row table-responsive">
                                <table class="col-md-12">
                                    <thead style="text-align: center; font-weight: 700;">
                                        <tr>
                                            <td class="col-md-1">MRN</td>
                                            <td class="col-md-3">Patient Name</td>
                                            <td class="col-md-1">Admission Date</td>
                                            <td class="col-md-1">LOS</td>
                                            <td class="col-md-6">Diagnosis</td>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($consultant_tb_patients as $patient):
                                        $decodedadmissiondx = json_decode($patient['admissiondiagnosis'], true);
                                        $LOS = (strtotime(date("Y-m-d")) - strtotime($patient['ADMDATE'])) / 86400;
                                        $losClass = ($LOS < $shortlos) ? 'bg-success' : (($LOS > $longlos) ? 'bg-danger' : 'bg-warning');
                                    ?>
                                        <tr class='eachrow' id='row<?= $patient['ID'] ?>'>
                                            <td class='eachcol mrn' style='padding: 0px 1%; text-align: center'><p><?= htmlspecialchars($patient['MRN']) ?></p></td>
                                            <td class='eachcol name' style='padding: 0px 1%; text-align: center'><p><?= htmlspecialchars($patient['PNAME']) ?></p></td>
                                            <td class='eachcol admdate' style='padding: 0px 1%; text-align: center'><p><?= htmlspecialchars($patient['ADMDATE']) ?></p></td>
                                            <td class='eachcol admdate <?= $losClass ?>' style='padding: 0px 1%; text-align: center'><p><?= round($LOS) ?></p></td>
                                            <td class='eachcol admissiondiagnosis' style='padding: 0px 1%;'>
                                                <ul style='list-style-position: inside; margin: 1% 0%;'>
                                                    <?php if (is_array($decodedadmissiondx)):
                                                        foreach ($decodedadmissiondx as $value):
                                                            if (isset($icd10_names[$value])):
                                                                echo '<li>' . htmlspecialchars($icd10_names[$value]) . '</li>';
                                                            endif;
                                                        endforeach;
                                                    endif; ?>
                                                </ul>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php require 'footer.php'; ?>
