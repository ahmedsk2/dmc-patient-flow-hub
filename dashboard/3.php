<?php
require_once __DIR__ . '/../guard.php'; require_login();

require_once '../dbconnect.php';

function getTopDiagnoses($mysqli, $weekNumber) {
    // PERF: the original "INNER JOIN icd10 ON JSON_CONTAINS(admissiondiagnosis, JSON_QUOTE(d.id))"
    // is an unindexable cross-join (~14.8k patients x ~72.7k ICD-10 rows = ~1B evaluations -> ~70s).
    // Aggregate this week's diagnosis ids in PHP, then resolve names for just the top 5.
    // Same result (top 5 diagnoses by volume for the ISO week), but effectively instant.
    $counts = [];
    if ($stmt = $mysqli->prepare("SELECT admissiondiagnosis FROM picupatients WHERE WEEK(ADMDATE) = ?")) {
        $stmt->bind_param("i", $weekNumber);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $dx = json_decode($row['admissiondiagnosis'] ?? '', true);
            if (is_array($dx)) {
                foreach ($dx as $id) {
                    $id = trim((string) $id);
                    if ($id !== '') { $counts[$id] = ($counts[$id] ?? 0) + 1; }
                }
            }
        }
        $stmt->close();
    }
    if (!$counts) { return []; }
    arsort($counts);
    $topIds = array_slice(array_keys($counts), 0, 5);
    $placeholders = implode(',', array_fill(0, count($topIds), '?'));
    $names = [];
    if ($stmt = $mysqli->prepare("SELECT id, name FROM icd10 WHERE id IN ($placeholders)")) {
        $stmt->bind_param(str_repeat('s', count($topIds)), ...$topIds);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) { $names[trim((string) $row['id'])] = $row['name']; }
        $stmt->close();
    }
    $out = [];
    foreach ($topIds as $id) {
        $out[] = ['name' => $names[$id] ?? $id, 'count' => $counts[$id]];
    }
    return $out;
}

function getStats($mysqli, $currentMonth, $currentYear) {
$stmt = $mysqli->prepare("
    SELECT 
        AVG(CASE WHEN DISDATE IS NOT NULL AND MONTH(DISDATE) = ? AND YEAR(DISDATE) = ? AND (current_location != 'ICU' OR current_location IS NULL) THEN DATEDIFF(DISDATE, ADMDATE) END) as average_los,
        SUM(CASE WHEN YEAR(ADMDATE) = ? AND (current_location != 'ICU' OR current_location IS NULL) THEN 1 ELSE 0 END) AS admission_count,
        SUM(CASE WHEN YEAR(DISDATE) = ? AND (current_location != 'ICU' OR current_location IS NULL) THEN 1 ELSE 0 END) AS discharge_count
    FROM picupatients
    WHERE (DISDATE IS NOT NULL AND MONTH(DISDATE) = ? AND YEAR(DISDATE) = ?) OR (YEAR(ADMDATE) = ? OR YEAR(DISDATE) = ?)
");

$stmt->bind_param("iiiiiiii", $currentMonth, $currentYear, $currentYear, $currentYear, $currentMonth, $currentYear, $currentYear, $currentYear);
$stmt->execute();
$result = $stmt->get_result();
    return $result->fetch_assoc();
}

function getConsultations($mysqli, $currentYear) {
    $stmt = $mysqli->prepare("
        SELECT 
            COUNT(*) AS total_consultations,
            COUNT(CASE WHEN YEAR(signoff_date) = ? THEN 1 ELSE NULL END) AS total_signoffs
        FROM consultations
        WHERE YEAR(consultation_date) = ?
    ");
    $stmt->bind_param("ii", $currentYear, $currentYear);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

function getConsultantName($mysqli, $consultant_id) {
    $stmt = $mysqli->prepare("SELECT full_name FROM members WHERE member_id = ?");
    $stmt->bind_param("i", $consultant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    return $data['full_name'];
}

function fetchSettings($mysqli) {
    $result = $mysqli->query("SELECT * FROM settings");
    return $result->fetch_assoc();
}

function fetchTuberculosisDiagnosisIDs($mysqli) {
    $result = $mysqli->query("SELECT dx_id FROM tb_list");
    return array_column($result->fetch_all(MYSQLI_ASSOC), 'dx_id');
}

function fetchActivePatients($mysqli) {
    $result = $mysqli->query("SELECT consultant_id, current_location, admissiondiagnosis, newassign, med_DISDATE, longterm FROM picupatients WHERE DISDATE IS NULL");
    return $result->fetch_all(MYSQLI_ASSOC);
}

function fetchConsultants($mysqli) {
    $result = $mysqli->query("SELECT member_id, active, specialty_id, on_service FROM members WHERE position = '3'");
    return $result->fetch_all(MYSQLI_ASSOC);
}

$weekNumber = date("W");
$dx_name_count = getTopDiagnoses($mysqli, $weekNumber);

$currentMonth = date("m");
$currentYear = date("Y");

$stats = getStats($mysqli, $currentMonth, $currentYear);
$consultations = getConsultations($mysqli, $currentYear);

$settings = fetchSettings($mysqli);
$tuber_list = fetchTuberculosisDiagnosisIDs($mysqli);
$activepicupatients = fetchActivePatients($mysqli);
$consultants = fetchConsultants($mysqli);

// Initialize consultant count
$consultant_count = [];
foreach ($consultants as $consultant) {
    $consultant_count[$consultant['member_id']] = [
        'new' => 0, 'old' => 0, 'icu' => 0, 'ward' => 0,
        'tb1' => 0, 'activepatients' => 0,
        'active' => $consultant['active'],
        'specialty_id' => $consultant['specialty_id'],
        'on_service' => $consultant['on_service']
    ];
}

$active_ICU_count = 0;
$all_activepatients = 0;
foreach ($activepicupatients as $patient) {
    $consultant_id = $patient['consultant_id'];
    if (isset($consultant_count[$consultant_id])) {
        $decodedadmissiondx = json_decode($patient['admissiondiagnosis'], true);
        $is_tb_patient = false;
        foreach ($decodedadmissiondx as $dx) {
            if (in_array($dx, $tuber_list)) {
                $is_tb_patient = true;
                $consultant_count[$consultant_id]['tb1']++;
                break;
            }
        }

        if (is_null($patient['newassign'])) {
            $consultant_count[$consultant_id]['old']++;
        } elseif ($patient['newassign'] == '1') {
            $consultant_count[$consultant_id]['new']++;
        }

        if ($patient['current_location'] == 'ICU') {
            $consultant_count[$consultant_id]['icu']++;
            $active_ICU_count++;
        } else {
            $consultant_count[$consultant_id]['ward']++;
            $all_activepatients++;
        }

        if ($patient['current_location'] != 'ICU' && !$patient['med_DISDATE'] && !$patient['longterm'] && !$is_tb_patient) {
            $consultant_count[$consultant_id]['activepatients']++;
        }
    }
}

// Calculate hospitalist capacity utilization
$hospitalist_n = 0;
foreach ($consultant_count as $cc) {
    if ($cc['on_service'] == '1' && $cc['specialty_id'] == '1' && $cc['active'] == '1') {
        $hospitalist_n++;
    }
}
$capacity = $hospitalist_n * $settings['max_hospitalist'];
$capacityUtilization = number_format((($all_activepatients / $capacity) * 100), 2, '.', '');
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="nav-icon fas fa-tachometer-alt text-info"></i> <?= date("Y"); ?> Overview</h3>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-sm-6">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">This Week Top 5 Diagnosis</th>
                            <th scope="col">Volume</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dx_name_count as $index => $row): ?>
                            <tr>
                                <th scope='row'><?= ($index + 1); ?></th>
                                <td><?= htmlspecialchars($row['name']); ?></td>
                                <td><?= htmlspecialchars($row['count']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="col-sm-6">
                <div class="table-responsive text-nowrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th scope="col" colspan="2">Average Length of Stay for <?= date("M"); ?></th>
                                <th scope="col" colspan="2"><?= number_format((float)$stats['average_los'], 2, '.', ''); ?> Days</th>
                            </tr>
                            <tr>
                                <th scope="col">Total Admissions for <?= date("Y"); ?></th>
                                <th scope="col"><?= $stats['admission_count']; ?> Patients</th>
                                <th scope="col">Total Discharges</th>
                                <th scope="col"><?= $stats['discharge_count']; ?> Patients</th>
                            </tr>
                            <tr>
                                <th scope="col">Total Consultations for <?= date("Y"); ?></th>
                                <th scope="col"><?= $consultations['total_consultations']; ?> Patients</th>
                                <th scope="col">Total Sign Offs</th>
                                <th scope="col"><?= $consultations['total_signoffs']; ?> Patients</th>
                            </tr>
                            <tr>
                                <th scope="col">Currently in ICU</th>
                                <th scope="col"><?= $active_ICU_count; ?> Patients</th>
                                <th scope="col"></th>
                                <th scope="col"></th>
                            </tr>
                            <tr>
                                <th scope="col" colspan="2">Hospitalist capacity utilization for today</th>
                                <th scope="col" colspan="2"><?= $capacityUtilization; ?> %</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-user-tie text-info"></i> Patient Count per Consultant</h3>
        <div id="addbtn" class='eachrow' style='float: right;'>
            <h3 class="card-title"><i class="fas fa-user-tie text-info"></i> Total Count (non ICU): <?= $all_activepatients; ?></h3>
        </div>
    </div>
    <div class="card-body">
        <div class="row table-responsive">
            <table class="col-md-12" style="text-align: center;">
                <thead style="text-align: center;font-weight: 700;">
                    <tr>
                        <td class="col-md-4">Consultant</td>
                        <td class="col-md-1">Old Patients</td>
                        <td class="col-md-1">New Patients</td>
                        <td class="col-md-6" colspan="4">All Patients</td>
                    </tr>
                    <tr>
                        <td class="col-md-4"></td>
                        <td class="col-md-1"></td>
                        <td class="col-md-1"></td>
                        <td class="col-md-2">Active Patients</td>
                        <td class="col-md-2">Total Ward Patients</td>
                        <td class="col-md-1">ICU Patients</td>
                        <td class="col-md-1">TB Patients</td>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($consultant_count as $key => $cc): ?>
                        <?php if ($cc['on_service'] == '1' && $cc['specialty_id'] == '1' && $cc['active'] == '1'): ?>
                            <tr class='eachrow'>
                                <td><?= htmlspecialchars(getConsultantName($mysqli, $key)); ?></td>
                                <td><?= htmlspecialchars($cc['old']); ?></td>
                                <td><?= htmlspecialchars($cc['new']); ?></td>
                                <td><?= htmlspecialchars($cc['activepatients']); ?></td>
                                <td><?= htmlspecialchars($cc['ward']); ?></td>
                                <td><?= htmlspecialchars($cc['icu']); ?></td>
                                <td><?= htmlspecialchars($cc['tb1']); ?></td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php foreach ($consultant_count as $key => $cc): ?>
                        <?php if ($cc['on_service'] == '1' && $cc['specialty_id'] !== '1' && $cc['active'] == '1'): ?>
                            <tr class='eachrow'>
                                <td><?= htmlspecialchars(getConsultantName($mysqli, $key)); ?></td>
                                <td><?= htmlspecialchars($cc['old']); ?></td>
                                <td><?= htmlspecialchars($cc['new']); ?></td>
                                <td><?= htmlspecialchars($cc['activepatients']); ?></td>
                                <td><?= htmlspecialchars($cc['ward']); ?></td>
                                <td><?= htmlspecialchars($cc['icu']); ?></td>
                                <td><?= htmlspecialchars($cc['tb1']); ?></td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <tr class='eachrow'>
                        <td colspan='7'><p style='color: red;font-weight: bold;'> OFF Service: </p></td>
                    </tr>
                    <?php foreach ($consultant_count as $key => $cc): ?>
                        <?php if ($cc['on_service'] == '0' && $cc['active'] == '1'): ?>
                            <tr class='eachrow'>
                                <td><?= htmlspecialchars(getConsultantName($mysqli, $key)); ?></td>
                                <td><?= htmlspecialchars($cc['old']); ?></td>
                                <td><?= htmlspecialchars($cc['new']); ?></td>
                                <td><?= htmlspecialchars($cc['activepatients']); ?></td>
                                <td><?= htmlspecialchars($cc['ward']); ?></td>
                                <td><?= htmlspecialchars($cc['icu']); ?></td>
                                <td><?= htmlspecialchars($cc['tb1']); ?></td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
